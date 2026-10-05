use crate::model::{Detail, MailHeader, Message, Part, PartSummary};
use anyhow::{Result, anyhow};
use mail_parser::{HeaderForm, MessageParser, MimeHeaders, PartType};
use serde_json::{Value, json};
use uuid::Uuid;

pub fn capture(
    raw: &[u8],
    envelope: Option<(&str, &[String])>,
    filename: Option<&str>,
) -> Result<Vec<(Detail, Vec<Part>)>> {
    let parsed = MessageParser::default()
        .parse(raw)
        .ok_or_else(|| anyhow!("The file is not a MIME message."))?;
    let header_list = headers(raw);
    let header = |name: &str| {
        header_list
            .iter()
            .find(|h| h.name.eq_ignore_ascii_case(name))
            .map(|h| h.value.clone())
    };
    let addresses = |address: Option<&mail_parser::Address<'_>>| -> Vec<String> {
        address
            .map(|a| {
                a.iter()
                    .filter_map(|a| a.address.as_ref().map(|s| s.to_string()))
                    .collect()
            })
            .unwrap_or_default()
    };
    let imported = envelope.is_none();
    let mut header_recipients = addresses(parsed.to());
    header_recipients.extend(addresses(parsed.cc()));
    header_recipients.extend(addresses(parsed.bcc()));
    let mut unique = Vec::new();
    for recipient in header_recipients {
        if !unique.contains(&recipient) {
            unique.push(recipient);
        }
    }
    if unique.is_empty() {
        unique.push("(no recipient)".into());
    }
    let recipients = envelope
        .map(|(_, r)| r.to_vec())
        .unwrap_or_else(|| vec![unique.join(", ")]);
    let from = header("from")
        .or_else(|| envelope.map(|(s, _)| s.into()))
        .unwrap_or_default();
    let (text, html) = bodies(&parsed);
    let body = if let Some(text) = text.as_ref().filter(|t| !t.trim().is_empty()) {
        text.trim().to_string()
    } else {
        let cleaned = crate::html::regex(r"(?is)<(?:script|style)\b[^>]*>.*?</(?:script|style)>")
            .replace_all(html.as_deref().unwrap_or_default(), " ");
        let doc = crate::html::document(&cleaned);
        doc.select(&crate::html::selector("body"))
            .next()
            .map(|body| {
                body.text()
                    .collect::<Vec<_>>()
                    .join(" ")
                    .split_whitespace()
                    .collect::<Vec<_>>()
                    .join(" ")
            })
            .unwrap_or_default()
    };
    let batch = Uuid::new_v4().to_string();
    recipients.iter().map(|to| {
        let mut message=Message::new(if imported {"import"} else {"smtp"},"email",&batch,to,&body);
        message.from=Some(from.clone()); message.provider_ref=header("message-id");
        message.meta=json!({"subject":parsed.subject().unwrap_or(""),"hasHtml":html.is_some(),"attachments":parsed.attachments().filter(|p|p.content_id().is_none() && p.content_disposition().is_none_or(|d| d.c_type != "inline")).count()});
        for (key,name) in [("to","to"),("cc","cc"),("replyTo","reply-to"),("date","date")] { if let Some(value)=header(name) { message.meta[key]=json!(value); } }
        if imported { message.meta["imported"]=json!(true); message.meta["recipients"]=json!(unique.len()); if let Some(name)=filename { message.meta["filename"]=json!(name); } }
        else if let Some((sender, recipients))=envelope { message.meta["envelopeSender"]=json!(sender); message.meta["envelopeRecipients"]=json!(recipients); }
        let mut detail=Detail::new(message,String::from_utf8_lossy(raw).into_owned());
        let mut parts=vec![Part { summary:PartSummary {
            id:Uuid::new_v4().to_string(),content_type:"message/rfc822".into(),filename:Some(filename.unwrap_or("message.eml").into()),content_id:None,size:raw.len(),disposition:"source".into(),charset:None,encoding_problem:false,
        }, content:raw.to_vec() }];
        for part in &parsed.parts {
            if matches!(part.body, PartType::Multipart(_)) { continue; }
            let content=part.contents().to_vec();
            let content_type=part.content_type().map(|c|format!("{}/{}",c.c_type,c.c_subtype.as_deref().unwrap_or("octet-stream")))
                .unwrap_or_else(||match part.body {PartType::Text(_)=>"text/plain".into(),PartType::Html(_)=>"text/html".into(),_=>"application/octet-stream".into()});
            let disposition=if part.content_id().is_some() || part.content_disposition().is_some_and(|d|d.c_type=="inline") { "inline" }
                else if part.content_disposition().is_some_and(|d|d.c_type=="attachment") || (part.attachment_name().is_some() && !matches!(content_type.as_str(),"text/plain"|"text/html")) { "attachment" } else { "body" };
            parts.push(Part { summary:PartSummary {
                id:Uuid::new_v4().to_string(),content_type,filename:part.attachment_name().map(String::from),content_id:part.content_id().map(String::from),size:content.len(),disposition:disposition.into(),
                charset:part.content_type().and_then(|c|c.attribute("charset")).map(String::from),encoding_problem:part.is_encoding_problem,
            },content });
        }
        detail.html=html.clone(); detail.text=text.clone(); detail.header_list=header_list.clone();
        detail.parts=parts.iter().map(|p|p.summary.clone()).collect();
        Ok((detail,parts))
    }).collect()
}

