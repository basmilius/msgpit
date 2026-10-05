use crate::{api::MAX_BYTES, mail, store::Store};
use anyhow::{Result, bail};
use std::{sync::Arc, time::Duration};
use tokio::{
    io::{AsyncBufReadExt, AsyncReadExt, AsyncWriteExt, BufReader},
    net::{TcpListener, TcpStream},
    sync::Semaphore,
    task::JoinSet,
};
use tokio_util::sync::CancellationToken;

pub async fn serve(listener: TcpListener, store: Store, shutdown: CancellationToken) -> Result<()> {
    let permits = Arc::new(Semaphore::new(128));
    let mut sessions = JoinSet::new();
    loop {
        tokio::select! {
            _ = shutdown.cancelled() => break,
            Some(result) = sessions.join_next(), if !sessions.is_empty() => {
                if let Err(error) = result { tracing::warn!(%error, "SMTP task failed"); }
            }
            accepted = listener.accept() => {
                let (socket, _) = accepted?;
                let Ok(permit) = permits.clone().try_acquire_owned() else { drop(socket); continue; };
                let store = store.clone();
                let shutdown = shutdown.clone();
                sessions.spawn(async move {
                    let _permit = permit;
                    tokio::select! {
                        _ = shutdown.cancelled() => {}
                        result = session(socket, store) => {
                            if let Err(error) = result { tracing::debug!(%error, "SMTP session ended"); }
                        }
                    }
                });
            }
        }
    }
    while sessions.join_next().await.is_some() {}
    Ok(())
}

async fn line(reader: &mut BufReader<TcpStream>, limit: usize) -> Result<Option<Vec<u8>>> {
    let mut bytes = Vec::new();
    let read = tokio::time::timeout(
        Duration::from_secs(60),
        reader
            .take((limit + 1) as u64)
            .read_until(b'\n', &mut bytes),
    )
    .await??;
    if read == 0 {
        return Ok(None);
    }
    if read > limit || !bytes.ends_with(b"\n") {
        bail!("SMTP line is too long or incomplete");
    }
    Ok(Some(bytes))
}

async fn reply(reader: &mut BufReader<TcpStream>, response: &str) -> Result<()> {
    reader.get_mut().write_all(response.as_bytes()).await?;
    Ok(())
}

fn address<'a>(argument: &'a str, prefix: &str) -> Option<&'a str> {
    let (key, value) = argument.split_once(':')?;
    if !key.eq_ignore_ascii_case(prefix) {
        return None;
    }
    let value = value.trim().strip_prefix('<')?;
    let (address, _) = value.split_once('>')?;
    Some(address)
}

async fn session(socket: TcpStream, store: Store) -> Result<()> {
    let mut reader = BufReader::new(socket);
    let hostname = std::env::var("MSGPIT_SMTP_HOSTNAME")
        .ok()
        .filter(|s| {
            !s.is_empty()
                && s.chars()
                    .all(|c| c.is_ascii_alphanumeric() || matches!(c, '.' | '-'))
        })
        .unwrap_or_else(|| "msgpit".into());
    reply(&mut reader, &format!("220 {hostname} ESMTP ready\r\n")).await?;
    let mut greeted = false;
    let mut sender: Option<String> = None;
    let mut recipients: Vec<String> = vec![];
    while let Some(bytes) = line(&mut reader, 512).await? {
        let command = std::str::from_utf8(&bytes)?.trim_end_matches(['\r', '\n']);
        let (verb, argument) = command.split_once(' ').unwrap_or((command, ""));
        match verb.to_ascii_uppercase().as_str() {
            "EHLO" | "HELO" if !argument.is_empty() => {
                greeted = true;
                sender = None;
                recipients.clear();
                reply(
                    &mut reader,
                    &format!("250-{hostname}\r\n250-SIZE {MAX_BYTES}\r\n250 8BITMIME\r\n"),
                )
                .await?;
            }
            "MAIL" if greeted => {
                if let Some(from) = address(argument, "FROM") {
                    sender = Some(from.into());
                    recipients.clear();
                    reply(&mut reader, "250 Sender accepted\r\n").await?;
                } else {
                    reply(&mut reader, "501 Expected MAIL FROM:<address>\r\n").await?;
                }
            }
            "RCPT" if sender.is_some() => {
                if let Some(to) = address(argument, "TO").filter(|s| !s.is_empty()) {
                    if recipients.len() >= 100 {
                        reply(&mut reader, "452 Too many recipients\r\n").await?;
                    } else {
                        recipients.push(to.into());
                        reply(&mut reader, "250 Recipient accepted\r\n").await?;
                    }
                } else {
                    reply(&mut reader, "501 Expected RCPT TO:<address>\r\n").await?;
                }
            }
            "DATA" if sender.is_some() && !recipients.is_empty() => {
                reply(&mut reader, "354 End data with <CRLF>.<CRLF>\r\n").await?;
                let mut raw = Vec::new();
                let mut too_large = false;
                loop {
                    let Some(mut bytes) = line(&mut reader, 128 * 1024).await? else {
                        return Ok(());
                    };
                    if bytes == b".\r\n" || bytes == b".\n" {
                        break;
                    }
                    if bytes.starts_with(b"..") {
                        bytes.remove(0);
                    }
                    if raw.len() + bytes.len() > MAX_BYTES {
                        too_large = true;
                    }
                    if !too_large {
                        raw.extend_from_slice(&bytes);
                    }
                }
                if too_large {
                    reply(&mut reader, "552 Message exceeds size limit\r\n").await?;
                } else {
                    match mail::capture(
                        &raw,
                        Some((sender.as_deref().unwrap_or_default(), &recipients)),
                        None,
                    ) {
                        Ok(mut captures) => {
                            mail::score(
                                &mut captures,
                                std::env::var("MSGPIT_SPAMASSASSIN").ok().as_deref(),
                                &raw,
                            )
                            .await;
                            match store.capture(captures).await {
                                Ok(()) => reply(&mut reader, "250 Message captured\r\n").await?,
                                Err(error) => {
                                    tracing::error!(%error, "SMTP capture failed");
                                    reply(&mut reader, "451 Storage unavailable\r\n").await?;
                                }
                            }
                        }
                        Err(_) => reply(&mut reader, "554 Invalid MIME message\r\n").await?,
                    }
                }
                sender = None;
                recipients.clear();
            }
            "RSET" => {
                sender = None;
                recipients.clear();
                reply(&mut reader, "250 Reset\r\n").await?;
            }
            "NOOP" => reply(&mut reader, "250 OK\r\n").await?,
            "QUIT" => {
                reply(&mut reader, "221 Bye\r\n").await?;
                break;
            }
            "MAIL" | "RCPT" | "DATA" => reply(&mut reader, "503 Bad command sequence\r\n").await?,
            _ => reply(&mut reader, "502 Command not implemented\r\n").await?,
        }
    }
    Ok(())
}
