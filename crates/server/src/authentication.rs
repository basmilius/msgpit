use crate::{
    html,
    model::Detail,
    network::Network,
    report::{Finding, Report, domain, header},
};
use mail_auth::{AuthenticatedMessage, DkimResult, SpfResult, spf::verify::SpfParameters};
use std::net::IpAddr;

#[derive(Default, Debug, PartialEq)]
pub struct Origin {
    pub ip: Option<IpAddr>,
    pub helo: Option<String>,
}

pub fn origin(detail: &Detail) -> Origin {
    if let Some(spf) = header(detail, "received-spf")
        && let Some(ip) = html::regex(r"(?i)client-ip=([^;\s]+)")
            .captures(&spf)
            .and_then(|c| public_ip(&c[1]))
    {
        return Origin {
            ip: Some(ip),
            helo: html::regex(r"(?i)helo=([^;\s]+)")
                .captures(&spf)
                .map(|c| c[1].into()),
        };
    }
    for received in detail
        .header_list
        .iter()
        .filter(|h| h.name.eq_ignore_ascii_case("received"))
    {
        for c in
            html::regex(r"(?i)(?:\d{1,3}\.){3}\d{1,3}|[0-9a-f:]{6,}").find_iter(&received.value)
        {
            if let Some(ip) = public_ip(c.as_str()) {
                return Origin {
                    ip: Some(ip),
                    helo: html::regex(r"(?i)^\s*from\s+([^\s(]+)")
                        .captures(&received.value)
                        .map(|c| c[1].into()),
                };
            }
        }
    }
    Origin::default()
}

fn public_ip(value: &str) -> Option<IpAddr> {
    let ip = value
        .trim_matches(['[', ']', '(', ')', ' '])
        .parse::<IpAddr>()
        .ok()?;
    let public = match ip {
        IpAddr::V4(v) => {
            !v.is_private()
                && !v.is_loopback()
                && !v.is_link_local()
                && !v.is_unspecified()
                && !v.is_multicast()
                && !v.is_broadcast()
                && v.octets()[0] < 240
                && !(v.octets()[0] == 100 && (64..128).contains(&v.octets()[1]))
        }
        IpAddr::V6(v) => {
            !v.is_loopback()
                && !v.is_unspecified()
                && !v.is_multicast()
                && (v.segments()[0] & 0xfe00) != 0xfc00
                && (v.segments()[0] & 0xffc0) != 0xfe80
                && v.to_ipv4_mapped()
                    .is_none_or(|v| public_ip(&v.to_string()).is_some())
        }
    };
    public.then_some(ip)
}

fn finding(
    id: &str,
    title: impl Into<String>,
    status: &str,
    penalty: f64,
    explanation: impl Into<String>,
    evidence: Vec<String>,
) -> Finding {
    Finding::new(
        id,
        if id == "blocklists" {
            "reputation"
        } else {
            "authentication"
        },
        title,
        status,
        penalty,
        explanation,
        evidence,
    )
}
fn unavailable(id: &str, title: &str, reason: impl Into<String>) -> Finding {
    finding(id, title, "skip", 0., reason, vec![])
}

pub async fn check(detail: &Detail, network: &Network) -> (Report, usize) {
    check_with_raw(detail, network, detail.raw_request.as_bytes()).await
}