fn bodies(parsed: &mail_parser::Message<'_>) -> (Option<String>, Option<String>) {
    let text = parsed
        .parts
        .iter()
        .find(|p| {
            matches!(p.body, PartType::Text(_))
                && p.content_type()
                    .is_none_or(|c| c.c_subtype.as_deref() == Some("plain"))
                && p.content_disposition()
                    .is_none_or(|d| d.c_type != "attachment")
        })
        .and_then(|p| p.text_contents())
        .map(String::from);
    let html = parsed
        .parts
        .iter()
        .find(|p| {
            matches!(p.body, PartType::Html(_))
                && p.content_disposition()
                    .is_none_or(|d| d.c_type != "attachment")
        })
        .and_then(|p| p.text_contents())
        .map(String::from);
    (text, html)
}

pub fn headers(raw: &[u8]) -> Vec<MailHeader> {
    MessageParser::default()
        .parse_headers(raw)
        .map(|message| {
            let mut positions = std::collections::HashMap::<String, usize>::new();
            message
                .headers()
                .iter()
                .map(|h| {
                    let name = h.name.as_str();
                    let position = positions.entry(name.to_string()).or_default();
                    let values = message.header_as(name, HeaderForm::Text);
                    let value = values
                        .get(*position)
                        .and_then(|v| v.as_text())
                        .unwrap_or_default()
                        .to_string();
                    *position += 1;
                    MailHeader {
                        name: name.into(),
                        value,
                    }
                })
                .collect()
        })
        .unwrap_or_default()
}

pub fn enrich(detail: &mut Detail) {
    let raw = detail.raw_request.clone();
    enrich_bytes(detail, raw.as_bytes());
}

pub fn enrich_bytes(detail: &mut Detail, raw: &[u8]) {
    detail.header_list = headers(raw);
    if let Some(parsed) = MessageParser::default().parse(raw) {
        (detail.text, detail.html) = bodies(&parsed);
    }
}

pub async fn score(captures: &mut [(Detail, Vec<Part>)], address: Option<&str>, raw: &[u8]) {
    if let Some(score) = crate::spam::check(address, raw).await {
        for (detail, _) in captures {
            detail.message.meta["spam"] = score.clone();
        }
    }
}

pub fn header_map(detail: &Detail) -> Value {
    let mut headers = json!({});
    for header in &detail.header_list {
        headers[header.name.to_lowercase()] = json!(header.value);
    }
    headers
}
