use axum::{
    Router,
    body::Body,
    http::{HeaderMap, Method, Request, StatusCode},
};
use http_body_util::BodyExt;
use msgpit_server::{
    api::{self, AppState},
    mail,
    model::{Detail, Filters, Message, Scenario},
    providers::{Provider, ProviderRequest, spryng::Spryng},
    store::Store,
};
use serde_json::{Value, json};
use std::sync::Arc;
use tower::ServiceExt;

fn app(store: Store) -> Router {
    api::router(
        AppState {
            store,
            network: msgpit_server::network::Network::new(false, None).unwrap(),
            providers: Arc::new(vec![Box::new(Spryng::default())]),
            smtp_port: 1025,
            shutdown: tokio_util::sync::CancellationToken::new(),
        },
        "/nonexistent",
    )
}

async fn call(app: Router, method: &str, path: &str, body: Value) -> (StatusCode, Value) {
    let response = app
        .oneshot(
            Request::builder()
                .method(method)
                .uri(path)
                .header("content-type", "application/json")
                .header("x-api-key", "test-secret")
                .body(Body::from(body.to_string()))
                .unwrap(),
        )
        .await
        .unwrap();
    let status = response.status();
    let bytes = response.into_body().collect().await.unwrap().to_bytes();
    (
        status,
        serde_json::from_slice(&bytes).unwrap_or(Value::Null),
    )
}

fn payload(to: &str) -> Value {
    json!({"accountReference":"SPNL0000000", "channel":"SMS", "from":"Acme", "body":{"text":"Hello 👋"}, "recipients":[{"msisdn":to}]})
}

fn assert_subset(actual: &Value, expected: &Value) {
    match expected {
        Value::String(s) if s == "@uuid" => {
            uuid::Uuid::parse_str(actual.as_str().unwrap()).unwrap();
        }
        Value::Object(fields) => {
            for (key, value) in fields {
                assert_subset(&actual[key], value);
            }
        }
        Value::Array(values) => {
            assert_eq!(actual.as_array().unwrap().len(), values.len());
            for (actual, expected) in actual.as_array().unwrap().iter().zip(values) {
                assert_subset(actual, expected);
            }
        }
        _ => assert_eq!(actual, expected),
    }
}

#[tokio::test]
async fn spryng_matches_preserved_send_and_balance_fixtures() {
    for case in [
        "send-single",
        "send-multiple-recipients",
        "send-unicode",
        "send-with-variables",
        "missing-auth",
        "invalid-account-reference",
        "invalid-msisdn",
        "no-recipients",
        "balance-wrapped",
        "webhook-events",
        "webhook-subscriptions",
        "webhook-subscribe",
        "webhook-auth-method",
        "webhook-unsubscribe",
    ] {
        let directory = std::path::Path::new(env!("CARGO_MANIFEST_DIR"))
            .join("../../reference/tests/fixtures/spryng")
            .join(case);
        let request: Value =
            serde_json::from_slice(&std::fs::read(directory.join("request.json")).unwrap())
                .unwrap();
        let expected: Value = serde_json::from_slice(
            &std::fs::read(directory.join("expected-response.json")).unwrap(),
        )
        .unwrap();
        let method = Method::from_bytes(request["method"].as_str().unwrap().as_bytes()).unwrap();
        let mut headers = HeaderMap::new();
        for (key, value) in request["headers"].as_object().unwrap() {
            headers.insert(
                axum::http::HeaderName::from_bytes(key.as_bytes()).unwrap(),
                value.as_str().unwrap().parse().unwrap(),
            );
        }
        let body = request["body"].to_string();
        let capture = Spryng::default().translate(&ProviderRequest {
            method: &method,
            path: request["path"]
                .as_str()
                .unwrap()
                .strip_prefix("/spryng")
                .unwrap(),
            headers: &headers,
            body: body.as_bytes(),
        });
        assert_eq!(
            capture.response.status().as_u16(),
            expected["status"].as_u64().unwrap() as u16,
            "{case}"
        );
        let bytes = capture
            .response
            .into_body()
            .collect()
            .await
            .unwrap()
            .to_bytes();
        let response: Value = if expected["status"] == 204 {
            assert!(bytes.is_empty(), "{case}");
            json!({})
        } else {
            serde_json::from_slice(&bytes).unwrap()
        };
        assert_subset(&response, &expected["body"]);
        let expected_messages = directory.join("expected-messages.json");
        if expected_messages.exists() {
            let expected: Value =
                serde_json::from_slice(&std::fs::read(expected_messages).unwrap()).unwrap();
            assert_subset(&serde_json::to_value(&capture.messages).unwrap(), &expected);
            for message in &capture.messages {
                assert_eq!(
                    message.batch_id,
                    response["data"]["requestId"].as_str().unwrap()
                );
            }
        } else {
            assert!(capture.messages.is_empty(), "{case}");
        }
    }
}

