use std::{
    fs::{self, File, OpenOptions},
    io::Write,
    path::PathBuf,
};

use anyhow::{Context, Result};
use serde::{Deserialize, Serialize};

use crate::protocol::FrameEvent;

const COMPACT_AFTER_RECORDS: usize = 4096;

#[derive(Serialize, Deserialize)]
#[serde(tag = "type", rename_all = "snake_case")]
enum JournalRecord {
    Frame { frame: FrameEvent },
    Ack { id: String },
}

pub struct FrameQueue {
    path: PathBuf,
    frames: Vec<FrameEvent>,
    records_since_compaction: usize,
}

impl FrameQueue {
    pub fn load(path: PathBuf) -> Result<Self> {
        let mut queue = Self {
            path,
            frames: Vec::new(),
            records_since_compaction: 0,
        };
        let legacy_path = queue.path.with_extension("json");

        if queue.path.is_file() {
            let bytes = fs::read(&queue.path)
                .with_context(|| format!("failed to read {}", queue.path.display()))?;
            let complete_length = bytes
                .iter()
                .rposition(|byte| *byte == b'\n')
                .map_or(0, |position| position + 1);

            for line in bytes[..complete_length].split(|byte| *byte == b'\n') {
                if line.is_empty() {
                    continue;
                }
                let record: JournalRecord = serde_json::from_slice(line)
                    .with_context(|| format!("invalid queue journal {}", queue.path.display()))?;
                queue.apply(record);
                queue.records_since_compaction += 1;
            }

            if complete_length < bytes.len() {
                OpenOptions::new()
                    .write(true)
                    .open(&queue.path)?
                    .set_len(complete_length as u64)?;
            }
        } else if legacy_path.is_file() {
            let raw = fs::read_to_string(&legacy_path)?;
            queue.frames = if raw.trim().is_empty() {
                Vec::new()
            } else {
                serde_json::from_str(&raw)
                    .with_context(|| format!("invalid legacy queue {}", legacy_path.display()))?
            };
            queue.compact()?;
        }

        if legacy_path.is_file() && queue.path.is_file() {
            fs::remove_file(legacy_path)?;
        }

        Ok(queue)
    }

    pub fn push_batch(&mut self, frames: &[FrameEvent]) -> Result<()> {
        let new_frames: Vec<FrameEvent> = frames
            .iter()
            .filter(|frame| !self.frames.iter().any(|existing| existing.id == frame.id))
            .cloned()
            .collect();
        if new_frames.is_empty() {
            return Ok(());
        }

        let records: Vec<JournalRecord> = new_frames
            .iter()
            .cloned()
            .map(|frame| JournalRecord::Frame { frame })
            .collect();
        self.append(&records, true)?;
        self.frames.extend(new_frames);
        self.records_since_compaction += records.len();
        Ok(())
    }

    pub fn ack(&mut self, id: &str) -> Result<bool> {
        if !self.frames.iter().any(|frame| frame.id == id) {
            return Ok(false);
        }

        self.append(&[JournalRecord::Ack { id: id.to_string() }], false)?;
        self.frames.retain(|frame| frame.id != id);
        self.records_since_compaction += 1;

        if self.records_since_compaction >= COMPACT_AFTER_RECORDS
            && self.records_since_compaction >= self.frames.len().saturating_mul(4)
        {
            self.compact()?;
        }

        Ok(true)
    }

    pub fn pending(&self) -> Vec<FrameEvent> {
        self.frames.clone()
    }

    pub fn len(&self) -> usize {
        self.frames.len()
    }

    fn apply(&mut self, record: JournalRecord) {
        match record {
            JournalRecord::Frame { frame } => {
                if !self.frames.iter().any(|existing| existing.id == frame.id) {
                    self.frames.push(frame);
                }
            }
            JournalRecord::Ack { id } => self.frames.retain(|frame| frame.id != id),
        }
    }

    fn append(&self, records: &[JournalRecord], durable: bool) -> Result<()> {
        if let Some(parent) = self.path.parent() {
            fs::create_dir_all(parent)?;
        }
        let is_new_file = !self.path.exists();
        let mut file = OpenOptions::new()
            .create(true)
            .append(true)
            .open(&self.path)?;
        let prior_length = file.metadata()?.len();
        let write_result = (|| -> Result<()> {
            for record in records {
                serde_json::to_writer(&mut file, record)?;
                file.write_all(b"\n")?;
            }
            if durable {
                file.sync_data()?;
                #[cfg(unix)]
                if is_new_file {
                    File::open(self.path.parent().expect("journal parent"))?.sync_all()?;
                }
            }
            Ok(())
        })();

        if write_result.is_err() {
            file.set_len(prior_length)?;
        }
        write_result
    }

