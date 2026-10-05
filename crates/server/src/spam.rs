use regex::Regex;
use serde_json::{Value, json};
use std::time::Duration;
use tokio::{
    io::{AsyncBufReadExt, AsyncReadExt, AsyncWriteExt, BufReader},
    net::TcpStream,
};

pub const LOCAL_ENDPOINT: &str = "127.0.0.1:783";

#[derive(Debug, PartialEq)]
pub enum Config {
    Disabled,
    Local,
    External(String),
}

impl Config {
    pub fn parse(value: &str) -> Self {
        let value = value.trim();
        match value.to_ascii_lowercase().as_str() {
            "" | "off" | "0" | "false" | "no" => Self::Disabled,
            "local" => Self::Local,
            _ => Self::External(value.into()),
        }
    }

    pub fn from_env() -> Self {
        Self::parse(&std::env::var("MSGPIT_SPAMASSASSIN").unwrap_or_default())
    }

    pub fn endpoint(&self) -> Option<&str> {
        match self {
            Self::Disabled => None,
            Self::Local => Some(LOCAL_ENDPOINT),
            Self::External(address) => Some(address),
        }
    }
}

pub async fn ping(address: &str) -> anyhow::Result<()> {
    tokio::time::timeout(Duration::from_secs(1), async {
        let mut socket = TcpStream::connect(address).await?;
        socket.write_all(b"PING SPAMC/1.5\r\n\r\n").await?;
        let mut response = String::new();
        BufReader::new(socket)
            .take(256)
            .read_line(&mut response)
            .await?;
        let fields: Vec<_> = response.split_whitespace().collect();
        anyhow::ensure!(
            fields.len() == 3
                && fields[0].starts_with("SPAMD/")
                && fields[1] == "0"
                && fields[2] == "PONG",
            "SpamAssassin healthcheck failed"
        );
        Ok(())
    })
    .await?
}

pub async fn check(address: Option<&str>, raw: &[u8]) -> Option<Value> {
    let address = address?;
    let endpoint = if address.contains(':') {
        address.into()
    } else {
        format!("{address}:783")
    };
    tokio::time::timeout(Duration::from_secs(10), async {
        let mut socket = TcpStream::connect(endpoint).await.ok()?;
        let mut normalized = Vec::with_capacity(raw.len());
        for (index, byte) in raw.iter().copied().enumerate() {
            if byte == b'\r' && raw.get(index + 1) == Some(&b'\n') {
                continue;
            }
            if byte == b'\n' {
                normalized.extend_from_slice(b"\r\n");
            } else {
                normalized.push(byte);
            }
        }
        socket
            .write_all(
                format!(
                    "REPORT SPAMC/1.5\r\nContent-length: {}\r\n\r\n",
                    normalized.len()
                )
                .as_bytes(),
            )
            .await
            .ok()?;
        socket.write_all(&normalized).await.ok()?;
        let mut response = Vec::new();
        socket
            .take(1024 * 1024)
            .read_to_end(&mut response)
            .await
            .ok()?;
        parse(&String::from_utf8_lossy(&response))
    })
    .await
    .ok()
    .flatten()
}

pub fn parse(response: &str) -> Option<Value> {
    let pattern =
        Regex::new(r"(?im)^Spam:\s*(True|False)\s*;\s*(-?[\d.]+)\s*/\s*(-?[\d.]+)").unwrap();
    let captures = pattern.captures(response)?;
    let row = Regex::new(r"^\s*(-?[\d.]+)\s+(\S+)\s+(.*)$").unwrap();
    let divider = Regex::new(r"^\s*-+\s+-+\s+-+").unwrap();
    let mut in_table = false;
    let mut rules: Vec<Value> = vec![];
    for line in response.lines() {
        if divider.is_match(line) {
            in_table = true;
            continue;
        }
        if !in_table {
            continue;
        }
        if let Some(c) = row.captures(line) {
            rules.push(
                json!({"points":c[1].parse::<f64>().ok()?,"name":&c[2],"description":c[3].trim()}),
            );
        } else if line.starts_with("    ")
            && !line.trim().is_empty()
            && let Some(last) = rules.last_mut()
        {
            last["description"] = json!(format!(
                "{} {}",
                last["description"].as_str().unwrap(),
                line.trim()
            ));
        }
    }
    Some(
        json!({"spam":captures[1].eq_ignore_ascii_case("true"),"score":captures[2].parse::<f64>().ok()?,"threshold":captures[3].parse::<f64>().ok()?,"rules":rules,"report":response.split_once("\r\n\r\n").map(|(_, b)| b).unwrap_or(response).trim()}),
    )
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn modes_share_one_endpoint_for_smtp_and_import() {
        for value in ["", "off", "0", " false ", "NO"] {
            assert_eq!(Config::parse(value).endpoint(), None);
        }
        assert_eq!(Config::parse(" Local "), Config::Local);
        assert_eq!(Config::Local.endpoint(), Some(LOCAL_ENDPOINT));
        assert_eq!(Config::parse("filter:783").endpoint(), Some("filter:783"));
    }

    #[tokio::test]
    async fn healthcheck_requires_a_successful_spamd_pong() {
        for (response, healthy) in [
            ("SPAMD/1.5 0 PONG\r\n", true),
            ("SPAMD/1.5 76 EX_PROTOCOL\r\n", false),
            ("HTTP/1.1 200 OK\r\n", false),
        ] {
            let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
            let address = listener.local_addr().unwrap().to_string();
            let task = tokio::spawn(async move {
                let (mut socket, _) = listener.accept().await.unwrap();
                let mut request = [0; 18];
                socket.read_exact(&mut request).await.unwrap();
                assert_eq!(&request, b"PING SPAMC/1.5\r\n\r\n");
                socket.write_all(response.as_bytes()).await.unwrap();
            });
            assert_eq!(ping(&address).await.is_ok(), healthy);
            task.await.unwrap();
        }
    }
}