pub async fn check_with_raw(detail: &Detail, network: &Network, raw: &[u8]) -> (Report, usize) {
    let mut lookups = 0;
    let origin = origin(detail);
    let domain = header(detail, "from").and_then(|s| domain(&s));
    let mut findings = Vec::new();
    if let Some(auth) = &network.auth {
        let spf = if let Some(domain) = &domain {
            match network.dns("TXT", domain, &mut lookups).await {
                Ok(records) => {
                    let record = records
                        .into_iter()
                        .find(|s| s.trim_start().to_lowercase().starts_with("v=spf1"));
                    let evidence = record
                        .as_ref()
                        .map(|s| vec![format!("{domain}: {s}")])
                        .unwrap_or_default();
                    if let Some(ip) = origin.ip {
                        let helo = origin.helo.as_deref().unwrap_or(domain);
                        let sender = format!("postmaster@{domain}");
                        let output = auth
                            .verify_spf(SpfParameters::verify_mail_from(
                                ip, helo, "msgpit", &sender,
                            ))
                            .await;
                        let (status, penalty) = match output.result() {
                            SpfResult::Pass => ("pass", 0.),
                            SpfResult::Fail => ("fail", 2.),
                            SpfResult::SoftFail => ("warn", 1.),
                            SpfResult::Neutral => ("warn", 0.5),
                            SpfResult::None | SpfResult::PermError => ("fail", 1.5),
                            SpfResult::TempError => ("skip", 0.),
                        };
                        let mut evidence = evidence;
                        evidence.insert(0, format!("Sending address: {ip}"));
                        finding("spf",format!("SPF {} for {ip}",output.result()),status,penalty,output.explanation().unwrap_or("Evaluated against the sending address, with the SPF DNS and recursion limits."),evidence)
                    } else if record.is_some() {
                        finding(
                            "spf",
                            format!("{domain} publishes an SPF record"),
                            "pass",
                            0.,
                            "This message named no sending address, so the record is reported rather than evaluated.",
                            evidence,
                        )
                    } else {
                        finding(
                            "spf",
                            format!("{domain} publishes no SPF record"),
                            "fail",
                            1.5,
                            "DMARC then has only DKIM to identify the sender.",
                            evidence,
                        )
                    }
                }
                Err(error) => unavailable("spf", "SPF", error.to_string()),
            }
        } else {
            unavailable("spf", "SPF", "The message has no sender domain to look up.")
        };
        findings.push(spf);
        if header(detail, "dkim-signature").is_none() {
            findings.push(finding(
                "dkim",
                "The message carries no DKIM signature",
                "warn",
                1.,
                "Without one DMARC has only SPF to lean on, and SPF does not survive forwarding.",
                vec![],
            ));
        } else if header(detail, "authentication-results").is_some() {
            findings.push(unavailable("dkim","DKIM signature, verified here","The receiving server already judged this signature, and it had the key as it was at the time."));
        } else if let Some(message) = AuthenticatedMessage::parse(raw) {
            let outputs = auth.verify_dkim(&message).await;
            let evidence = outputs
                .iter()
                .map(|o| {
                    format!(
                        "{} ({}): {:?}",
                        o.signature()
                            .map(|s| s.d.as_str())
                            .unwrap_or("(unknown domain)"),
                        o.signature()
                            .map(|s| s.s.as_str())
                            .unwrap_or("(unknown selector)"),
                        o.result()
                    )
                })
                .collect::<Vec<_>>();
            let pass = outputs.iter().any(|o| *o.result() == DkimResult::Pass);
            let temporary = outputs.iter().all(|o| {
                matches!(
                    o.result(),
                    DkimResult::TempError(_)
                        | DkimResult::None
                        | DkimResult::PermError(mail_auth::Error::Dns(
                            mail_auth::DnsError::RecordNotFound(_)
                        ))
                )
            });
            findings.push(finding("dkim",if pass{"The DKIM signature verifies"}else{"The DKIM signature does not verify"},if pass{"pass"}else if temporary{"skip"}else{"fail"},if pass||temporary{0.}else{1.5},"An exported .eml may no longer contain the exact signed bytes. Body hash mismatches are reported separately in the evidence.",evidence));
        } else {
            findings.push(finding(
                "dkim",
                "The signature could not be read",
                "warn",
                1.,
                "The captured message could not be parsed for signature verification.",
                vec![],
            ));
        }
        if let Some(domain) = &domain {
            findings.push(dmarc(domain, network, &mut lookups).await);
        } else {
            findings.push(unavailable(
                "dmarc",
                "DMARC policy",
                "The message has no sender domain to look up.",
            ));
        }
        if let Some(ip) = origin.ip {
            let reverse = reverse_name(ip);
            let names = network.dns("PTR", &reverse, &mut lookups).await;
            let reverse_finding = match names {
                Ok(names) if names.is_empty() => finding(
                    "reverse-dns",
                    format!("The sending address {ip} has no reverse DNS"),
                    "fail",
                    1.,
                    "Receiving servers hold this against a sender before reading anything else.",
                    vec![],
                ),
                Ok(names) => {
                    let name = &names[0];
                    match network.addresses(name, &mut lookups).await {
                        Ok(addresses) => {
                            let confirmed = addresses
                                .iter()
                                .any(|s| s.parse::<IpAddr>().ok() == Some(ip));
                            finding(
                                "reverse-dns",
                                if confirmed {
                                    format!("The sending address is {name}, confirmed both ways")
                                } else {
                                    format!("The reverse name {name} does not point back")
                                },
                                if confirmed { "pass" } else { "warn" },
                                if confirmed { 0. } else { 0.5 },
                                "Forward-confirmed reverse DNS needs the name to resolve to the address again.",
                                vec![
                                    format!("{ip} resolves to {name}"),
                                    format!("{name} resolves to {}", addresses.join(", ")),
                                ],
                            )
                        }
                        Err(error) => unavailable("reverse-dns", "Reverse DNS", error.to_string()),
                    }
                }
                Err(error) => unavailable("reverse-dns", "Reverse DNS", error.to_string()),
            };
            findings.push(reverse_finding);
            findings.push(blocklists(ip, network, &mut lookups).await);
        } else {
            for (id, title) in [("reverse-dns", "Reverse DNS"), ("blocklists", "Blocklists")] {
                findings.push(unavailable(
                    id,
                    title,
                    "This message names no sending address, because it never crossed a network.",
                ));
            }
        }
    } else {
        for (id, title) in [
            ("spf", "SPF"),
            ("dkim", "DKIM signature"),
            ("dmarc", "DMARC policy"),
            ("reverse-dns", "Reverse DNS"),
            ("blocklists", "Blocklists"),
        ] {
            findings.push(unavailable(id, title, "DNS lookups are switched off."));
        }
    }
    let mut offline = crate::report::build(detail).findings;
    offline.splice(2..2, findings);
    (Report::new(offline), lookups)
}

