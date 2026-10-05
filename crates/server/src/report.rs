use crate::{html, mail, model::Detail};
use serde::{Deserialize, Serialize};
use serde_json::{Value, json};

#[derive(Clone, Debug, Serialize, Deserialize)]
pub struct Finding {
    pub id: String,
    pub section: String,
    pub title: String,
    pub status: String,
    pub penalty: f64,
    pub explanation: String,
    pub evidence: Vec<String>,
}
impl Finding {
    pub fn new(
        id: &str,
        section: &str,
        title: impl Into<String>,
        status: &str,
        penalty: f64,
        explanation: impl Into<String>,
        evidence: Vec<String>,
    ) -> Self {
        Self {
            id: id.into(),
            section: section.into(),
            title: title.into(),
            status: status.into(),
            penalty,
            explanation: explanation.into(),
            evidence,
        }
    }
}

#[derive(Clone, Debug, Serialize, Deserialize)]
pub struct Report {
    pub score: f64,
    pub max: f64,
    pub applicable: usize,
    pub skipped: usize,
    pub passed: usize,
    pub findings: Vec<Finding>,
}
impl Report {
    pub fn new(findings: Vec<Finding>) -> Self {
        let skipped = findings.iter().filter(|f| f.status == "skip").count();
        Self {
            score: ((10. - findings.iter().map(|f| f.penalty).sum::<f64>()).max(0.) * 10.).round()
                / 10.,
            max: 10.,
            applicable: findings.len() - skipped,
            skipped,
            passed: findings.iter().filter(|f| f.status == "pass").count(),
            findings,
        }
    }
}

pub fn domain(value: &str) -> Option<String> {
    html::regex(r"[^\s<>@]+@([^\s<>@,;]+)")
        .captures(value)
        .map(|c| c[1].to_lowercase().trim_end_matches(['.', '>']).into())
}
pub fn header(detail: &Detail, name: &str) -> Option<String> {
    detail
        .header_list
        .iter()
        .find(|h| h.name.eq_ignore_ascii_case(name) && !h.value.trim().is_empty())
        .map(|h| h.value.trim().into())
}

