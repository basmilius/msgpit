use msgpit_server::{html, mail, report, spam};
use serde_json::{Value, json};

// Generated with the preserved PHP implementation at upstream c9fdc25.
#[test]
fn offline_deliverability_matches_upstream_oracle() {
    let cases: Vec<Value> =
        serde_json::from_str(include_str!("fixtures/report-golden.json")).unwrap();
    for case in cases {
        let raw = case["raw"].as_str().unwrap().as_bytes();
        let (mut detail, _) = mail::capture(raw, None, Some("fixture.eml"))
            .unwrap()
            .remove(0);
        detail.html = case["html"].as_str().map(String::from);
        detail.text = case["text"].as_str().map(String::from);
        detail.message.meta["spam"] = case["spam"].clone();
        let result = report::build(&detail);
        let actual = json!({"score":result.score,"applicable":result.applicable,"skipped":result.skipped,"passed":result.passed,"findings":result.findings.iter().map(|f|json!({"id":f.id,"status":f.status,"penalty":f.penalty})).collect::<Vec<_>>()});
        assert_eq!(actual, case["expected"], "{}", case["name"]);
    }
}

#[test]
fn original_mime_fixtures_keep_decoded_bodies_headers_and_source_bytes() {
    let root = std::path::Path::new(env!("CARGO_MANIFEST_DIR"))
        .join("../../reference/tests/fixtures/mime");
    for name in [
        "plain-text",
        "html-only",
        "alternative",
        "latin1",
        "folded-subject",
        "attachment-utf8-filename",
        "nested-with-inline-image-and-attachment",
    ] {
        let raw = std::fs::read(root.join(format!("{name}.eml"))).unwrap();
        // Upstream fixtures spell line endings as literal escapes.
        let raw = String::from_utf8_lossy(&raw)
            .replace("\\r\\n", "\r\n")
            .replace("\\n", "\n")
            .into_bytes();
        let (detail, parts) = mail::capture(&raw, None, Some(name)).unwrap().remove(0);
        assert_eq!(parts[0].content, raw, "{name}");
        assert!(
            !detail.message.meta["subject"]
                .as_str()
                .unwrap()
                .contains("=?"),
            "{name}"
        );
        let public = report::detail_json(&detail);
        assert_eq!(public["sourcePart"]["id"], parts[0].summary.id, "{name}");
        assert_eq!(
            public["parts"].as_array().unwrap().len(),
            parts.len() - 1,
            "{name}"
        );
        match name {
            "plain-text" | "latin1" | "folded-subject" => {
                assert!(detail.text.is_some(), "{name}");
                assert!(detail.html.is_none(), "{name}");
            }
            "html-only" => {
                assert!(detail.text.is_none());
                assert!(detail.html.is_some());
            }
            "alternative" | "nested-with-inline-image-and-attachment" => {
                assert!(detail.text.is_some());
                assert!(detail.html.is_some());
            }
            "attachment-utf8-filename" => assert!(
                parts
                    .iter()
                    .any(|p| p.summary.filename.as_deref() == Some("evaluatie café.pdf"))
            ),
            _ => unreachable!(),
        }
    }
}

#[test]
fn preserved_spam_reports_parse_scores_and_wrapped_rules() {
    let root = std::path::Path::new(env!("CARGO_MANIFEST_DIR"))
        .join("../../reference/tests/fixtures/spamassassin");
    let spam = spam::parse(
        &std::fs::read_to_string(root.join("report.txt"))
            .unwrap()
            .replace("\\r\\n", "\r\n")
            .replace("\\n", "\n"),
    )
    .unwrap();
    assert_eq!(spam["spam"], true);
    assert_eq!(spam["score"], 6.2);
    assert!(
        spam["rules"]
            .as_array()
            .unwrap()
            .iter()
            .any(|r| r["name"] == "MISSING_DATE")
    );
    let clean = spam::parse(
        &std::fs::read_to_string(root.join("clean-report.txt"))
            .unwrap()
            .replace("\\r\\n", "\r\n")
            .replace("\\n", "\n"),
    )
    .unwrap();
    assert_eq!(clean["spam"], false);
    assert!(clean["score"].as_f64().unwrap() < 5.);
    assert!(spam::parse("SPAMD/1.5 76 EX_PROTOCOL").is_none());
}

#[test]
fn html_links_extract_only_http_targets_and_deduplicate_resources() {
    let links = html::links(
        Some(
            "<a href='https://example.test/a'>Visit</a><img src='https://example.test/i'><img src='cid:image'><a href='mailto:a@b.test'>Mail</a><p>https://example.test/a</p><style>.a{background:url(https://example.test/bg)}</style>",
        ),
        Some("https://example.test/a https://example.test/t."),
    );
    assert_eq!(links.len(), 4);
    assert!(
        links
            .iter()
            .any(|l| l["url"] == "https://example.test/i" && l["kind"] == "resource")
    );
    assert!(links.iter().any(|l| l["url"] == "https://example.test/t"));
}
