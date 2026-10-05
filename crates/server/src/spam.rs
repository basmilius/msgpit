use regex::Regex;
use serde_json::{Value, json};
use std::time::Duration;
use tokio::{
    io::{AsyncReadExt, AsyncWriteExt},
    net::TcpStream,
};

pub async fn check(address: Option<&str>, raw: &[u8]) -> Option<Value> {
    let address = address?;
    let endpoint = if address.contains(':') {
        address.into()
    } else {
        format!("{address}:783")
    };
    tokio::time::timeout(Duration::from_secs(10), async {
        let mut socket = TcpStream::connect(endpoint).await.ok()?;
        let normalized = String::from_utf8_lossy(raw)
            .replace("\r\n", "\n")
            .replace('\n', "\r\n");
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
        socket.write_all(normalized.as_bytes()).await.ok()?;
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
