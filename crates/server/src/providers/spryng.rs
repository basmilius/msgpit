use crate::{
    model::{Message, Scenario},
    providers::{Callback, CallbackConfig, Capture, Provider, ProviderRequest},
};
use axum::{
    Json,
    http::{Method, StatusCode},
    response::{IntoResponse, Response},
};
use serde_json::{Value, json};
use uuid::Uuid;

#[derive(Default)]
pub struct Spryng {
    pub callback: CallbackConfig,
}

impl Spryng {
    pub fn new(callback: CallbackConfig) -> Self {
        Self { callback }
    }
}

fn error(code: &str, message: &str, status: StatusCode) -> Response {
    (
        status,
        Json(json!({"errors":[{"errorCode":code,"errorMessage":message}]})),
    )
        .into_response()
}

fn unauthorized(path: &str) -> Response {
    error(
        "UnauthenticatedError",
        &format!("Request for authenticated route '{path}' was unauthenticated"),
        StatusCode::UNAUTHORIZED,
    )
}

fn e164(value: &str) -> bool {
    value.starts_with('+')
        && (8..=16).contains(&value.len())
        && value.as_bytes()[1] != b'0'
        && value[1..].bytes().all(|c| c.is_ascii_digit())
}

impl Provider for Spryng {
    fn id(&self) -> &'static str {
        "spryng"
    }
    fn channels(&self) -> &'static [&'static str] {
        &["sms"]
    }

    fn translate(&self, request: &ProviderRequest<'_>) -> Capture {
        let empty = |response| Capture {
            messages: vec![],
            response,
        };
        if request
            .headers
            .get("x-api-key")
            .or_else(|| request.headers.get("api-key"))
            .and_then(|v| v.to_str().ok())
            .is_none_or(|v| v.trim().is_empty())
        {
            return empty(unauthorized(&format!("/spryng{}", request.path)));
        }
        if request.method == Method::GET && request.path == "/v2/balance" {
            return empty(
                Json(json!({"data":{"available":"2951.73346","reserved":"0"}})).into_response(),
            );
        }
        let webhook = match (request.method.as_str(), request.path) {
            ("GET", "/v2/webhooks/events") => Some(Json(json!({"data":[
                {"id":"message-delivered","name":"Message delivered","description":"An outbound message reached the handset."},
                {"id":"message-failed","name":"Message failed","description":"An outbound message could not be delivered."},
                {"id":"message-received","name":"Message received","description":"An inbound message arrived on a VMN or shortcode."},
                {"id":"inbound-opted-out","name":"Opted out","description":"A recipient opted out of further messages."},
                {"id":"message-updated","name":"Message updated","description":"A message changed after it was submitted."},
                {"id":"schedule-updated","name":"Schedule updated","description":"A scheduled send changed."}
            ]})).into_response()),
            ("GET", "/v2/webhooks/subscriptions") => {
                let events: Vec<_> = self.callback.url.as_ref().map(|url| ["message-delivered", "message-failed"].into_iter()
                    .map(|event| json!({"eventType":event,"callbacks":[{"url":url}],"requiresAuthentication":self.callback.header.is_some()})).collect()).unwrap_or_default();
                Some(Json(json!({"data":{"events":events}})).into_response())
            }
            ("POST", "/v2/webhooks/subscriptions") => Some((StatusCode::CREATED, Json(json!({"data":{"created":true}}))).into_response()),
            ("PUT", "/v2/webhooks/authentication-methods") => Some(Json(json!({"data":{"updated":true}})).into_response()),
            ("DELETE", path) if path.strip_prefix("/v2/webhooks/events/").is_some_and(|event| !event.is_empty() && !event.contains('/')) => Some(StatusCode::NO_CONTENT.into_response()),
            _ => None,
        };
        if let Some(response) = webhook {
            return empty(response);
        }
        if request.method != Method::POST || request.path != "/v2/messages" {
            return empty(StatusCode::NOT_FOUND.into_response());
        }
        let Ok(payload) = serde_json::from_slice::<Value>(request.body) else {
            return empty(error(
                "invalid_json",
                "Request body must be valid JSON.",
                StatusCode::BAD_REQUEST,
            ));
        };
        let account = payload["accountReference"].as_str().unwrap_or_default();
        if !account.starts_with("SPNL")
            || account.len() != 11
            || !account[4..].bytes().all(|c| c.is_ascii_digit())
        {
            return empty(error(
                "accountReference_invalid",
                "A valid accountReference must be provided.",
                StatusCode::BAD_REQUEST,
            ));
        }
        if payload["channel"] != "SMS" {
            return empty(error(
                "channel_invalid",
                "Channel must be 'SMS'.",
                StatusCode::BAD_REQUEST,
            ));
        }
        if payload["body"]["text"].as_str().is_none()
            && payload["body"]["templateId"].as_str().is_none()
        {
            return empty(error(
                "body_invalid",
                "Either body.text or body.templateId must be provided.",
                StatusCode::BAD_REQUEST,
            ));
        }
        if payload.get("addressBook").is_some() && payload["recipients"].is_array() {
            return empty(error(
                "recipients_invalid",
                "Provide either recipients or addressBook, not both.",
                StatusCode::BAD_REQUEST,
            ));
        }
        let Some(recipients) = payload["recipients"].as_array().filter(|r| !r.is_empty()) else {
            return empty(error(
                "recipients_empty",
                "At least one recipient must be provided.",
                StatusCode::BAD_REQUEST,
            ));
        };
        if recipients
            .iter()
            .any(|r| !e164(r["msisdn"].as_str().unwrap_or_default()))
        {
            return empty(error(
                "msisdn_invalid",
                "Recipient msisdn must be in E.164 format.",
                StatusCode::BAD_REQUEST,
            ));
        }
        let batch_id = Uuid::new_v4().to_string();
        let messages: Vec<Message> = recipients.iter().map(|recipient| {
            let mut body = payload["body"]["text"].as_str().unwrap_or_default().to_string();
            if let Some(variables) = recipient["variables"].as_object() {
                for (name, value) in variables {
                    if !value.is_object() && !value.is_array() && !value.is_null() {
                        let rendered = value.as_str().map(String::from).unwrap_or_else(|| value.to_string());
                        body = body.replace(&format!("[{name}]"), &rendered);
                    }
                }
            }
            let mut message = Message::new("spryng", "sms", &batch_id, recipient["msisdn"].as_str().unwrap_or_default(), &body);
            message.from = payload["from"].as_str().map(String::from);
            message.provider_ref = Some(Uuid::new_v4().to_string());
            message.meta = json!({"accountReference": account, "characterSet": payload["characterSet"].as_str().unwrap_or("Auto")});
            for (name, value) in [
                ("messageType", &payload["messageType"]), ("requestName", &payload["name"]),
                ("validity", &payload["validity"]), ("templateId", &payload["body"]["templateId"]),
                ("variables", &recipient["variables"]), ("recipientMetaData", &recipient["metaData"]),
                ("requestMetaData", &payload["metaData"]),
            ] {
                if !value.is_null() { message.meta[name] = value.clone(); }
            }
            message
        }).collect();
        let ids: Vec<_> = messages.iter().map(|m| &m.provider_ref).collect();
        let response = (
            StatusCode::ACCEPTED,
            Json(json!({"data":{"requestId":batch_id,"messageIds":ids}})),
        )
            .into_response();
        Capture { messages, response }
    }

    fn supports_delivery_reports(&self) -> bool {
        true
    }

    fn callback(&self, message: &Message, status: &str) -> Option<Callback> {
        let url = self.callback.url.clone()?;
        let label = if status == "delivered" {
            "Delivered"
        } else {
            "Failed"
        };
        let payload = json!({
            "RequestId":message.batch_id,"AccountReference":message.meta.get("accountReference").cloned().unwrap_or(json!("SPNL0000000")),
            "Channel":"SMS","Status":label,"Messages":[{
                "MessageId":message.provider_ref,"MostRecentOutboundMessageId":null,"MostRecentOutboundRequestId":null,
                "OccurredAtTime":crate::model::now(),"Status":label,"Reason":if status == "delivered" { "Delivered" } else { "NetworkFailed" },
                "DestinationCode":"","Body":message.body,"MessageParts":message.segments.unwrap_or(1),
                "MessageCoding":if message.encoding.as_deref() == Some("UCS-2") { "Unicode" } else { "GSM" },
                "ContactId":null,"Originator":message.from,"Msisdn":message.to.trim_start_matches('+'),"SenderType":"AlphanumericSenderId",
                "Metadata":message.meta.get("recipientMetaData").cloned().unwrap_or(json!({}))
            }]
        });
        let mut headers = vec![("Content-Type".into(), "application/json".into())];
        if let Some(header) = &self.callback.header {
            headers.push(header.clone());
        }
        Some(Callback {
            url,
            headers,
            body: payload.to_string(),
        })
    }

    fn failure(&self, scenario: Scenario) -> Response {
        match scenario {
            Scenario::InvalidNumber => error(
                "msisdn_invalid",
                "Recipient msisdn must be in E.164 format.",
                StatusCode::BAD_REQUEST,
            ),
            Scenario::Unauthorized => unauthorized("/v2/messages"),
            Scenario::ServerError => error(
                "internal_error",
                "An unexpected error occurred.",
                StatusCode::INTERNAL_SERVER_ERROR,
            ),
            Scenario::RateLimited => {
                let mut response = error(
                    "rate_limit_exceeded",
                    "Too many requests. Retry after 5 seconds.",
                    StatusCode::TOO_MANY_REQUESTS,
                );
                response
                    .headers_mut()
                    .insert("retry-after", "5".parse().unwrap());
                response
            }
        }
    }
}
