use serde_json::{Value, json};

const PAGES: &[(&str, &str)] = &[
    ("01-why", include_str!("../../../docs/01-why.md")),
    (
        "02-getting-started",
        include_str!("../../../docs/02-getting-started.md"),
    ),
    (
        "03-scenarios",
        include_str!("../../../docs/03-scenarios.md"),
    ),
    ("04-segments", include_str!("../../../docs/04-segments.md")),
    (
        "05-providers",
        include_str!("../../../docs/05-providers.md"),
    ),
    ("06-api", include_str!("../../../docs/06-api.md")),
    ("07-email", include_str!("../../../docs/07-email.md")),
    (
        "08-deliverability",
        include_str!("../../../docs/08-deliverability.md"),
    ),
];

pub fn index() -> Vec<Value> {
    PAGES.iter().map(|(slug,markdown)|json!({"slug":slug,"title":markdown.lines().find_map(|line|line.strip_prefix("# ")).unwrap_or(slug)})).collect()
}
pub fn page(slug: &str) -> Option<&'static str> {
    PAGES
        .iter()
        .find(|(name, _)| *name == slug)
        .map(|(_, text)| *text)
}
