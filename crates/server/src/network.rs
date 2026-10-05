use crate::providers::Callback;
use anyhow::{Context, Result};
use mail_auth::MessageAuthenticator;
use reqwest::{Client, Method, Url};
use serde_json::{Value, json};
use std::{
    collections::HashMap,
    net::{IpAddr, SocketAddr},
    sync::{Arc, Mutex},
    time::{Duration, Instant},
};

type DnsCache = Arc<Mutex<HashMap<String, (Instant, Vec<String>)>>>;

#[derive(Clone)]
pub struct Network {
    pub auth: Option<Arc<MessageAuthenticator>>,
    pub spamd: Option<String>,
    client: Client,
    cache: DnsCache,
}

impl Network {
    pub fn new(dns: bool, spamd: Option<String>) -> Result<Self> {
        let auth = if dns {
            let (config, mut opts) = hickory_resolver::system_conf::read_system_conf()?;
            opts.timeout = Duration::from_secs(3);
            opts.attempts = 1;
            Some(Arc::new(MessageAuthenticator::new(config, opts)?))
        } else {
            None
        };
        Ok(Self {
            auth,
            spamd,
            client: Client::builder()
                .no_proxy()
                .timeout(Duration::from_secs(5))
                .redirect(reqwest::redirect::Policy::none())
                .build()?,
            cache: Arc::new(Mutex::new(HashMap::new())),
        })
    }

    pub fn from_env() -> Result<Self> {
        let enabled = !matches!(
            std::env::var("MSGPIT_DNS")
                .unwrap_or_default()
                .to_lowercase()
                .as_str(),
            "off" | "0" | "false" | "no"
        );
        Self::new(
            enabled,
            crate::spam::Config::from_env()
                .endpoint()
                .map(str::to_owned),
        )
    }

    pub async fn callback(&self, callback: &Callback) -> (Option<u16>, String) {
        let mut request = self.client.post(&callback.url).body(callback.body.clone());
        for (name, value) in &callback.headers {
            request = request.header(name, value);
        }
        match request.send().await {
            Ok(mut response) => {
                let status = response.status().as_u16();
                let mut body = Vec::new();
                while let Ok(Some(chunk)) = response.chunk().await {
                    let remaining = (1024 * 1024_usize).saturating_sub(body.len());
                    body.extend_from_slice(&chunk[..chunk.len().min(remaining)]);
                    if body.len() >= 1024 * 1024 {
                        break;
                    }
                }
                (Some(status), String::from_utf8_lossy(&body).into_owned())
            }
            Err(_) => (None, "Callback could not be delivered.".into()),
        }
    }

    pub async fn dns(&self, kind: &str, name: &str, lookups: &mut usize) -> Result<Vec<String>> {
        let Some(auth) = &self.auth else {
            return Ok(vec![]);
        };
        let key = format!("{kind}:{}", name.to_lowercase().trim_end_matches('.'));
        if let Some((expires, values)) = self.cache.lock().unwrap().get(&key)
            && *expires > Instant::now()
        {
            return Ok(values.clone());
        }
        *lookups += 1;
        use hickory_resolver::proto::rr::{RData, RecordType};
        let record_type = match kind {
            "TXT" => RecordType::TXT,
            "A" => RecordType::A,
            "AAAA" => RecordType::AAAA,
            "PTR" => RecordType::PTR,
            _ => anyhow::bail!("Unknown DNS type"),
        };
        let (values, ttl) = match auth.resolver().lookup(name, record_type).await {
            Ok(answer) => {
                let mut values = Vec::new();
                let mut ttl = 3600;
                for record in answer.answers() {
                    ttl = ttl.min(record.ttl);
                    match &record.data {
                        RData::TXT(txt) => values.push(
                            String::from_utf8_lossy(
                                &txt.txt_data
                                    .iter()
                                    .flat_map(|s| s.iter().copied())
                                    .collect::<Vec<_>>(),
                            )
                            .into_owned(),
                        ),
                        RData::A(ip) => values.push(ip.0.to_string()),
                        RData::AAAA(ip) => values.push(ip.0.to_string()),
                        RData::PTR(name) => {
                            values.push(name.0.to_string().trim_end_matches('.').into())
                        }
                        _ => {}
                    }
                }
                (values, ttl)
            }
            Err(error) if error.is_no_records_found() => (vec![], 60),
            Err(error) => return Err(error.into()),
        };
        let mut cache = self.cache.lock().unwrap();
        cache.retain(|_, (expires, _)| *expires > Instant::now());
        if cache.len() > 8192 {
            cache.clear();
        }
        cache.insert(
            key,
            (
                Instant::now() + Duration::from_secs(u64::from(ttl.clamp(1, 3600))),
                values.clone(),
            ),
        );
        Ok(values)
    }

