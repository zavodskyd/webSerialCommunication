use std::{
    fs,
    path::{Path, PathBuf},
};

use anyhow::Context;

use crate::protocol::FrameEvent;

pub struct FrameQueue {
    path: PathBuf,
    frames: Vec<FrameEvent>,
}

impl FrameQueue {
    pub fn load(path: PathBuf) -> anyhow::Result<Self> {
        let frames = if path.is_file() {
            let raw = fs::read_to_string(&path)
                .with_context(|| format!("failed to read {}", path.display()))?;

            if raw.trim().is_empty() {
                Vec::new()
            } else {
                serde_json::from_str(&raw)
                    .with_context(|| format!("failed to parse {}", path.display()))?
            }
        } else {
            Vec::new()
        };

        Ok(Self { path, frames })
    }

    pub fn push(&mut self, frame: FrameEvent) -> anyhow::Result<()> {
        if self.frames.iter().any(|existing| existing.id == frame.id) {
            return Ok(());
        }

        self.frames.push(frame);
        self.persist()
    }

    pub fn ack(&mut self, id: &str) -> anyhow::Result<bool> {
        let before = self.frames.len();
        self.frames.retain(|frame| frame.id != id);
        let removed = before != self.frames.len();

        if removed {
            self.persist()?;
        }

        Ok(removed)
    }

    pub fn pending(&self) -> Vec<FrameEvent> {
        self.frames.clone()
    }

    pub fn len(&self) -> usize {
        self.frames.len()
    }

    fn persist(&self) -> anyhow::Result<()> {
        if let Some(parent) = self.path.parent() {
            fs::create_dir_all(parent)
                .with_context(|| format!("failed to create {}", parent.display()))?;
        }

        if self.frames.is_empty() {
            if Path::new(&self.path).exists() {
                fs::remove_file(&self.path)
                    .with_context(|| format!("failed to remove {}", self.path.display()))?;
            }

            return Ok(());
        }

        let body = serde_json::to_string_pretty(&self.frames)?;
        fs::write(&self.path, body)
            .with_context(|| format!("failed to write {}", self.path.display()))
    }
}

#[cfg(test)]
mod tests {
    use std::time::{SystemTime, UNIX_EPOCH};

    use super::*;

    #[test]
    fn ack_removes_frame_from_queue() {
        let path = std::env::temp_dir().join(format!(
            "serial-agent-queue-test-{}.json",
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));

        let mut queue = FrameQueue::load(path.clone()).unwrap();
        queue
            .push(FrameEvent {
                id: "frame-1".to_string(),
                hex: "2081a1".to_string(),
                received_at: "2026-04-28T12:00:00Z".to_string(),
            })
            .unwrap();

        assert_eq!(queue.len(), 1);
        assert!(queue.ack("frame-1").unwrap());
        assert_eq!(queue.len(), 0);
        assert!(!path.exists());
    }
}
