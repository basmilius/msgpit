use crate::{
    mail,
    model::{Detail, Filters, Scenario},
    providers::{Provider, ProviderRequest},
    store::Store,
};
use axum::{
    Json, Router,
    body::Bytes,
    extract::{DefaultBodyLimit, Path, Query, State},
    http::{HeaderMap, Method, StatusCode},
    response::{
        IntoResponse, Response, Sse,
        sse::{Event, KeepAlive},
    },
    routing::{get, post},
};
use serde::Deserialize;
use serde_json::{Value, json};
use std::{convert::Infallible, sync::Arc, time::Duration};
use tower_http::services::ServeDir;

pub const MAX_BYTES: usize = 30 * 1024 * 1024;

#[derive(Clone)]
pub struct AppState {
    pub store: Store,
    pub network: crate::network::Network,
    pub providers: Arc<Vec<Box<dyn Provider>>>,
    pub smtp_port: u16,
    pub shutdown: tokio_util::sync::CancellationToken,
}

struct ApiError(StatusCode, String);
impl From<anyhow::Error> for ApiError {
    fn from(error: anyhow::Error) -> Self {
        tracing::error!(%error, "Request failed");
        Self(
            StatusCode::INTERNAL_SERVER_ERROR,
            "The server could not complete this request.".into(),
        )
    }
}
impl IntoResponse for ApiError {
    fn into_response(self) -> Response {
        (self.0, Json(json!({"error":self.1}))).into_response()
    }
}
fn missing() -> ApiError {
    ApiError(StatusCode::NOT_FOUND, "Message not found.".into())
}

pub fn router(state: AppState, web_dir: &str) -> Router {
    Router::new()
        .nest_service("/assets", ServeDir::new(format!("{web_dir}/assets")))
        .route("/healthz", get(health))
        .route("/api/messages", get(list).delete(clear))
        .route("/api/messages/read", post(read_all))
        .route("/api/messages/import", post(import))
        .route("/api/messages/{id}", get(detail))
        .route("/api/messages/{id}/read", post(read))
        .route("/api/messages/{id}/dlr", post(dlr))
        .route("/api/messages/{id}/links", post(check_links))
        .route("/api/messages/{id}/authentication", post(authentication))
        .route("/api/docs", get(docs))
        .route("/api/docs/{slug}", get(doc))
        .route("/api/messages/{id}/parts/{part}", get(part))
        .route("/api/providers", get(providers))
        .route("/api/scenarios", get(scenarios))
        .route("/api/scenario", post(scenario))
        .route("/api/stream", get(stream))
        .route("/{provider}/{*path}", axum::routing::any(provider_request))
        .fallback_service(ServeDir::new(web_dir))
        .layer(DefaultBodyLimit::max(MAX_BYTES))
        .with_state(state)
}

async fn health(State(state): State<AppState>) -> Result<Json<Value>, ApiError> {
    state.store.events_since(i64::MAX).await?;
    Ok(Json(
        json!({"status":"ok", "version":env!("CARGO_PKG_VERSION"), "smtp":{"port":state.smtp_port}}),
    ))
}