    pub async fn addresses(&self, name: &str, lookups: &mut usize) -> Result<Vec<String>> {
        let mut values = self.dns("A", name, lookups).await?;
        values.extend(self.dns("AAAA", name, lookups).await?);
        Ok(values)
    }

    pub async fn check_links(&self, links: &[Value]) -> Vec<Value> {
        let mut tasks = tokio::task::JoinSet::new();
        let permits = Arc::new(tokio::sync::Semaphore::new(8));
        for (index, link) in links.iter().take(50).enumerate() {
            let network = self.clone();
            let link = link.clone();
            let permits = permits.clone();
            tasks.spawn(async move {
                let _permit = permits.acquire().await.expect("Link semaphore open");
                let mut result = link;
                let outcome = network
                    .probe(result["url"].as_str().unwrap_or_default())
                    .await;
                for (key, value) in outcome.as_object().unwrap() {
                    result[key] = value.clone();
                }
                (index, result)
            });
        }
        let mut results = Vec::new();
        while let Some(Ok(result)) = tasks.join_next().await {
            results.push(result);
        }
        results.sort_by_key(|(index, _)| *index);
        results.into_iter().map(|(_, result)| result).collect()
    }

    async fn probe(&self, value: &str) -> Value {
        let mut result = json!({"status":null,"reason":null,"redirect":null});
        let outcome = async {
            let url = Url::parse(value)?;
            anyhow::ensure!(
                matches!(url.scheme(), "http" | "https"),
                "Refused: only http and https are fetched"
            );
            let host = url
                .host_str()
                .context("Refused: no host")?
                .trim_matches(['[', ']']);
            let port = url.port_or_known_default().unwrap_or(80);
            let addresses: Vec<SocketAddr> = tokio::time::timeout(
                Duration::from_secs(3),
                tokio::net::lookup_host((host, port)),
            )
            .await??
            .collect();
            anyhow::ensure!(!addresses.is_empty(), "Could not resolve host");
            anyhow::ensure!(
                !addresses.iter().any(|a| refused_address(a.ip())),
                "Refused: link-local address"
            );
            // Pin the checked addresses so a second DNS answer cannot route the fetch to metadata.
            let client = Client::builder()
                .no_proxy()
                .resolve_to_addrs(host, &addresses)
                .timeout(Duration::from_secs(8))
                .redirect(reqwest::redirect::Policy::none())
                .danger_accept_invalid_certs(true)
                .user_agent("msgpit link check")
                .build()?;
            let first = client.request(Method::HEAD, url.clone()).send().await;
            let response = if first
                .as_ref()
                .map_or(true, |r| matches!(r.status().as_u16(), 405 | 501))
            {
                client.get(url).send().await.or(first)?
            } else {
                first?
            };
            Ok::<_, anyhow::Error>((
                response.status().as_u16(),
                response
                    .headers()
                    .get("location")
                    .and_then(|h| h.to_str().ok())
                    .map(String::from),
            ))
        }
        .await;
        match outcome {
            Ok((status, redirect)) => {
                result["status"] = json!(status);
                result["redirect"] = json!(redirect);
            }
            Err(error) => {
                result["reason"] = json!(error.to_string());
            }
        }
        result
    }
}

pub fn refused_address(ip: IpAddr) -> bool {
    match ip {
        IpAddr::V4(ip) => ip.is_link_local(),
        IpAddr::V6(ip) => ip
            .to_ipv4_mapped()
            .map(|ip| ip.is_link_local())
            .unwrap_or_else(|| {
                let s = ip.segments();
                (s[0] & 0xffc0) == 0xfe80 || s[..4] == [0xfd00, 0xec2, 0, 0]
            }),
    }
}