    fn compact(&mut self) -> Result<()> {
        if let Some(parent) = self.path.parent() {
            fs::create_dir_all(parent)?;
        }
        let temporary_path = self.path.with_extension("journal.tmp");
        if temporary_path.exists() {
            fs::remove_file(&temporary_path)?;
        }
        let mut file = OpenOptions::new()
            .write(true)
            .create_new(true)
            .open(&temporary_path)?;
        for frame in &self.frames {
            serde_json::to_writer(
                &mut file,
                &JournalRecord::Frame {
                    frame: frame.clone(),
                },
            )?;
            file.write_all(b"\n")?;
        }
        file.sync_all()?;
        fs::rename(&temporary_path, &self.path)?;
        #[cfg(unix)]
        if let Some(parent) = self.path.parent() {
            File::open(parent)?.sync_all()?;
        }
        self.records_since_compaction = self.frames.len();
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use std::{
        sync::atomic::{AtomicU64, Ordering},
        time::{SystemTime, UNIX_EPOCH},
    };

    static NEXT_TEST_PATH: AtomicU64 = AtomicU64::new(0);

    use super::*;

    fn temporary_path() -> PathBuf {
        std::env::temp_dir().join(format!(
            "serial-agent-queue-test-{}-{}-{}.journal",
            std::process::id(),
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_nanos(),
            NEXT_TEST_PATH.fetch_add(1, Ordering::Relaxed)
        ))
    }

    fn frame(id: &str) -> FrameEvent {
        FrameEvent {
            id: id.to_string(),
            hex: "2081a1".to_string(),
            received_at: "2026-04-28T12:00:00Z".to_string(),
        }
    }

    #[test]
    fn replays_only_unacknowledged_frames_after_restart() {
        let path = temporary_path();
        let mut queue = FrameQueue::load(path.clone()).unwrap();
        queue
            .push_batch(&[frame("frame-1"), frame("frame-2")])
            .unwrap();
        assert!(queue.ack("frame-1").unwrap());
        drop(queue);

        let recovered = FrameQueue::load(path.clone()).unwrap();
        assert_eq!(recovered.len(), 1);
        assert_eq!(recovered.pending()[0].id, "frame-2");
        fs::remove_file(path).unwrap();
    }

    #[test]
    fn ignores_incomplete_final_journal_record() {
        let path = temporary_path();
        let mut queue = FrameQueue::load(path.clone()).unwrap();
        queue.push_batch(&[frame("frame-1")]).unwrap();
        drop(queue);
        OpenOptions::new()
            .append(true)
            .open(&path)
            .unwrap()
            .write_all(b"{\"type\":\"frame\"")
            .unwrap();

        let mut recovered = FrameQueue::load(path.clone()).unwrap();
        assert_eq!(recovered.len(), 1);
        recovered.push_batch(&[frame("frame-2")]).unwrap();
        assert_eq!(FrameQueue::load(path.clone()).unwrap().len(), 2);
        fs::remove_file(path).unwrap();
    }

    #[test]
    fn persists_a_burst_of_150_frames_in_one_batch() {
        let path = temporary_path();
        let mut queue = FrameQueue::load(path.clone()).unwrap();
        let frames: Vec<FrameEvent> = (0..150)
            .map(|number| frame(&format!("frame-{number}")))
            .collect();

        queue.push_batch(&frames).unwrap();
        assert_eq!(queue.len(), 150);
        assert_eq!(FrameQueue::load(path.clone()).unwrap().len(), 150);
        for frame in &frames {
            assert!(queue.ack(&frame.id).unwrap());
        }
        assert_eq!(FrameQueue::load(path.clone()).unwrap().len(), 0);
        fs::remove_file(path).unwrap();
    }

    #[test]
    fn imports_legacy_json_queue() {
        let path = temporary_path();
        let legacy = path.with_extension("json");
        fs::write(
            &legacy,
            serde_json::to_vec(&vec![frame("frame-1")]).unwrap(),
        )
        .unwrap();

        let recovered = FrameQueue::load(path.clone()).unwrap();
        assert_eq!(recovered.len(), 1);
        assert!(!legacy.exists());
        assert_eq!(FrameQueue::load(path.clone()).unwrap().len(), 1);
        fs::remove_file(path).unwrap();
    }
}
