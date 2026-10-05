use chrono::Utc;
use serde::{Deserialize, Serialize};
use serde_json::{Value, json};
use uuid::Uuid;

use crate::segments;

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct Message {
    pub id: String,
    pub batch_id: String,
    pub provider: String,
    pub channel: String,
    pub from: Option<String>,
    pub to: String,
    pub body: String,
    pub meta: Value,
    pub provider_ref: Option<String>,
    pub status: String,
    pub encoding: Option<String>,
    pub segments: Option<usize>,
    pub characters: Option<usize>,
    pub units: Option<usize>,
    pub ucs2_offsets: Vec<usize>,
    pub created_at: String,
    pub read_at: Option<String>,
    pub read: bool,
}

impl Message {
    pub fn new(provider: &str, channel: &str, batch_id: &str, to: &str, body: &str) -> Self {
        let analysis = (channel == "sms").then(|| segments::analyze(body));
        Self {
            id: Uuid::new_v4().to_string(),
            batch_id: batch_id.into(),
            provider: provider.into(),
            channel: channel.into(),
            from: None,
            to: to.into(),
            body: body.into(),
            meta: json!({}),
            provider_ref: None,
            status: "accepted".into(),
            encoding: analysis.as_ref().map(|a| a.encoding.into()),
            segments: analysis.as_ref().map(|a| a.segments),
            characters: analysis.as_ref().map(|a| a.characters),
            units: analysis.as_ref().map(|a| a.units),
            ucs2_offsets: analysis.map(|a| a.ucs2_offsets).unwrap_or_default(),
            created_at: now(),
            read_at: None,
            read: false,
        }
    }
}

pub fn now() -> String {
    Utc::now().to_rfc3339_opts(chrono::SecondsFormat::Millis, true)
}

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct Detail {
    #[serde(flatten)]
    pub message: Message,
    pub raw_request: String,
    #[serde(default)]
    pub header_list: Vec<MailHeader>,
    pub delivery_reports: Vec<Value>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub html: Option<String>,
    pub parts: Vec<PartSummary>,
    #[serde(default)]
    pub text: Option<String>,
}

impl Detail {
    pub fn new(message: Message, raw_request: String) -> Self {
        Self {
            message,
            raw_request,
            header_list: vec![],
            delivery_reports: vec![],
            html: None,
            parts: vec![],
            text: None,
        }
    }
}

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct PartSummary {
    pub id: String,
    pub content_type: String,
    pub filename: Option<String>,
    pub content_id: Option<String>,
    pub size: usize,
    #[serde(default = "body_disposition")]
    pub disposition: String,
    #[serde(default)]
    pub charset: Option<String>,
    #[serde(default)]
    pub encoding_problem: bool,
}

pub struct Part {
    pub summary: PartSummary,
    pub content: Vec<u8>,
}

#[derive(Default, Deserialize)]
pub struct Filters {
    pub provider: Option<String>,
    pub channel: Option<String>,
    pub to: Option<String>,
    pub since: Option<String>,
}

#[derive(Clone, Copy, Debug, Deserialize, Serialize)]
pub enum Scenario {
    InvalidNumber,
    Unauthorized,
    RateLimited,
    ServerError,
}

impl Scenario {
    pub const ALL: [Self; 4] = [
        Self::InvalidNumber,
        Self::Unauthorized,
        Self::RateLimited,
        Self::ServerError,
    ];

    pub fn recipient(self) -> &'static str {
        match self {
            Self::InvalidNumber => "+31600000001",
            Self::Unauthorized => "+31600000002",
            Self::RateLimited => "+31600000003",
            Self::ServerError => "+31600000004",
        }
    }

    pub fn for_recipient(recipient: &str) -> Option<Self> {
        let digits: String = recipient.chars().filter(char::is_ascii_digit).collect();
        let normalized = format!("+{}", digits.strip_prefix("00").unwrap_or(&digits));
        Self::ALL.into_iter().find(|s| s.recipient() == normalized)
    }
}

#[derive(Clone, Debug, Serialize, Deserialize)]
pub struct MailHeader {
    pub name: String,
    pub value: String,
}

fn body_disposition() -> String {
    "attachment".into()
}
