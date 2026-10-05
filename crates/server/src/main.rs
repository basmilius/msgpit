use anyhow::{Context, Result};
use msgpit_server::{
    api::{self, AppState},
    providers::{CallbackConfig, Provider, spryng::Spryng},
    smtp,
    store::Store,
};
use std::{
    env,
    net::{IpAddr, Ipv4Addr, SocketAddr},
    sync::Arc,
    time::Duration,
};
use tokio::{
    io::{AsyncBufReadExt, AsyncWriteExt, BufReader},
    net::{TcpListener, TcpStream},
};
use tokio_util::sync::CancellationToken;

fn setting(name: &str, default: &str) -> String {
    env::var(name).unwrap_or_else(|_| default.into())
}

fn loopback(mut addr: SocketAddr) -> SocketAddr {
    if addr.ip().is_unspecified() {
        addr.set_ip(IpAddr::V4(Ipv4Addr::LOCALHOST));
    }
    addr
}

async fn healthcheck(http: SocketAddr, smtp: SocketAddr) -> Result<()> {
    tokio::time::timeout(Duration::from_secs(3), async {
        let mut http = TcpStream::connect(loopback(http)).await?;
        http.write_all(b"GET /healthz HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")
            .await?;
        let mut status = String::new();
        BufReader::new(http).read_line(&mut status).await?;
        anyhow::ensure!(
            status.starts_with("HTTP/1.1 200"),
            "HTTP healthcheck failed"
        );
        let mut greeting = String::new();
        BufReader::new(TcpStream::connect(loopback(smtp)).await?)
            .read_line(&mut greeting)
            .await?;
        anyhow::ensure!(greeting.starts_with("220 "), "SMTP healthcheck failed");
        Ok(())
    })
    .await
    .context("Healthcheck timed out")?
}

#[tokio::main]
async fn main() -> Result<()> {
    let http: SocketAddr = setting("MSGPIT_HTTP_ADDR", "0.0.0.0:8080").parse()?;
    let smtp_addr: SocketAddr = setting("MSGPIT_SMTP_ADDR", "0.0.0.0:1025").parse()?;
    if env::args().nth(1).as_deref() == Some("healthcheck") {
        return healthcheck(http, smtp_addr).await;
    }
    tracing_subscriber::fmt()
        .with_env_filter(setting("RUST_LOG", "msgpit_server=info,tower_http=info"))
        .init();
    let store = Store::open(
        &setting("MSGPIT_DB", "/data/msgpit-rust.sqlite"),
        setting("MSGPIT_MAX_MESSAGES", "1000").parse()?,
    )?;
    let enabled = setting("MSGPIT_PROVIDERS", "spryng");
    let mut providers: Vec<Box<dyn Provider>> = vec![];
    for id in enabled.split(',').map(str::trim).filter(|s| !s.is_empty()) {
        match id {
            "spryng" => providers.push(Box::new(Spryng::new(CallbackConfig {
                url: env::var("MSGPIT_SPRYNG_DLR_URL")
                    .ok()
                    .filter(|s| !s.is_empty()),
                header: env::var("MSGPIT_SPRYNG_DLR_HEADER")
                    .ok()
                    .filter(|s| !s.is_empty())
                    .zip(
                        env::var("MSGPIT_SPRYNG_DLR_SECRET")
                            .ok()
                            .filter(|s| !s.is_empty()),
                    ),
            }))),
            _ => anyhow::bail!("Unknown provider: {id}"),
        }
    }
    let http_listener = TcpListener::bind(http)
        .await
        .context("Could not bind HTTP")?;
    let smtp_listener = TcpListener::bind(smtp_addr)
        .await
        .context("Could not bind SMTP")?;
    let shutdown = CancellationToken::new();
    let state = AppState {
        store: store.clone(),
        network: msgpit_server::network::Network::from_env()?,
        providers: Arc::new(providers),
        smtp_port: smtp_listener.local_addr()?.port(),
        shutdown: shutdown.clone(),
    };
    let app = api::router(state, &setting("MSGPIT_WEB_DIR", "web/dist"));
    tracing::info!(http = %http_listener.local_addr()?, smtp = %smtp_listener.local_addr()?, "Msgpit ready");
    let signal_shutdown = shutdown.clone();
    tokio::spawn(async move {
        #[cfg(unix)]
        {
            let mut terminate =
                tokio::signal::unix::signal(tokio::signal::unix::SignalKind::terminate())
                    .expect("SIGTERM handler");
            tokio::select! { _ = tokio::signal::ctrl_c() => {}, _ = terminate.recv() => {} }
        }
        #[cfg(not(unix))]
        let _ = tokio::signal::ctrl_c().await;
        signal_shutdown.cancel();
    });
    let http_shutdown = shutdown.clone();
    let mut http_task = tokio::spawn(async move {
        axum::serve(http_listener, app)
            .with_graceful_shutdown(http_shutdown.cancelled_owned())
            .await
    });
    let mut smtp_task = tokio::spawn(smtp::serve(smtp_listener, store, shutdown.clone()));
    tokio::select! {
        result = &mut http_task => { shutdown.cancel(); result??; smtp_task.await??; }
        result = &mut smtp_task => { shutdown.cancel(); result??; http_task.await??; }
    }
    Ok(())
}