async fn list(
    State(state): State<AppState>,
    Query(filters): Query<Filters>,
) -> Result<Json<Value>, ApiError> {
    let messages = state.store.list(filters).await?;
    let unread = state
        .store
        .list(Filters::default())
        .await?
        .iter()
        .filter(|m| !m.read)
        .count();
    Ok(Json(
        json!({"messages":messages,"unread":unread,"scenario":state.store.scenario().await?}),
    ))
}
async fn clear(State(state): State<AppState>) -> Result<StatusCode, ApiError> {
    state.store.clear().await?;
    Ok(StatusCode::NO_CONTENT)
}
async fn detail(
    State(state): State<AppState>,
    Path(id): Path<String>,
) -> Result<Json<Value>, ApiError> {
    let mut detail = state.store.detail(id).await?.ok_or_else(missing)?;
    if detail.message.channel == "email" {
        mail::enrich(&mut detail);
    }
    Ok(Json(crate::report::detail_json(&detail)))
}
async fn read(
    State(state): State<AppState>,
    Path(id): Path<String>,
) -> Result<Json<Value>, ApiError> {
    if !state.store.mark_read(Some(id)).await? {
        return Err(missing());
    }
    unread(&state).await
}
async fn read_all(State(state): State<AppState>) -> Result<Json<Value>, ApiError> {
    state.store.mark_read(None).await?;
    unread(&state).await
}
async fn unread(state: &AppState) -> Result<Json<Value>, ApiError> {
    let count = state
        .store
        .list(Filters::default())
        .await?
        .iter()
        .filter(|m| !m.read)
        .count();
    Ok(Json(json!({"unread":count})))
}
async fn import(
    State(state): State<AppState>,
    headers: HeaderMap,
    body: Bytes,
) -> Result<(StatusCode, Json<Value>), ApiError> {
    if body.iter().all(u8::is_ascii_whitespace) {
        return Err(ApiError(
            StatusCode::BAD_REQUEST,
            "An empty file is not a message.".into(),
        ));
    }
    let filename = headers
        .get("x-msgpit-filename")
        .and_then(|h| h.to_str().ok())
        .map(|s| {
            percent_encoding::percent_decode_str(s)
                .decode_utf8_lossy()
                .into_owned()
        });
    let mut captures = mail::capture(&body, None, filename.as_deref())
        .map_err(|e| ApiError(StatusCode::BAD_REQUEST, e.to_string()))?;
    mail::score(&mut captures, state.network.spamd.as_deref(), &body).await;
    let imported = captures.len();
    state.store.capture(captures).await?;
    Ok((StatusCode::CREATED, Json(json!({"imported":imported}))))
}
async fn part(
    State(state): State<AppState>,
    Path((id, part)): Path<(String, String)>,
) -> Result<Response, ApiError> {
    let detail = state.store.detail(id.clone()).await?.ok_or_else(missing)?;
    let summary = detail
        .parts
        .iter()
        .find(|p| p.id == part)
        .ok_or_else(missing)?;
    let content = state.store.part(id, part).await?.ok_or_else(missing)?;
    let mime = if [
        "image/png",
        "image/jpeg",
        "image/gif",
        "image/webp",
        "text/plain",
    ]
    .contains(&summary.content_type.as_str())
    {
        summary.content_type.as_str()
    } else {
        "application/octet-stream"
    };
    let mut response = content.into_response();
    response
        .headers_mut()
        .insert("content-type", mime.parse().unwrap());
    response
        .headers_mut()
        .insert("x-content-type-options", "nosniff".parse().unwrap());
    response
        .headers_mut()
        .insert("cache-control", "no-store".parse().unwrap());
    if let Some(filename) = &summary.filename {
        let encoded =
            percent_encoding::utf8_percent_encode(filename, percent_encoding::NON_ALPHANUMERIC);
        response.headers_mut().insert(
            "content-disposition",
            format!("attachment; filename*=UTF-8''{encoded}")
                .parse()
                .unwrap(),
        );
    }
    Ok(response)
}
async fn check_links(
    State(state): State<AppState>,
    Path(id): Path<String>,
) -> Result<Json<Value>, ApiError> {
    let mut detail = state.store.detail(id).await?.ok_or_else(missing)?;
    mail::enrich(&mut detail);
    let links = crate::html::links(detail.html.as_deref(), detail.text.as_deref());
    Ok(Json(
        json!({"links":state.network.check_links(&links).await}),
    ))
}

async fn authentication(
    State(state): State<AppState>,
    Path(id): Path<String>,
) -> Result<Json<Value>, ApiError> {
    let mut detail = state.store.detail(id).await?.ok_or_else(missing)?;
    if detail.message.channel != "email" {
        return Err(missing());
    }
    if state.network.auth.is_none() {
        return Err(ApiError(
            StatusCode::CONFLICT,
            "DNS lookups are switched off with MSGPIT_DNS.".into(),
        ));
    }
    mail::enrich(&mut detail);
    let (report, lookups) = crate::authentication::check(&detail, &state.network).await;
    Ok(Json(json!({"report":report,"lookups":lookups})))
}

async fn docs() -> Json<Value> {
    Json(json!({"pages":crate::docs::index()}))
}
async fn doc(Path(slug): Path<String>) -> Result<Json<Value>, ApiError> {
    let markdown = crate::docs::page(&slug)
        .ok_or_else(|| ApiError(StatusCode::NOT_FOUND, "Page not found.".into()))?;
    Ok(Json(json!({"slug":slug,"markdown":markdown})))
}

async fn dlr(
    State(state): State<AppState>,
    Path(id): Path<String>,
    Json(input): Json<Value>,
) -> Result<Json<Value>, ApiError> {
    let detail = state.store.detail(id.clone()).await?.ok_or_else(missing)?;
    let status = input["status"].as_str().unwrap_or_default();
    if !matches!(status, "delivered" | "failed") {
        return Err(ApiError(
            StatusCode::BAD_REQUEST,
            "Status must be 'delivered' or 'failed'.".into(),
        ));
    }
    let callback = state
        .providers
        .iter()
        .find(|p| p.id() == detail.message.provider)
        .and_then(|p| p.callback(&detail.message, status));
    let report = if let Some(callback) = &callback {
        let (response_status, response_body) = state.network.callback(callback).await;
        Some(
            json!({"status":status,"url":callback.url,"requestBody":callback.body,"responseStatus":response_status,"responseBody":response_body,"sentAt":crate::model::now()}),
        )
    } else {
        None
    };
    let detail = state
        .store
        .delivery_report(id, status.into(), report)
        .await?;
    Ok(Json(
        json!({"status":status,"callbackSent":callback.is_some(),"deliveryReports":detail.delivery_reports}),
    ))
}

