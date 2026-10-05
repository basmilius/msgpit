pub mod spryng;

use crate::model::{Message, Scenario};
use axum::{
    http::{HeaderMap, Method},
    response::Response,
};

pub struct ProviderRequest<'a> {
    pub method: &'a Method,
    pub path: &'a str,
    pub headers: &'a HeaderMap,
    pub body: &'a [u8],
}

#[derive(Clone, Default)]
pub struct CallbackConfig {
    pub url: Option<String>,
    pub header: Option<(String, String)>,
}

pub struct Callback {
    pub url: String,
    pub headers: Vec<(String, String)>,
    pub body: String,
}

pub struct Capture {
    pub messages: Vec<Message>,
    pub response: Response,
}

pub trait Provider: Send + Sync {
    fn id(&self) -> &'static str;
    fn channels(&self) -> &'static [&'static str];
    fn translate(&self, request: &ProviderRequest<'_>) -> Capture;
    fn failure(&self, scenario: Scenario) -> Response;
    fn callback(&self, _message: &Message, _status: &str) -> Option<Callback> {
        None
    }
    fn supports_delivery_reports(&self) -> bool {
        false
    }
}