pub fn build(detail: &Detail) -> Report {
    let mut findings = Vec::new();
    let mut add = |id: &str,
                   section: &str,
                   title: String,
                   status: &str,
                   penalty: f64,
                   explanation: &str,
                   evidence: Vec<String>| {
        findings.push(Finding::new(
            id,
            section,
            title,
            status,
            penalty,
            explanation,
            evidence,
        ))
    };
    let html_body = detail.html.as_deref();
    let text = detail.text.as_deref();
    let doc = html_body
        .filter(|s| !s.trim().is_empty())
        .map(html::document);
    let spam = &detail.message.meta["spam"];
    if spam.is_object() {
        let score = spam["score"].as_f64().unwrap_or(0.);
        let threshold = spam["threshold"].as_f64().unwrap_or(5.);
        let evidence = spam["rules"]
            .as_array()
            .map(|rules| {
                rules
                    .iter()
                    .map(|r| {
                        format!(
                            "{:+.1} {}: {}",
                            r["points"].as_f64().unwrap_or(0.),
                            r["name"].as_str().unwrap_or(""),
                            r["description"].as_str().unwrap_or("")
                        )
                    })
                    .collect()
            })
            .unwrap_or_default();
        add(
            "spam-score",
            "spam",
            format!("SpamAssassin scores this message {score:.1} of {threshold:.1}"),
            if score >= threshold {
                "fail"
            } else if score > 0. {
                "warn"
            } else {
                "pass"
            },
            score.clamp(0., 5.),
            "The rules explain which parts of the message contributed to the score.",
            evidence,
        );
    } else {
        add(
            "spam-score",
            "spam",
            "SpamAssassin score".into(),
            "skip",
            0.,
            "No spamd was reachable when this message came in, so it has no score.",
            vec![],
        );
    }
    let results = header(detail, "authentication-results");
    let spf = header(detail, "received-spf");
    let signed = header(detail, "dkim-signature").is_some();
    if results.is_none() && spf.is_none() && !signed {
        add(
            "authentication",
            "authentication",
            "No receiving server judged this message".into(),
            "skip",
            0.,
            "It never left the machine. Import a delivered .eml to see what a real server made of it.",
            vec![],
        );
    } else {
        let mut evidence = Vec::new();
        if let Some(r) = &results {
            evidence.push(format!("Authentication-Results: {r}"));
        }
        if let Some(s) = &spf {
            evidence.push(format!("Received-SPF: {s}"));
        }
        if signed {
            evidence.push("DKIM-Signature: present".into());
        }
        let mut verdicts = Vec::new();
        for name in ["spf", "dkim", "dmarc"] {
            if let Some(c) = results.as_ref().and_then(|r| {
                html::regex(&format!(r"(?i)\b{name}=(\w+)"))
                    .captures(r)
                    .map(|c| c[1].to_lowercase())
            }) {
                verdicts.push((name, c));
            }
        }
        if verdicts.is_empty()
            && let Some(s) = &spf
            && let Some(c) = html::regex(r"^(\w+)").captures(s)
        {
            verdicts.push(("spf", c[1].to_lowercase()));
        }
        let failed = verdicts
            .iter()
            .any(|(_, v)| matches!(v.as_str(), "fail" | "softfail" | "permerror" | "temperror"));
        let passed = verdicts.iter().any(|(_, v)| v == "pass");
        let summary = verdicts
            .iter()
            .map(|(n, v)| format!("{} {v}", n.to_uppercase()))
            .collect::<Vec<_>>()
            .join(", ");
        add(
            "authentication",
            "authentication",
            if failed || passed {
                format!("The receiving server recorded {summary}")
            } else {
                "The receiving server recorded no verdict".into()
            },
            if failed {
                "fail"
            } else if passed {
                "pass"
            } else {
                "warn"
            },
            if failed {
                2.
            } else if passed {
                0.
            } else {
                1.
            },
            "Recorded by the receiving server; this is not a fresh verification.",
            evidence,
        );
    }
    let has_html = html_body.is_some_and(|s| !s.trim().is_empty());
    let has_text = text.is_some_and(|s| !s.trim().is_empty());
    add(
        "text-and-html",
        "content",
        match (has_text, has_html) {
            (true, true) => "The message has both a text and an html version",
            (true, false) => "The message has no html version",
            (false, true) => "The message has no text version",
            _ => "The message has no body at all",
        }
        .into(),
        if has_html && has_text {
            "pass"
        } else if has_html || has_text {
            "warn"
        } else {
            "fail"
        },
        if has_html && has_text {
            0.
        } else if has_html || has_text {
            0.5
        } else {
            2.
        },
        "Check both alternatives that recipients may read.",
        vec![],
    );
    if let Some(doc) = &doc {
        let mut dangerous = Vec::new();
        for name in [
            "script", "iframe", "frame", "frameset", "embed", "object", "applet", "form",
        ] {
            let count = doc.select(&html::selector(name)).count();
            if count > 0 {
                dangerous.push(if count == 1 {
                    format!("<{name}>")
                } else {
                    format!("<{name}> x{count}")
                });
            }
        }
        add(
            "dangerous-html",
            "content",
            if dangerous.is_empty() {
                "No scripts, frames or embedded content"
            } else {
                "The html contains elements mail clients strip"
            }
            .into(),
            if dangerous.is_empty() { "pass" } else { "fail" },
            if dangerous.is_empty() { 0. } else { 2. },
            "Mail clients do not run these elements.",
            dangerous,
        );
        let images = doc.select(&html::selector("img")).collect::<Vec<_>>();
        let missing = images
            .iter()
            .filter(|img| img.value().attr("alt").is_none())
            .map(|img| {
                img.value()
                    .attr("src")
                    .unwrap_or("(image without src)")
                    .into()
            })
            .collect::<Vec<String>>();
        let title = if images.is_empty() {
            "The message has no images".into()
        } else if !missing.is_empty() {
            format!(
                "{} of {} images have no alt text",
                missing.len(),
                images.len()
            )
        } else if images.len() == 1 {
            "The image has alt text".into()
        } else {
            format!("All {} images have alt text", images.len())
        };
        add(
            "image-alt",
            "content",
            title,
            if missing.is_empty() { "pass" } else { "warn" },
            if missing.is_empty() { 0. } else { 0.5 },
            "An empty alt attribute marks decoration; a missing one leaves nothing to read.",
            missing.into_iter().take(10).collect(),
        );
    } else {
        for (id, title) in [
            ("dangerous-html", "Dangerous html"),
            ("image-alt", "Images have alt text"),
        ] {
            add(
                id,
                "content",
                title.into(),
                "skip",
                0.,
                "The message has no html.",
                vec![],
            );
        }
    }
    if let Some(body) = html_body {
        let size = body.len();
        let readable = if size < 1024 {
            format!("{size} bytes")
        } else {
            format!("{} kB", (size as f64 / 1024.).round())
        };
        let large = size > 102 * 1024;
        add(
            "gmail-clipping",
            "content",
            format!(
                "The html is {readable}, {} Gmail's clipping point",
                if large { "over" } else { "under" }
            ),
            if large { "warn" } else { "pass" },
            if large { 1. } else { 0. },
            "Gmail clips HTML at about 102 kB, including the footer.",
            vec![],
        );
    } else {
        add(
            "gmail-clipping",
            "content",
            "Message size".into(),
            "skip",
            0.,
            "The message has no html.",
            vec![],
        );
    }
    if let Some(check) = html_body.and_then(html::analyse) {
        let supported = check["supported"].as_f64().unwrap();
        let evidence = check["warnings"]
            .as_array()
            .unwrap()
            .iter()
            .take(8)
            .map(|f| {
                format!(
                    "{}: {:.0}% supported",
                    f["title"].as_str().unwrap_or(""),
                    f["supported"].as_f64().unwrap() / f["tested"].as_f64().unwrap() * 100.
                )
            })
            .collect();
        add(
            "html-support",
            "content",
            format!("{supported}% of what this message uses is supported outright"),
            if supported >= 90. { "pass" } else { "warn" },
            if supported >= 90. {
                0.
            } else {
                (((90. - supported) / 40.).min(1.) * 10.).round() / 10.
            },
            "Some clients fall back or ignore unsupported HTML and CSS.",
            evidence,
        );
    } else {
        add(
            "html-support",
            "content",
            "Client support".into(),
            "skip",
            0.,
            "The message has no html to test.",
            vec![],
        );
    }
    let texts = detail
        .parts
        .iter()
        .filter(|p| p.content_type == "text/plain" && p.disposition == "body")
        .collect::<Vec<_>>();
    if texts.is_empty() {
        add(
            "charset",
            "content",
            "Character set".into(),
            "skip",
            0.,
            "The message has no text body.",
            vec![],
        );
    } else {
        let missing = texts
            .iter()
            .any(|p| p.charset.as_ref().is_none_or(|s| s.is_empty()));
        let invalid = texts.iter().any(|p| p.encoding_problem);
        add(
            "charset",
            "content",
            if missing {
                "A body declares no character set"
            } else if invalid {
                "A body does not match the character set it declares"
            } else {
                "Every body declares a character set and matches it"
            }
            .into(),
            if missing {
                "warn"
            } else if invalid {
                "fail"
            } else {
                "pass"
            },
            if missing {
                0.5
            } else if invalid {
                1.
            } else {
                0.
            },
            "Clients guess undeclared character sets and may display different text.",
            texts
                .iter()
                .map(|p| {
                    format!(
                        "{}; charset={}",
                        p.content_type,
                        p.charset.as_deref().unwrap_or("(none)")
                    )
                })
                .collect(),
        );
    }
    let attachments = detail
        .parts
        .iter()
        .filter(|p| p.disposition == "attachment")
        .collect::<Vec<_>>();
    let blocked = [
        "exe", "scr", "com", "bat", "cmd", "pif", "vbs", "js", "jar", "msi", "dll", "iso", "lnk",
    ];
    let refused = attachments.iter().any(|p| {
        p.filename
            .as_ref()
            .and_then(|name| name.rsplit_once('.'))
            .is_some_and(|(_, ext)| blocked.contains(&ext.to_lowercase().as_str()))
    });
    let total = attachments.iter().map(|p| p.size).sum::<usize>();
    let large = total > 10 * 1024 * 1024;
    let title = if attachments.is_empty() {
        "The message has no attachments".into()
    } else if refused {
        "An attachment has a type mail gateways refuse".into()
    } else if large {
        format!(
            "The attachments total {:.1} MB",
            total as f64 / 1024. / 1024.
        )
    } else if attachments.len() == 1 {
        "One attachment, nothing a gateway objects to".into()
    } else {
        format!(
            "{} attachments, nothing a gateway objects to",
            attachments.len()
        )
    };
    add(
        "attachments",
        "content",
        title,
        if refused {
            "fail"
        } else if large {
            "warn"
        } else {
            "pass"
        },
        if refused {
            2.
        } else if large {
            0.5
        } else {
            0.
        },
        "Gateways can strip executable files or refuse oversized attachments.",
        attachments
            .iter()
            .map(|p| {
                format!(
                    "{} ({}, {} kB)",
                    p.filename.as_deref().unwrap_or("(unnamed)"),
                    p.content_type,
                    (p.size as f64 / 1024.).round()
                )
            })
            .collect(),
    );
    let required = [
        ("from", "From"),
        ("to", "To"),
        ("subject", "Subject"),
        ("date", "Date"),
        ("message-id", "Message-ID"),
    ];
    let missing = required
        .iter()
        .filter(|(name, _)| header(detail, name).is_none())
        .map(|(_, label)| *label)
        .collect::<Vec<_>>();
    let evidence = required
        .iter()
        .filter_map(|(name, label)| header(detail, name).map(|value| format!("{label}: {value}")))
        .collect();
    add(
        "required-headers",
        "headers",
        if missing.is_empty() {
            "All the expected headers are there".into()
        } else {
            format!("Missing headers: {}", missing.join(", "))
        },
        if missing.is_empty() { "pass" } else { "fail" },
        0.5 * missing.len() as f64,
        "A message without them looks assembled rather than sent.",
        evidence,
    );
    if let Some(from) = header(detail, "from") {
        let named = from.contains('<');
        add(
            "sender-name",
            "headers",
            if named {
                "The sender has a display name"
            } else {
                "The sender has no display name"
            }
            .into(),
            if named { "pass" } else { "warn" },
            if named { 0. } else { 0.5 },
            "Inboxes show the sender's display name.",
            vec![format!("From: {from}")],
        );
    } else {
        add(
            "sender-name",
            "headers",
            "Sender name".into(),
            "skip",
            0.,
            "The message has no From header.",
            vec![],
        );
    }
    let from = header(detail, "from").and_then(|s| domain(&s));
    let bounce = header(detail, "return-path").and_then(|s| domain(&s));
    if let (Some(from), Some(bounce)) = (from, bounce) {
        let aligned = from == bounce
            || from.ends_with(&format!(".{bounce}"))
            || bounce.ends_with(&format!(".{from}"));
        add(
            "return-path",
            "headers",
            if aligned {
                "The bounce address is on the sender domain"
            } else {
                "The bounce address is on another domain than the sender"
            }
            .into(),
            if aligned { "pass" } else { "warn" },
            if aligned { 0. } else { 1. },
            "DMARC requires alignment with the domain the reader sees.",
            vec![format!("From: {from}"), format!("Return-Path: {bounce}")],
        );
    } else {
        add(
            "return-path",
            "headers",
            "Return-Path alignment".into(),
            "skip",
            0.,
            "This message has no Return-Path, which is added by the sending server.",
            vec![],
        );
    }
    let unsubscribe = header(detail, "list-unsubscribe");
    let post = header(detail, "list-unsubscribe-post");
    let setup = unsubscribe
        .as_ref()
        .is_some_and(|s| s.to_lowercase().contains("https://"))
        && post.is_some();
    let title = match &unsubscribe {
        None => "The message has no List-Unsubscribe header",
        Some(value) if !value.to_lowercase().contains("https://") => {
            "The unsubscribe header offers no https url"
        }
        Some(_) if post.is_none() => {
            "The message has List-Unsubscribe but not List-Unsubscribe-Post"
        }
        Some(_) => "One-click unsubscribe is set up",
    };
    add(
        "unsubscribe",
        "headers",
        title.into(),
        if setup { "pass" } else { "warn" },
        if setup { 0. } else { 0.5 },
        "Required for bulk mail; transactional messages may omit it.",
        [
            ("List-Unsubscribe", unsubscribe),
            ("List-Unsubscribe-Post", post),
        ]
        .into_iter()
        .filter_map(|(name, value)| value.map(|v| format!("{name}: {v}")))
        .collect(),
    );
    if let Some(doc) = &doc {
        let anchors = doc.select(&html::selector("a")).collect::<Vec<_>>();
        let mut mismatched = Vec::new();
        for a in &anchors {
            let text = a.text().collect::<String>();
            let target = a.value().attr("href").and_then(host);
            if let (Some(target), Some(c)) = (
                target,
                html::regex(r"(?i)\b((?:https?://)?(?:[a-z0-9-]+\.)+[a-z]{2,})")
                    .captures(text.trim()),
            ) {
                let claimed = host(
                    if c[1].starts_with("http") {
                        c[1].into()
                    } else {
                        format!("https://{}", &c[1])
                    }
                    .as_str(),
                );
                if claimed.is_some_and(|c| c != target && !target.ends_with(&format!(".{c}"))) {
                    mismatched.push(format!("\"{}\" goes to {target}", text.trim()));
                }
            }
        }
        let title = if anchors.is_empty() {
            "The message has no links".into()
        } else if mismatched.is_empty() {
            format!(
                "None of the {} links say one thing and do another",
                anchors.len()
            )
        } else {
            format!(
                "{} links name a different domain than they open",
                mismatched.len()
            )
        };
        add(
            "link-text",
            "links",
            title,
            if mismatched.is_empty() {
                "pass"
            } else {
                "warn"
            },
            if mismatched.is_empty() { 0. } else { 1. },
            "A link naming another domain resembles a phishing message.",
            mismatched.into_iter().take(10).collect(),
        );
    } else {
        add(
            "link-text",
            "links",
            "Link text".into(),
            "skip",
            0.,
            "The message has no html.",
            vec![],
        );
    }
    let shorteners = [
        "bit.ly",
        "bit.do",
        "t.co",
        "tinyurl.com",
        "goo.gl",
        "ow.ly",
        "is.gd",
        "buff.ly",
        "rebrand.ly",
        "cutt.ly",
        "shorturl.at",
        "t.ly",
        "rb.gy",
        "lnkd.in",
        "s.id",
        "tiny.cc",
        "shorte.st",
        "adf.ly",
        "soo.gd",
        "clck.ru",
        "trib.al",
        "mcaf.ee",
        "qr.ae",
    ];
    let found = html::links(html_body, text)
        .into_iter()
        .filter_map(|l| {
            let url = l["url"].as_str()?;
            host(url)
                .filter(|h| shorteners.contains(&h.as_str()))
                .map(|_| url.to_string())
        })
        .collect::<Vec<_>>();
    add(
        "url-shorteners",
        "links",
        if found.is_empty() {
            "No shortened urls".into()
        } else {
            format!("{} links use a url shortener", found.len())
        },
        if found.is_empty() { "pass" } else { "warn" },
        if found.is_empty() { 0. } else { 1. },
        "Shorteners hide the destination from the reader.",
        found.into_iter().take(10).collect(),
    );
    Report::new(findings)
}

fn host(url: &str) -> Option<String> {
    reqwest::Url::parse(url.trim())
        .ok()?
        .host_str()
        .map(|s| s.to_lowercase().trim_start_matches("www.").into())
}

pub fn detail_json(detail: &Detail) -> Value {
    let mut result = serde_json::to_value(detail).expect("Serializable detail");
    if detail.message.channel == "email" {
        result["headers"] = mail::header_map(detail);
        result["htmlCheck"] = detail
            .html
            .as_deref()
            .and_then(html::analyse)
            .unwrap_or(Value::Null);
        result["links"] = json!(html::links(detail.html.as_deref(), detail.text.as_deref()));
        result["report"] = json!(build(detail));
        result["sourcePart"] = json!(detail.parts.iter().find(|p| p.disposition == "source"));
        result["parts"] = json!(
            detail
                .parts
                .iter()
                .filter(|p| p.disposition != "source")
                .collect::<Vec<_>>()
        );
    } else {
        for field in ["parts", "text", "headerList"] {
            result.as_object_mut().unwrap().remove(field);
        }
    }
    result
}
