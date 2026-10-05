use regex::Regex;
use scraper::{Html, Selector};
use serde_json::{Value, json};
use std::{
    collections::{BTreeMap, HashMap},
    sync::OnceLock,
};

pub fn document(html: &str) -> Html {
    Html::parse_document(html)
}
pub fn selector(value: &str) -> Selector {
    Selector::parse(value).expect("Static HTML selector")
}
pub fn regex(value: &str) -> Regex {
    Regex::new(value).expect("Static pattern")
}

fn data() -> &'static Value {
    static DATA: OnceLock<Value> = OnceLock::new();
    DATA.get_or_init(|| {
        serde_json::from_str(include_str!("../../../data/caniemail.json"))
            .expect("Bundled caniemail data")
    })
}

pub fn analyse(html: &str) -> Option<Value> {
    analyse_with(html, data())
}

pub fn analyse_with(html: &str, data: &Value) -> Option<Value> {
    if html.trim().is_empty() {
        return None;
    }
    let doc = document(html);
    let mut used: BTreeMap<String, usize> = BTreeMap::new();
    let properties = regex(r"(?i)(?:^|[;{])\s*([a-z-]+)\s*:");
    let comments = regex(r"(?s)/\*.*?\*/");
    let ignored = [
        "class", "id", "style", "href", "src", "alt", "title", "lang", "charset", "content", "name",
    ];
    for node in doc.select(&selector("*")) {
        *used
            .entry(format!("html-{}", node.value().name()))
            .or_default() += 1;
        for (name, _) in node.value().attrs() {
            if !ignored.contains(&name) {
                *used.entry(format!("html-{name}")).or_default() += 1;
            }
        }
        let style = format!(
            "{} {}",
            node.value().attr("style").unwrap_or_default(),
            if node.value().name() == "style" {
                node.text().collect::<String>()
            } else {
                String::new()
            }
        );
        let css = comments.replace_all(&style, " ");
        for c in properties.captures_iter(&css) {
            *used
                .entry(format!("css-{}", c[1].to_lowercase()))
                .or_default() += 1;
        }
    }
    let mut findings = Vec::new();
    for feature in data["data"].as_array()? {
        let slug = feature["slug"].as_str()?;
        let Some(count) = used.get(slug) else {
            continue;
        };
        let mut support = [0; 3];
        count_support(&feature["stats"], &mut support);
        let total = support.iter().sum::<usize>();
        if total == 0 {
            continue;
        }
        findings.push(json!({"slug":slug,"title":feature["title"],"category":feature["category"],"occurrences":count,"supported":support[0],"partial":support[1],"unsupported":support[2],"tested":total}));
    }
    if findings.is_empty() {
        return None;
    }
    findings.sort_by(|a, b| {
        let score = |f: &Value| f["supported"].as_f64().unwrap() / f["tested"].as_f64().unwrap();
        score(a)
            .total_cmp(&score(b))
            .then_with(|| a["title"].as_str().cmp(&b["title"].as_str()))
    });
    let tested = findings
        .iter()
        .map(|f| f["tested"].as_u64().unwrap())
        .sum::<u64>();
    let percent = |kind: &str| {
        (findings
            .iter()
            .map(|f| f[kind].as_f64().unwrap())
            .sum::<f64>()
            / tested as f64
            * 10_000.)
            .round()
            / 100.
    };
    Some(
        json!({"supported":percent("supported"),"partial":percent("partial"),"unsupported":percent("unsupported"),"tested":tested,"features":findings.len(),"dataUpdated":data["last_update_date"],"warnings":findings.iter().filter(|f| f["partial"].as_u64().unwrap()>0 || f["unsupported"].as_u64().unwrap()>0).collect::<Vec<_>>()}),
    )
}

fn count_support(value: &Value, count: &mut [usize; 3]) {
    match value {
        Value::Object(object) => {
            for value in object.values() {
                count_support(value, count);
            }
        }
        Value::String(value) => match value.split_whitespace().next().unwrap_or("") {
            "y" => count[0] += 1,
            "a" => count[1] += 1,
            "n" => count[2] += 1,
            _ => {}
        },
        _ => {}
    }
}

fn add(
    found: &mut Vec<Value>,
    positions: &mut HashMap<String, usize>,
    url: &str,
    kind: &str,
    replace: bool,
) {
    let url = url.trim();
    if !url.to_lowercase().starts_with("http://") && !url.to_lowercase().starts_with("https://") {
        return;
    }
    if let Some(index) = positions.get(url) {
        if replace {
            found[*index]["kind"] = json!(kind);
        }
    } else {
        positions.insert(url.into(), found.len());
        found.push(json!({"url":url,"kind":kind}));
    }
}

pub fn links(html: Option<&str>, text: Option<&str>) -> Vec<Value> {
    let mut found = Vec::new();
    let mut positions = HashMap::new();
    let bare = regex(r#"(?i)\bhttps?://[^\s<>"'\])]+"#);
    let css_url = regex(r#"(?i)url\(\s*['"]?([^'"\)]+)"#);
    if let Some(html) = html {
        let doc = document(html);
        for c in bare.find_iter(&doc.root_element().text().collect::<String>()) {
            add(
                &mut found,
                &mut positions,
                c.as_str().trim_end_matches(['.', ',', ';', ':', '!', '?']),
                "link",
                false,
            );
        }
        for node in doc.select(&selector("*")) {
            let tag = node.value().name();
            let attr = match tag {
                "a" | "area" | "link" => Some("href"),
                "img" | "source" => Some("src"),
                "video" => Some("poster"),
                _ => None,
            };
            if let Some(attr) = attr
                && let Some(url) = node.value().attr(attr)
            {
                add(
                    &mut found,
                    &mut positions,
                    url,
                    if matches!(tag, "a" | "area") {
                        "link"
                    } else {
                        "resource"
                    },
                    true,
                );
            }
            let css = format!(
                "{} {}",
                node.value().attr("style").unwrap_or_default(),
                if tag == "style" {
                    node.text().collect::<String>()
                } else {
                    String::new()
                }
            );
            for c in css_url.captures_iter(&css) {
                add(&mut found, &mut positions, &c[1], "resource", false);
            }
        }
    }
    for c in bare.find_iter(text.unwrap_or_default()) {
        add(
            &mut found,
            &mut positions,
            c.as_str().trim_end_matches(['.', ',', ';', ':', '!', '?']),
            "link",
            false,
        );
    }
    found
}