#[tokio::test]
async fn api_captures_masks_credentials_filters_and_marks_read_idempotently() {
    let store = Store::open(":memory:", 100).unwrap();
    let app = app(store.clone());
    let (status, response) = call(
        app.clone(),
        "POST",
        "/spryng/v2/messages",
        payload("+31612345678"),
    )
    .await;
    assert_eq!(status, StatusCode::ACCEPTED);
    let (_, list) = call(
        app.clone(),
        "GET",
        "/api/messages?channel=sms&to=%2B3161234",
        Value::Null,
    )
    .await;
    assert_eq!(list["unread"], 1);
    let id = list["messages"][0]["id"].as_str().unwrap();
    assert_eq!(
        list["messages"][0]["providerRef"],
        response["data"]["messageIds"][0]
    );
    let (_, detail) = call(
        app.clone(),
        "GET",
        &format!("/api/messages/{id}"),
        Value::Null,
    )
    .await;
    let raw = detail["rawRequest"].as_str().unwrap();
    assert!(!raw.contains("test-secret"));
    assert!(raw.contains("[redacted]"));
    assert!(raw.starts_with("POST /spryng/v2/messages HTTP/1.1"));
    for _ in 0..2 {
        let (_, result) = call(
            app.clone(),
            "POST",
            &format!("/api/messages/{id}/read"),
            Value::Null,
        )
        .await;
        assert_eq!(result["unread"], 0);
    }
    assert_eq!(store.events_since(0).await.unwrap().len(), 2);
    assert_eq!(
        call(
            app.clone(),
            "GET",
            "/api/messages?channel=unknown",
            Value::Null
        )
        .await
        .1["messages"],
        json!([])
    );
    assert_eq!(
        call(app.clone(), "GET", "/api/messages/missing", Value::Null)
            .await
            .0,
        StatusCode::NOT_FOUND
    );
    assert_eq!(
        call(app.clone(), "DELETE", "/api/messages", Value::Null)
            .await
            .0,
        StatusCode::NO_CONTENT
    );
    assert!(store.list(Filters::default()).await.unwrap().is_empty());
}

#[tokio::test]
async fn one_shot_and_magic_failures_never_store_messages() {
    let store = Store::open(":memory:", 100).unwrap();
    let app = app(store.clone());
    store
        .set_scenario(Some(Scenario::ServerError))
        .await
        .unwrap();
    assert_eq!(
        call(app.clone(), "POST", "/spryng/v2/messages", json!({}))
            .await
            .0,
        StatusCode::BAD_REQUEST
    );
    assert_eq!(
        call(
            app.clone(),
            "POST",
            "/spryng/v2/messages",
            payload("+31612345678")
        )
        .await
        .0,
        StatusCode::INTERNAL_SERVER_ERROR
    );
    assert_eq!(
        call(
            app.clone(),
            "POST",
            "/spryng/v2/messages",
            payload("+31612345678")
        )
        .await
        .0,
        StatusCode::ACCEPTED
    );
    for (number, expected) in [
        ("+31600000001", 400),
        ("+31600000002", 401),
        ("+31600000003", 429),
        ("+31600000004", 500),
    ] {
        assert_eq!(
            call(app.clone(), "POST", "/spryng/v2/messages", payload(number))
                .await
                .0
                .as_u16(),
            expected
        );
    }
    assert_eq!(store.list(Filters::default()).await.unwrap().len(), 1);
    assert_eq!(
        call(app, "POST", "/api/scenario", json!({"scenario":"unknown"}))
            .await
            .0,
        StatusCode::UNPROCESSABLE_ENTITY
    );
}

