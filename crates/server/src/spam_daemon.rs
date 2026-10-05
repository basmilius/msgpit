use crate::spam;
use anyhow::{Context, Result, bail};
use std::{process::Stdio, time::Duration};
use tokio::process::{Child, Command};
use tokio_util::sync::CancellationToken;

pub struct Daemon {
    child: Child,
    group: i32,
}

impl Daemon {
    pub async fn start(shutdown: &CancellationToken) -> Result<Self> {
        let mut command = Command::new("spamd");
        command.args([
            "--listen=127.0.0.1",
            "--port=783",
            "--nouser-config",
            "--local",
            "--max-children=2",
            "--syslog=stderr",
        ]);
        command.stdin(Stdio::null()).kill_on_drop(true);
        #[cfg(unix)]
        command.process_group(0);
        let child = command
            .spawn()
            .context("Could not start bundled SpamAssassin")?;
        let group = child.id().context("SpamAssassin has no process ID")? as i32;
        let mut daemon = Self { child, group };
        let ready = tokio::time::timeout(Duration::from_secs(30), async {
            loop {
                if let Some(status) = daemon.child.try_wait()? {
                    bail!("SpamAssassin exited during startup: {status}");
                }
                tokio::select! {
                    _ = shutdown.cancelled() => bail!("SpamAssassin startup cancelled"),
                    result = spam::ping(spam::LOCAL_ENDPOINT) => {
                        if result.is_ok() { return Ok(()); }
                    }
                }
                tokio::time::sleep(Duration::from_millis(100)).await;
            }
        })
        .await
        .context("SpamAssassin startup timed out")
        .and_then(|result| result);
        if let Err(error) = ready {
            daemon.stop().await?;
            return Err(error);
        }
        tracing::info!(pid = group, "Bundled SpamAssassin ready");
        Ok(daemon)
    }

    pub async fn run(mut self, shutdown: CancellationToken) -> Result<()> {
        tokio::select! {
            biased;
            _ = shutdown.cancelled() => self.stop().await,
            status = self.child.wait() => {
                self.signal(libc::SIGKILL);
                self.group = 0;
                bail!("Bundled SpamAssassin exited unexpectedly: {}", status?);
            }
        }
    }

    fn signal(&self, signal: i32) {
        if self.group == 0 {
            return;
        }
        #[cfg(unix)]
        // The private process group includes spamd workers, which must stop with Msgpit.
        unsafe {
            libc::kill(-self.group, signal);
        }
    }

    async fn stop(&mut self) -> Result<()> {
        self.signal(libc::SIGTERM);
        let result = match tokio::time::timeout(Duration::from_secs(5), self.child.wait()).await {
            Ok(result) => result,
            Err(_) => {
                self.signal(libc::SIGKILL);
                self.child.wait().await
            }
        };
        self.signal(libc::SIGKILL);
        self.group = 0;
        result?;
        Ok(())
    }
}

impl Drop for Daemon {
    fn drop(&mut self) {
        self.signal(libc::SIGKILL);
    }
}