async fn dmarc(domain: &str, network: &Network, lookups: &mut usize) -> Finding {
    let mut current = domain.to_string();
    loop {
        match network
            .dns("TXT", &format!("_dmarc.{current}"), lookups)
            .await
        {
            Ok(records) => {
                if let Some(record) = records
                    .into_iter()
                    .find(|r| r.trim_start().to_uppercase().starts_with("V=DMARC1"))
                {
                    let tags = record
                        .split(';')
                        .filter_map(|part| part.trim().split_once('='))
                        .map(|(k, v)| (k.trim().to_lowercase(), v.trim().to_string()))
                        .collect::<std::collections::HashMap<_, _>>();
                    let policy = if current != domain {
                        tags.get("sp").or_else(|| tags.get("p"))
                    } else {
                        tags.get("p")
                    };
                    let Some(policy) =
                        policy.filter(|p| matches!(p.as_str(), "none" | "quarantine" | "reject"))
                    else {
                        return finding(
                            "dmarc",
                            format!("{domain} publishes an invalid DMARC policy"),
                            "fail",
                            1.5,
                            "The record has no valid p= policy.",
                            vec![record],
                        );
                    };
                    let mut evidence = vec![format!("_dmarc.{current}: {record}")];
                    if let Some(pct) = tags.get("pct").filter(|s| s.as_str() != "100") {
                        evidence.push(format!("Applied to {pct}% of failing mail"));
                    }
                    return finding(
                        "dmarc",
                        if policy == "none" {
                            format!("{domain} publishes DMARC, but the policy is p=none")
                        } else {
                            format!("{domain} publishes DMARC with p={policy}")
                        },
                        if policy == "none" { "warn" } else { "pass" },
                        if policy == "none" { 0.5 } else { 0. },
                        "The domain's published policy for messages that fail authentication.",
                        evidence,
                    );
                }
            }
            Err(error) => return unavailable("dmarc", "DMARC policy", error.to_string()),
        }
        if current.split('.').count() <= 2 {
            break;
        }
        current = current.split_once('.').unwrap().1.to_string();
    }
    finding(
        "dmarc",
        format!("{domain} publishes no DMARC record"),
        "fail",
        1.5,
        "No published policy tells recipients what to do with mail that fails authentication.",
        vec![format!("No v=DMARC1 record at _dmarc.{domain} or above it")],
    )
}

fn reverse_name(ip: IpAddr) -> String {
    match ip {
        IpAddr::V4(ip) => format!(
            "{}.in-addr.arpa",
            ip.octets()
                .iter()
                .rev()
                .map(u8::to_string)
                .collect::<Vec<_>>()
                .join(".")
        ),
        IpAddr::V6(ip) => format!(
            "{}.ip6.arpa",
            format!("{:032x}", u128::from(ip))
                .chars()
                .rev()
                .map(|c| c.to_string())
                .collect::<Vec<_>>()
                .join(".")
        ),
    }
}

const ZONES: [(&str, &str); 16] = [
    ("zen.spamhaus.org", "Spamhaus"),
    ("b.barracudacentral.org", "Barracuda"),
    ("bl.spamcop.net", "SpamCop"),
    ("psbl.surriel.com", "PSBL"),
    ("db.wpbl.info", "WPBL"),
    ("bl.mailspike.net", "Mailspike"),
    ("hostkarma.junkemailfilter.com", "Hostkarma"),
    ("dnsbl-1.uceprotect.net", "UCEPROTECT"),
    ("truncate.gbudb.net", "GBUdb Truncate"),
    ("bl.blocklist.de", "blocklist.de"),
    ("all.s5h.net", "s5h"),
    ("dnsbl.dronebl.org", "DroneBL"),
    ("spam.dnsbl.anonmails.de", "Anonmails"),
    ("ips.backscatterer.org", "Backscatterer"),
    ("rbl.interserver.net", "InterServer"),
    ("spamrbl.imp.ch", "IMP"),
];

