use hickory_resolver::config::{ConnectionConfig, NameServerConfig, ResolverConfig, ResolverOpts};
use mail_auth::MessageAuthenticator;
use msgpit_server::{authentication, mail, network::Network};
use serde_json::Value;
use std::{sync::Arc, time::Duration};
use tokio::net::UdpSocket;

fn name(value: &str) -> Vec<u8> {
    let mut out = Vec::new();
    for label in value.split('.') {
        out.push(label.len() as u8);
        out.extend(label.as_bytes());
    }
    out.push(0);
    out
}
fn txt(value: &str) -> Vec<u8> {
    let mut out = Vec::new();
    for chunk in value.as_bytes().chunks(255) {
        out.push(chunk.len() as u8);
        out.extend(chunk);
    }
    out
}

async fn fixture_dns(key: Option<String>) -> (Network, tokio::task::JoinHandle<()>) {
    let socket = UdpSocket::bind("127.0.0.1:0").await.unwrap();
    let endpoint = socket.local_addr().unwrap();
    let task = tokio::spawn(async move {
        let mut buf = [0; 4096];
        loop {
            let (length, peer) = socket.recv_from(&mut buf).await.unwrap();
            let query = &buf[..length];
            let mut end = 12;
            let mut labels = Vec::new();
            while query[end] != 0 {
                let count = query[end] as usize;
                end += 1;
                labels.push(String::from_utf8_lossy(&query[end..end + count]).to_lowercase());
                end += count;
            }
            end += 1;
            let kind = u16::from_be_bytes([query[end], query[end + 1]]);
            let domain = labels.join(".");
            end += 4;
            let data = match (domain.as_str(), kind) {
                ("example.test", 16) => Some(txt("v=spf1 include:allowed.example.test -all")),
                ("allowed.example.test", 16) => Some(txt("v=spf1 ip4:8.8.8.8 -all")),
                ("_dmarc.example.test", 16) => Some(txt("v=DMARC1; p=reject")),
                ("8.8.8.8.in-addr.arpa", 12) => Some(name("mail.example.test")),
                ("mail.example.test", 1) => Some(vec![8, 8, 8, 8]),
                ("msgpit._domainkey.example.test", 16) => key.as_deref().map(txt),
                _ => None,
            };
            let mut response = query[..end].to_vec();
            response[2..12].copy_from_slice(&[
                0x81,
                0x80,
                0,
                1,
                0,
                u8::from(data.is_some()),
                0,
                0,
                0,
                0,
            ]);
            if let Some(data) = data {
                response.extend([0xc0, 0x0c]);
                response.extend(kind.to_be_bytes());
                response.extend([0, 1, 0, 0, 0, 60]);
                response.extend((data.len() as u16).to_be_bytes());
                response.extend(data);
            }
            socket.send_to(&response, peer).await.unwrap();
        }
    });
    let mut connection = ConnectionConfig::udp();
    connection.port = endpoint.port();
    let config = ResolverConfig::from_name_servers(vec![NameServerConfig::new(
        endpoint.ip(),
        true,
        vec![connection],
    )]);
    let mut options = ResolverOpts::default();
    options.timeout = Duration::from_millis(500);
    options.attempts = 1;
    let mut network = Network::new(false, None).unwrap();
    network.auth = Some(Arc::new(
        MessageAuthenticator::new(config, options).unwrap(),
    ));
    (network, task)
}

#[tokio::test]
async fn network_report_evaluates_spf_includes_dmarc_reverse_dns_and_caches_lookups() {
    let (network, task) = fixture_dns(None).await;
    let raw = b"From: Bas <bas@example.test>\r\nTo: reader@example.test\r\nReceived-SPF: pass; client-ip=8.8.8.8; helo=mail.example.test\r\n\r\nHi";
    let detail = mail::capture(raw, None, None).unwrap().remove(0).0;
    let (report, lookups) = authentication::check(&detail, &network).await;
    for id in ["spf", "dmarc", "reverse-dns", "blocklists"] {
        assert_eq!(
            report.findings.iter().find(|f| f.id == id).unwrap().status,
            "pass",
            "{id}"
        );
    }
    assert_eq!(report.findings.len(), 20);
    assert!(lookups > 16);
    assert_eq!(authentication::check(&detail, &network).await.1, 0);
    let failed = mail::capture(
        &String::from_utf8_lossy(raw)
            .replace("8.8.8.8", "8.8.4.4")
            .into_bytes(),
        None,
        None,
    )
    .unwrap()
    .remove(0)
    .0;
    let result = authentication::check(&failed, &network).await.0;
    assert_eq!(
        result
            .findings
            .iter()
            .find(|f| f.id == "spf")
            .unwrap()
            .status,
        "fail"
    );
    task.abort();
}

#[tokio::test]
async fn dkim_verifies_upstream_signatures_and_detects_tampered_body_and_missing_key() {
    // Signed with the preserved upstream canonicalizer; no private key is retained.
    let fixture: Value = serde_json::from_str(include_str!("fixtures/dkim-golden.json")).unwrap();
    let (network, task) = fixture_dns(Some(fixture["key"].as_str().unwrap().into())).await;
    for signed in fixture["messages"].as_array().unwrap() {
        let raw = signed["raw"].as_str().unwrap();
        let detail = mail::capture(raw.as_bytes(), None, None)
            .unwrap()
            .remove(0)
            .0;
        let report = authentication::check(&detail, &network).await.0;
        let dkim = report.findings.iter().find(|f| f.id == "dkim").unwrap();
        assert_eq!(
            dkim.status, "pass",
            "{}: {:?}",
            signed["canonicalization"], dkim.evidence
        );
        let changed = mail::capture(raw.replace("de body.", "de bodz.").as_bytes(), None, None)
            .unwrap()
            .remove(0)
            .0;
        assert_eq!(
            authentication::check(&changed, &network)
                .await
                .0
                .findings
                .iter()
                .find(|f| f.id == "dkim")
                .unwrap()
                .status,
            "fail"
        );
    }
    task.abort();
    let (network, task) = fixture_dns(None).await;
    let detail = mail::capture(
        fixture["messages"][0]["raw"].as_str().unwrap().as_bytes(),
        None,
        None,
    )
    .unwrap()
    .remove(0)
    .0;
    assert_eq!(
        authentication::check(&detail, &network)
            .await
            .0
            .findings
            .iter()
            .find(|f| f.id == "dkim")
            .unwrap()
            .status,
        "skip"
    );
    task.abort();
}

#[test]
fn private_origins_and_policy_blocklist_codes_are_classified_without_false_accusations() {
    for ip in [
        "127.0.0.1",
        "10.0.0.1",
        "169.254.1.2",
        "100.64.1.1",
        "::1",
        "fc00::1",
    ] {
        let raw =
            format!("From: a@example.test\r\nReceived-SPF: pass; client-ip={ip}\r\n\r\nHello");
        assert!(
            authentication::origin(
                &mail::capture(raw.as_bytes(), None, None)
                    .unwrap()
                    .remove(0)
                    .0
            )
            .ip
            .is_none(),
            "{ip}"
        );
    }
    for (zone, code, verdict) in [
        ("zen.spamhaus.org", "127.0.0.10", "caution"),
        ("zen.spamhaus.org", "127.255.255.254", "refused"),
        ("zen.spamhaus.org", "127.0.0.2", "listed"),
        ("hostkarma.junkemailfilter.com", "127.0.0.1", "good"),
        ("bl.mailspike.net", "127.0.0.18", "good"),
    ] {
        assert_eq!(
            authentication::blocklist_verdict(zone, &[code.into()]),
            verdict
        );
    }
}
