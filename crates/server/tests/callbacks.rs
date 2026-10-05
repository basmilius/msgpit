use axum::{
    Router,
    body::Bytes,
    http::{HeaderMap, StatusCode},
    routing::post,
};
use http_body_util::BodyExt;
use msgpit_server::{
    api::{self, AppState},
    model::Filters,
    network::Network,
    providers::{CallbackConfig, spryng::Spryng},
    store::Store,
};
use serde_json::{Value, json};
use std::sync::Arc;
use tower::ServiceExt;

async fn call(app: &Router, path: &str, body: Value) -> (StatusCode, Value) {
    let response = app
        .clone()
        .oneshot(
            axum::http::Request::builder()
                .method("POST")
                .uri(path)
                .header("content-type", "application/json")
                .header("x-api-key", "development")
                .body(axum::body::Body::from(body.to_string()))
                .unwrap(),
        )
        .await
        .unwrap();
    let status = response.status();
    let bytes = response.into_body().collect().await.unwrap().to_bytes();
    (status, serde_json::from_slice(&bytes).unwrap())
}
#[tokio::test]
async fn delivery_callback_preserves_metadata_auth_response_and_read_state() {
    let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
    let url = format!("http://{}/callback", listener.local_addr().unwrap());
    let (send, mut receive) = tokio::sync::mpsc::unbounded_channel();
    let endpoint = Router::new().route(
        "/callback",
        post(move |headers: HeaderMap, body: Bytes| {
            let send = send.clone();
            async move {
                send.send((headers, body)).unwrap();
                (StatusCode::ACCEPTED, "recorded")
            }
        }),
    );
    let task = tokio::spawn(async move {
        axum::serve(listener, endpoint).await.unwrap();
    });
    let dir = tempfile::tempdir().unwrap();
    let store = Store::open(dir.path().join("messages.sqlite").to_str().unwrap(), 100).unwrap();
    let app = api::router(
        AppState {
            store: store.clone(),
            network: Network::new(false, None).unwrap(),
            providers: Arc::new(vec![Box::new(Spryng::new(CallbackConfig {
                url: Some(url),
                header: Some(("X-Webhook-Token".into(), "callback-secret".into())),
            }))]),
            smtp_port: 0,
            shutdown: tokio_util::sync::CancellationToken::new(),
        },
        "/nonexistent",
    );
    let send = json!({"accountReference":"SPNL0000000","channel":"SMS","from":"Acme","body":{"text":"Hello 👋"},"recipients":[{"msisdn":"+31612345678","metaData":{"invoice":"42"}}]});
    assert_eq!(
        call(&app, "/spryng/v2/messages", send).await.0,
        StatusCode::ACCEPTED
    );
    let message = store.list(Filters::default()).await.unwrap().remove(0);
    store.mark_read(Some(message.id.clone())).await.unwrap();
    for (status, reason) in [("delivered", "Delivered"), ("failed", "NetworkFailed")] {
        let (code, report) = call(
            &app,
            &format!("/api/messages/{}/dlr", message.id),
            json!({"status":status}),
        )
        .await;
        assert_eq!(code, StatusCode::OK);
        assert_eq!(report["callbackSent"], true);
        let (headers, body) = receive.recv().await.unwrap();
        assert_eq!(headers["x-webhook-token"], "callback-secret");
        let callback: Value = serde_json::from_slice(&body).unwrap();
        assert_eq!(callback["RequestId"], message.batch_id);
        assert_eq!(callback["Messages"][0]["Msisdn"], "31612345678");
        assert_eq!(callback["Messages"][0]["Metadata"], json!({"invoice":"42"}));
        assert_eq!(callback["Messages"][0]["Reason"], reason);
        let detail = store.detail(message.id.clone()).await.unwrap().unwrap();
        assert!(detail.message.read);
        assert_eq!(detail.message.status, status);
        assert_eq!(
            detail.delivery_reports.last().unwrap()["responseStatus"],
            202
        );
        assert_eq!(
            detail.delivery_reports.last().unwrap()["responseBody"],
            "recorded"
        );
        assert!(
            !serde_json::to_string(&detail)
                .unwrap()
                .contains("callback-secret")
        );
    }
    assert_eq!(
        call(
            &app,
            &format!("/api/messages/{}/dlr", message.id),
            json!({"status":"unknown"})
        )
        .await
        .0,
        StatusCode::BAD_REQUEST
    );
    task.abort();
}
#[tokio::test]
async fn link_probes_fall_back_to_get_keep_redirects_and_refuse_metadata_targets() {
    let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
    let base = format!("http://{}", listener.local_addr().unwrap());
    let endpoint = Router::new()
        .route(
            "/head-only",
            axum::routing::get(|| async { "ok" }).head(|| async { StatusCode::METHOD_NOT_ALLOWED }),
        )
        .route(
            "/redirect",
            axum::routing::get(|| async { axum::response::Redirect::temporary("/head-only") }),
        );
    let task = tokio::spawn(async move {
        axum::serve(listener, endpoint).await.unwrap();
    });
    let network = Network::new(false, None).unwrap();
    let links = vec![
        json!({"url":format!("{base}/head-only"),"kind":"link"}),
        json!({"url":format!("{base}/redirect"),"kind":"link"}),
        json!({"url":"http://169.254.169.254/latest/meta-data","kind":"link"}),
    ];
    let results = network.check_links(&links).await;
    assert_eq!(results[0]["status"], 200);
    assert_eq!(results[1]["status"], 307);
    assert_eq!(results[1]["redirect"], "/head-only");
    assert_eq!(results[2]["status"], Value::Null);
    assert!(results[2]["reason"].as_str().unwrap().contains("Refused"));
    task.abort();
}
#[tokio::test]
async fn mail_import_scores_through_spamd_without_changing_8bit_bytes() {
    use tokio::io::{AsyncBufReadExt, AsyncReadExt, AsyncWriteExt};
    let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
    let endpoint = listener.local_addr().unwrap();
    let raw=b"From: sender@example.test\r\nTo: reader@example.test\r\nContent-Type: text/plain; charset=iso-8859-1\r\n\r\nCaf\xe9\n";
    let expected = raw[..raw.len() - 1]
        .iter()
        .copied()
        .chain(b"\r\n".iter().copied())
        .collect::<Vec<_>>();
    let task = tokio::spawn(async move {
        let (socket, _) = listener.accept().await.unwrap();
        let mut reader = tokio::io::BufReader::new(socket);
        let mut length = 0;
        let mut line = String::new();
        reader.read_line(&mut line).await.unwrap();
        assert_eq!(line, "REPORT SPAMC/1.5\r\n");
        loop {
            line.clear();
            reader.read_line(&mut line).await.unwrap();
            if line == "\r\n" {
                break;
            }
            if let Some(value) = line.strip_prefix("Content-length:") {
                length = value.trim().parse().unwrap();
            }
        }
        let mut bytes = vec![0; length];
        reader.read_exact(&mut bytes).await.unwrap();
        assert_eq!(bytes, expected);
        reader.get_mut().write_all(b"SPAMD/1.5 0 EX_OK\r\nSpam: False ; 0.3 / 5.0\r\n\r\n pts rule name description\n---- ---- ----\n 0.3 TEST_RULE Test rule\n").await.unwrap();
    });
    let dir = tempfile::tempdir().unwrap();
    let store = Store::open(dir.path().join("score.sqlite").to_str().unwrap(), 100).unwrap();
    let app = api::router(
        AppState {
            store: store.clone(),
            network: Network::new(false, Some(endpoint.to_string())).unwrap(),
            providers: Arc::new(vec![Box::new(Spryng::default())]),
            smtp_port: 0,
            shutdown: tokio_util::sync::CancellationToken::new(),
        },
        "/nonexistent",
    );
    let response = app
        .oneshot(
            axum::http::Request::builder()
                .method("POST")
                .uri("/api/messages/import")
                .body(axum::body::Body::from(raw.to_vec()))
                .unwrap(),
        )
        .await
        .unwrap();
    assert_eq!(response.status(), StatusCode::CREATED);
    task.await.unwrap();
    let message = store.list(Filters::default()).await.unwrap().remove(0);
    assert_eq!(message.meta["spam"]["score"], 0.3);
    assert_eq!(message.meta["spam"]["rules"][0]["name"], "TEST_RULE");
    assert!(message.body.contains("Café"));
}