pub fn blocklist_verdict(zone: &str, codes: &[String]) -> &'static str {
    if codes.is_empty() {
        return "clean";
    }
    if codes.iter().any(|c| c.starts_with("127.255.255.")) {
        return "refused";
    }
    let last = codes[0]
        .rsplit('.')
        .next()
        .and_then(|s| s.parse::<u8>().ok())
        .unwrap_or(0);
    match zone {
        "hostkarma.junkemailfilter.com" => match last {
            1 | 5 => "good",
            3 => "caution",
            _ => "listed",
        },
        "bl.mailspike.net" => {
            if last >= 17 {
                "good"
            } else if last >= 11 {
                "caution"
            } else {
                "listed"
            }
        }
        "zen.spamhaus.org" if matches!(last, 10 | 11) => "caution",
        _ => "listed",
    }
}

async fn blocklists(ip: IpAddr, network: &Network, lookups: &mut usize) -> Finding {
    let IpAddr::V4(ip) = ip else {
        return unavailable(
            "blocklists",
            "Blocklists",
            format!("The sending address {ip} is IPv6, and these lists answer on IPv4 only."),
        );
    };
    let reversed = ip
        .octets()
        .iter()
        .rev()
        .map(u8::to_string)
        .collect::<Vec<_>>()
        .join(".");
    let mut results = Vec::new();
    let mut listed = Vec::new();
    let mut caution = 0;
    let mut checked = 0;
    let mut queries = tokio::task::JoinSet::new();
    for (index, (zone, name)) in ZONES.into_iter().enumerate() {
        let network = network.clone();
        let query = format!("{reversed}.{zone}");
        queries.spawn(async move {
            let mut count = 0;
            let codes = network.dns("A", &query, &mut count).await;
            (index, zone, name, codes, count)
        });
    }
    let mut answers = Vec::new();
    while let Some(Ok(answer)) = queries.join_next().await {
        answers.push(answer);
    }
    answers.sort_by_key(|(index, _, _, _, _)| *index);
    for (_, zone, name, codes, count) in answers {
        *lookups += count;
        let (weight, evidence) = match codes {
            Ok(codes) => {
                let verdict = blocklist_verdict(zone, &codes);
                if verdict != "refused" {
                    checked += 1;
                }
                if verdict == "listed" {
                    listed.push(name);
                }
                if verdict == "caution" {
                    caution += 1;
                }
                match verdict {
                    "clean" => (0, format!("Not listed in {name}")),
                    "good" => (
                        1,
                        format!(
                            "{name} knows this address as a good one ({})",
                            codes.join(", ")
                        ),
                    ),
                    "caution" => (
                        3,
                        format!(
                            "Listed in {name} on a policy list, not as a spam source ({})",
                            codes.join(", ")
                        ),
                    ),
                    "listed" => (4, format!("Listed in {name} ({})", codes.join(", "))),
                    _ => (
                        2,
                        format!(
                            "{name} declined to answer, which it does for queries through a public resolver"
                        ),
                    ),
                }
            }
            Err(error) => (2, format!("{name} could not be checked: {error}")),
        };
        results.push((weight, evidence));
    }
    results.sort_by_key(|(weight, _)| std::cmp::Reverse(*weight));
    let evidence = results.into_iter().map(|(_, e)| e).collect();
    if !listed.is_empty() {
        finding(
            "blocklists",
            format!("The sending address is listed in {}", listed.join(", ")),
            "fail",
            (1.5 * listed.len() as f64).min(3.),
            "Servers consulting these lists may refuse the message before reading it.",
            evidence,
        )
    } else if caution > 0 {
        finding(
            "blocklists",
            "The sending address is on a policy list",
            "warn",
            0.5,
            "Policy lists mark addresses that should not send mail directly; this is not an accusation.",
            evidence,
        )
    } else if checked == 0 {
        unavailable(
            "blocklists",
            "Blocklists",
            "Every list declined or could not answer. Queries through public resolvers may be refused.",
        )
    } else {
        finding(
            "blocklists",
            format!("The sending address is on none of the {checked} blocklists checked"),
            "pass",
            0.,
            "",
            evidence,
        )
    }
}