async fn providers(State(state): State<AppState>) -> Json<Value> {
    Json(
        json!({"providers":state.providers.iter().map(|p| json!({"id":p.id(),"channels":p.channels(),"deliveryReports":p.supports_delivery_reports(),"errorScenarios":true,"basePath":format!("/{}",p.id()),"baseUrl":format!("http://msgpit:8080/{}",p.id())})).collect::<Vec<_>>(),
        "smtp":{"port":state.smtp_port}, "version":env!("CARGO_PKG_VERSION")}),
    )
}
async fn scenarios() -> Json<Value> {
    Json(
        json!({"scenarios":Scenario::ALL.into_iter().map(|s| json!({"id":s,"scenario":s,"recipient":s.recipient(),"description":s.description()})).collect::<Vec<_>>()}),
    )
}
async fn scenario(
    State(state): State<AppState>,
    Json(input): Json<Value>,
) -> Result<Json<Value>, ApiError> {
    let scenario = match input.get("scenario") {
        Some(Value::String(s)) if !s.is_empty() => Some(
            serde_json::from_value::<Scenario>(json!(s))
                .map_err(|_| ApiError(StatusCode::BAD_REQUEST, "Unknown scenario.".into()))?,
        ),
        _ => None,
    };
    state.store.set_scenario(scenario).await?;
    Ok(Json(json!({"scenario":scenario})))
}

async fn provider_request(
    State(state): State<AppState>,
    Path((id, path)): Path<(String, String)>,
    method: Method,
    headers: HeaderMap,
    body: Bytes,
) -> Result<Response, ApiError> {
    let provider = state
        .providers
        .iter()
        .find(|p| p.id() == id)
        .ok_or_else(|| ApiError(StatusCode::NOT_FOUND, "Provider not found.".into()))?;
    let path = format!("/{path}");
    let capture = provider.translate(&ProviderRequest {
        method: &method,
        path: &path,
        headers: &headers,
        body: &body,
    });
    if !capture.messages.is_empty() {
        let armed = state.store.consume_scenario().await?;
        let triggered = armed.or_else(|| {
            capture
                .messages
                .iter()
                .find_map(|m| Scenario::for_recipient(&m.to))
        });
        if let Some(scenario) = triggered {
            return Ok(provider.failure(scenario));
        }
        let raw = raw_request(&method, &format!("/{id}{path}"), &headers, &body);
        state
            .store
            .capture(
                capture
                    .messages
                    .into_iter()
                    .map(|m| (Detail::new(m, raw.clone()), vec![]))
                    .collect(),
            )
            .await?;
    }
    Ok(capture.response)
}

fn raw_request(method: &Method, path: &str, headers: &HeaderMap, body: &[u8]) -> String {
    let mut raw = format!("{method} {path} HTTP/1.1\n");
    for (name, value) in headers {
        let secret = [
            "authorization",
            "proxy-authorization",
            "cookie",
            "set-cookie",
            "api-key",
            "x-api-key",
        ]
        .contains(&name.as_str())
            || name.as_str().contains("token")
            || name.as_str().contains("secret");
        raw.push_str(&format!(
            "{name}: {}\n",
            if secret {
                "[redacted]"
            } else {
                value.to_str().unwrap_or("[binary]")
            }
        ));
    }
    raw.push('\n');
    raw.push_str(&String::from_utf8_lossy(body));
    raw
}

#[derive(Default, Deserialize)]
struct StreamQuery {
    #[serde(default)]
    seq: i64,
}
async fn stream(
    State(state): State<AppState>,
    Query(query): Query<StreamQuery>,
    headers: HeaderMap,
) -> Sse<impl futures_core::Stream<Item = Result<Event, Infallible>>> {
    let mut changes = state.store.changes.subscribe();
    let mut seq = headers
        .get("last-event-id")
        .and_then(|v| v.to_str().ok())
        .and_then(|v| v.parse().ok())
        .unwrap_or(query.seq)
        .max(0);
    let events = async_stream::stream! {
        loop {
            if state.shutdown.is_cancelled() { break; }
            match state.store.events_since(seq).await {
                Ok(batch) if !batch.is_empty() => {
                    for (id, payload) in batch {
                        seq = id;
                        yield Ok(Event::default().id(id.to_string()).data(payload.to_string()));
                    }
                    continue;
                }
                Ok(_) => {}
                Err(error) => { tracing::error!(%error, "Event stream failed"); break; }
            }
            tokio::select! {
                _ = state.shutdown.cancelled() => break,
                _ = changes.recv() => {}
                _ = tokio::time::sleep(Duration::from_secs(10)) => {}
            }
        }
    };
    Sse::new(events).keep_alive(KeepAlive::new().interval(Duration::from_secs(20)))
}