#[tokio::test]
async fn mail_import_keeps_one_row_and_exact_bytes_and_prunes_parts() {
    let directory = tempfile::tempdir().unwrap();
    let path = directory.path().join("test.sqlite");
    let store = Store::open(path.to_str().unwrap(), 1).unwrap();
    let raw = b"From: sender@example.test\r\nTo: a@example.test, b@example.test\r\nSubject: =?UTF-8?B?SGVsbG8g8J+RiA==?=\r\nX-Trace: first\r\nX-Trace: second\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nHello world\r\n";
    let captures = mail::capture(raw, None, Some("example.eml")).unwrap();
    assert_eq!(captures.len(), 1);
    let detail = &captures[0].0;
    assert_eq!(detail.message.meta["subject"], "Hello 👈");
    assert_eq!(detail.message.provider, "import");
    assert!(detail.message.to.contains("b@example.test"));
    assert!(detail.message.meta.get("envelopeSender").is_none());
    let id = detail.message.id.clone();
    let part = detail.parts[0].id.clone();
    let mut legacy = serde_json::to_value(detail).unwrap();
    legacy.as_object_mut().unwrap().remove("headerList");
    assert!(
        serde_json::from_value::<Detail>(legacy)
            .unwrap()
            .header_list
            .is_empty()
    );
    store.capture(captures).await.unwrap();
    let (status, result) = call(
        app(store.clone()),
        "GET",
        &format!("/api/messages/{id}"),
        Value::Null,
    )
    .await;
    assert_eq!(status, StatusCode::OK);
    let traces: Vec<_> = result["headerList"]
        .as_array()
        .unwrap()
        .iter()
        .filter(|header| header["name"] == "X-Trace")
        .map(|header| header["value"].as_str().unwrap())
        .collect();
    assert_eq!(traces, ["first", "second"]);
    assert_eq!(
        store.part(id.clone(), part.clone()).await.unwrap().unwrap(),
        raw
    );
    drop(store);
    let store = Store::open(path.to_str().unwrap(), 1).unwrap();
    assert_eq!(store.list(Filters::default()).await.unwrap().len(), 1);
    let message = Message::new("test", "sms", "batch", "recipient", "new");
    store
        .capture(vec![(Detail::new(message, String::new()), vec![])])
        .await
        .unwrap();
    assert!(store.detail(id.clone()).await.unwrap().is_none());
    assert!(store.part(id, part).await.unwrap().is_none());
}

#[tokio::test]
async fn sse_resumes_after_the_requested_sequence() {
    let store = Store::open(":memory:", 100).unwrap();
    let app = app(store.clone());
    call(
        app.clone(),
        "POST",
        "/spryng/v2/messages",
        payload("+31612345678"),
    )
    .await;
    call(
        app.clone(),
        "POST",
        "/spryng/v2/messages",
        payload("+31612345679"),
    )
    .await;
    let response = app
        .oneshot(
            Request::builder()
                .uri("/api/stream?seq=0")
                .header("last-event-id", "1")
                .body(Body::empty())
                .unwrap(),
        )
        .await
        .unwrap();
    assert_eq!(response.headers()["content-type"], "text/event-stream");
    let frame = tokio::time::timeout(
        std::time::Duration::from_secs(2),
        response.into_body().frame(),
    )
    .await
    .unwrap()
    .unwrap()
    .unwrap();
    let data = String::from_utf8(frame.into_data().unwrap().to_vec()).unwrap();
    assert!(data.contains("id: 2"));
    assert!(!data.contains("id: 1"));
}

#[tokio::test]
async fn built_assets_are_not_intercepted_by_provider_routes() {
    let directory = tempfile::tempdir().unwrap();
    std::fs::create_dir(directory.path().join("assets")).unwrap();
    std::fs::write(directory.path().join("index.html"), "<h1>msgpit</h1>").unwrap();
    std::fs::write(directory.path().join("assets/app.js"), "export {}").unwrap();
    let state = AppState {
        store: Store::open(":memory:", 10).unwrap(),
        network: msgpit_server::network::Network::new(false, None).unwrap(),
        providers: Arc::new(vec![]),
        smtp_port: 1025,
        shutdown: tokio_util::sync::CancellationToken::new(),
    };
    let app = api::router(state, directory.path().to_str().unwrap());
    for path in ["/", "/assets/app.js"] {
        let response = app
            .clone()
            .oneshot(Request::builder().uri(path).body(Body::empty()).unwrap())
            .await
            .unwrap();
        assert_eq!(response.status(), StatusCode::OK, "{path}");
    }
}
