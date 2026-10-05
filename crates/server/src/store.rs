use std::{
    path::Path,
    sync::{Arc, Mutex},
};

use anyhow::{Context, Result};
use rusqlite::{Connection, OptionalExtension, params};
use serde_json::{Value, json};
use tokio::sync::broadcast;

use crate::model::{Detail, Filters, Message, Part, Scenario, now};

#[derive(Clone)]
pub struct Store {
    db: Arc<Mutex<Connection>>,
    max_messages: usize,
    pub changes: broadcast::Sender<()>,
}

impl Store {
    pub fn open(path: &str, max_messages: usize) -> Result<Self> {
        anyhow::ensure!(max_messages > 0, "MSGPIT_MAX_MESSAGES must be positive");
        if path != ":memory:"
            && let Some(parent) = Path::new(path)
                .parent()
                .filter(|p| !p.as_os_str().is_empty())
        {
            std::fs::create_dir_all(parent)?;
        }
        let db = Connection::open(path)?;
        db.execute_batch("PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;
            CREATE TABLE IF NOT EXISTS rust_messages (
                id TEXT PRIMARY KEY, provider TEXT NOT NULL, channel TEXT NOT NULL,
                recipient TEXT NOT NULL, created_at TEXT NOT NULL, payload TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS rust_parts (
                id TEXT PRIMARY KEY, message_id TEXT NOT NULL REFERENCES rust_messages(id) ON DELETE CASCADE,
                content BLOB NOT NULL
            );
            CREATE TABLE IF NOT EXISTS rust_events (
                seq INTEGER PRIMARY KEY AUTOINCREMENT, payload TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS rust_state (key TEXT PRIMARY KEY, value TEXT NOT NULL);")?;
        let (changes, _) = broadcast::channel(128);
        Ok(Self {
            db: Arc::new(Mutex::new(db)),
            max_messages,
            changes,
        })
    }

    async fn with<T: Send + 'static>(
        &self,
        f: impl FnOnce(&mut Connection) -> Result<T> + Send + 'static,
    ) -> Result<T> {
        let db = self.db.clone();
        tokio::task::spawn_blocking(move || {
            let mut db = db
                .lock()
                .map_err(|_| anyhow::anyhow!("Database lock poisoned"))?;
            f(&mut db)
        })
        .await
        .context("Database task failed")?
    }

    pub async fn capture(&self, captures: Vec<(Detail, Vec<Part>)>) -> Result<()> {
        let max = self.max_messages;
        self.with(move |db| {
            let tx = db.transaction()?;
            for (detail, parts) in captures {
                let m = &detail.message;
                tx.execute("INSERT INTO rust_messages VALUES (?1, ?2, ?3, ?4, ?5, ?6)",
                    params![m.id, m.provider, m.channel, m.to, m.created_at, serde_json::to_string(&detail)?])?;
                for part in parts {
                    tx.execute("INSERT INTO rust_parts VALUES (?1, ?2, ?3)", params![part.summary.id, m.id, part.content])?;
                }
                record(&tx, "message", Some(m))?;
            }
            tx.execute("DELETE FROM rust_messages WHERE id NOT IN (SELECT id FROM rust_messages ORDER BY rowid DESC LIMIT ?1)", [max as i64])?;
            // Clients replay the latest window; older cursors are explicitly told to reload.
            tx.execute("DELETE FROM rust_events WHERE seq <= (SELECT COALESCE(MAX(seq), 0) - ?1 FROM rust_events)", [(max * 4).max(1000) as i64])?;
            tx.commit()?;
            Ok(())
        }).await?;
        let _ = self.changes.send(());
        Ok(())
    }

    pub async fn list(&self, filters: Filters) -> Result<Vec<Message>> {
        self.with(move |db| {
            let mut stmt = db.prepare("SELECT payload FROM rust_messages WHERE
                (?1 IS NULL OR ?1 = '' OR provider = ?1) AND (?2 IS NULL OR ?2 = '' OR channel = ?2)
                AND (?3 IS NULL OR instr(recipient, ?3) > 0)
                AND (?4 IS NULL OR ?4 = '' OR julianday(created_at) > julianday(?4)) ORDER BY rowid DESC")?;
            let rows = stmt.query_map(params![filters.provider, filters.channel, filters.to, filters.since], |row| row.get::<_, String>(0))?;
            rows.map(|row| Ok(serde_json::from_str::<Detail>(&row?)?.message)).collect()
        }).await
    }

    pub async fn detail(&self, id: String) -> Result<Option<Detail>> {
        self.with(move |db| {
            let payload: Option<String> = db
                .query_row(
                    "SELECT payload FROM rust_messages WHERE id = ?1",
                    [id],
                    |r| r.get(0),
                )
                .optional()?;
            payload
                .map(|p| serde_json::from_str(&p).map_err(Into::into))
                .transpose()
        })
        .await
    }

    pub async fn part(&self, message_id: String, id: String) -> Result<Option<Vec<u8>>> {
        self.with(move |db| {
            Ok(db
                .query_row(
                    "SELECT content FROM rust_parts WHERE message_id = ?1 AND id = ?2",
                    params![message_id, id],
                    |r| r.get(0),
                )
                .optional()?)
        })
        .await
    }

    pub async fn mark_read(&self, id: Option<String>) -> Result<bool> {
        let found = self
            .with(move |db| {
                let tx = db.transaction()?;
                let details: Vec<Detail> = {
                    let mut stmt = tx
                        .prepare("SELECT payload FROM rust_messages WHERE ?1 IS NULL OR id = ?1")?;
                    let rows = stmt.query_map([id], |r| r.get::<_, String>(0))?;
                    rows.map(|r| Ok(serde_json::from_str(&r?)?))
                        .collect::<Result<_>>()?
                };
                let found = !details.is_empty();
                for mut detail in details {
                    if !detail.message.read {
                        detail.message.read = true;
                        detail.message.read_at = Some(now());
                        tx.execute(
                            "UPDATE rust_messages SET payload = ?1 WHERE id = ?2",
                            params![serde_json::to_string(&detail)?, detail.message.id],
                        )?;
                        record(&tx, "read", Some(&detail.message))?;
                    }
                }
                tx.commit()?;
                Ok(found)
            })
            .await?;
        let _ = self.changes.send(());
        Ok(found)
    }

    pub async fn clear(&self) -> Result<()> {
        self.with(|db| {
            let tx = db.transaction()?;
            tx.execute("DELETE FROM rust_messages", [])?;
            record(&tx, "cleared", None)?;
            tx.commit()?;
            Ok(())
        })
        .await?;
        let _ = self.changes.send(());
        Ok(())
    }

    pub async fn delivery_report(
        &self,
        id: String,
        status: String,
        report: Option<Value>,
    ) -> Result<Detail> {
        let detail = self
            .with(move |db| {
                let tx = db.transaction()?;
                let payload: String = tx.query_row(
                    "SELECT payload FROM rust_messages WHERE id=?1",
                    [&id],
                    |r| r.get(0),
                )?;
                let mut detail: Detail = serde_json::from_str(&payload)?;
                detail.message.status = status;
                if let Some(report) = report {
                    detail.delivery_reports.push(report);
                }
                tx.execute(
                    "UPDATE rust_messages SET payload=?1 WHERE id=?2",
                    params![serde_json::to_string(&detail)?, id],
                )?;
                record(&tx, "status", Some(&detail.message))?;
                tx.commit()?;
                Ok(detail)
            })
            .await?;
        let _ = self.changes.send(());
        Ok(detail)
    }

    pub async fn events_since(&self, seq: i64) -> Result<Vec<(i64, Value)>> {
        self.with(move |db| {
            let oldest: i64 =
                db.query_row("SELECT COALESCE(MIN(seq),0) FROM rust_events", [], |r| {
                    r.get(0)
                })?;
            if seq > 0 && seq < oldest - 1 {
                return Ok(vec![(
                    oldest - 1,
                    json!({"type":"reset", "seq":oldest - 1}),
                )]);
            }
            let mut stmt = db.prepare(
                "SELECT seq, payload FROM rust_events WHERE seq > ?1 ORDER BY seq LIMIT 200",
            )?;
            let rows =
                stmt.query_map([seq], |r| Ok((r.get::<_, i64>(0)?, r.get::<_, String>(1)?)))?;
            rows.map(|row| {
                let (seq, payload) = row?;
                let mut value: Value = serde_json::from_str(&payload)?;
                value["seq"] = json!(seq);
                Ok((seq, value))
            })
            .collect()
        })
        .await
    }

    pub async fn set_scenario(&self, scenario: Option<Scenario>) -> Result<()> {
        self.with(move |db| {
            let tx = db.transaction()?;
            tx.execute("DELETE FROM rust_state WHERE key = 'scenario'", [])?;
            if let Some(scenario) = scenario {
                tx.execute(
                    "INSERT INTO rust_state VALUES ('scenario', ?1)",
                    [serde_json::to_string(&scenario)?],
                )?;
            }
            record(&tx, "scenario", None)?;
            tx.commit()?;
            Ok(())
        })
        .await?;
        let _ = self.changes.send(());
        Ok(())
    }

    pub async fn scenario(&self) -> Result<Option<Scenario>> {
        self.with(|db| {
            let value: Option<String> = db
                .query_row(
                    "SELECT value FROM rust_state WHERE key='scenario'",
                    [],
                    |r| r.get(0),
                )
                .optional()?;
            value
                .map(|v| serde_json::from_str(&v).map_err(Into::into))
                .transpose()
        })
        .await
    }

    pub async fn consume_scenario(&self) -> Result<Option<Scenario>> {
        let scenario = self
            .with(|db| {
                let tx = db.transaction()?;
                let value: Option<String> = tx
                    .query_row(
                        "SELECT value FROM rust_state WHERE key='scenario'",
                        [],
                        |r| r.get(0),
                    )
                    .optional()?;
                tx.execute("DELETE FROM rust_state WHERE key='scenario'", [])?;
                if value.is_some() {
                    record(&tx, "scenario", None)?;
                }
                tx.commit()?;
                value
                    .map(|v| serde_json::from_str(&v).map_err(Into::into))
                    .transpose()
            })
            .await?;
        if scenario.is_some() {
            let _ = self.changes.send(());
        }
        Ok(scenario)
    }
}

fn record(db: &Connection, kind: &str, message: Option<&Message>) -> Result<()> {
    let mut payload = json!({"type":kind});
    if let Some(message) = message {
        payload["message"] = serde_json::to_value(message)?;
    }
    db.execute(
        "INSERT INTO rust_events (payload) VALUES (?1)",
        [serde_json::to_string(&payload)?],
    )?;
    Ok(())
}
