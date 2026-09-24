use std::{
    io::{ErrorKind, Read, Write},
    sync::{Arc, Mutex},
    thread,
    time::{Duration, Instant},
};

use chrono::Utc;
use crossbeam_channel::{unbounded, Receiver, RecvTimeoutError, Sender};
use serialport::{SerialPort, SerialPortType};
use uuid::Uuid;

use crate::{
    protocol::{AgentEvent, FrameEvent},
    AgentContext,
};

const SERIAL_BAUD_RATE: u32 = 28_800;
const FRAME_LENGTH: usize = 3;
const HEX_INIT: [&str; 3] = ["f400c00236", "f500000101f5", "f54b4e050200000601f0"];
const HEX_START: [&str; 2] = ["5b80db", "5a80da"];
const HEX_STOP: &str = "5b80db";

#[derive(Debug, Clone)]
pub enum SerialCommand {
    Start,
    Stop,
    Close,
    Health,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct SerialPortChoice {
    pub port_name: String,
    pub label: String,
}

pub fn available_ports() -> Vec<SerialPortChoice> {
    match serialport::available_ports() {
        Ok(ports) => ports
            .into_iter()
            .map(|port| {
                let label = match &port.port_type {
                    SerialPortType::UsbPort(info) => {
                        let product = info
                            .product
                            .as_deref()
                            .or(info.manufacturer.as_deref())
                            .unwrap_or("USB serial device");
                        let serial = info
                            .serial_number
                            .as_deref()
                            .map(|value| format!(" SN {value}"))
                            .unwrap_or_default();

                        format!(
                            "{} - {} (VID {:04X}, PID {:04X}{})",
                            port.port_name, product, info.vid, info.pid, serial
                        )
                    }
                    SerialPortType::BluetoothPort => {
                        format!("{} - Bluetooth serial device", port.port_name)
                    }
                    SerialPortType::PciPort => {
                        format!("{} - PCI serial device", port.port_name)
                    }
                    SerialPortType::Unknown => {
                        format!("{} - Serial device", port.port_name)
                    }
                };

                SerialPortChoice {
                    port_name: port.port_name,
                    label,
                }
            })
            .collect(),
        Err(error) => {
            tracing::warn!(%error, "failed to list serial ports");
            Vec::new()
        }
    }
}

pub fn spawn(port_name: String, context: AgentContext) -> Sender<SerialCommand> {
    let (tx, rx) = unbounded();
    let worker_tx = tx.clone();

    thread::spawn(move || {
        if let Err(error) = run_worker(port_name.clone(), context.clone(), rx) {
            tracing::error!(%error, %port_name, "serial worker failed");
            update_state(&context, |state| {
                state.connected = false;
                state.collecting = false;
                state.command_tx = None;
                state.status = format!("Serial error: {error}");
            });
            broadcast_status(&context);
        }
    });

    worker_tx
}

fn run_worker(
    port_name: String,
    context: AgentContext,
    rx: Receiver<SerialCommand>,
) -> anyhow::Result<()> {
    update_state(&context, |state| {
        state.status = format!("Opening {port_name}...");
        state.selected_port = Some(port_name.clone());
        state.connected = false;
        state.collecting = false;
    });
    broadcast_status(&context);

    let mut port = serialport::new(&port_name, SERIAL_BAUD_RATE)
        .data_bits(serialport::DataBits::Eight)
        .parity(serialport::Parity::None)
        .stop_bits(serialport::StopBits::One)
        .timeout(Duration::from_millis(25))
        .open()?;

    for hex in HEX_INIT {
        write_hex(&mut port, hex)?;
    }

    update_state(&context, |state| {
        state.connected = true;
        state.collecting = false;
        state.status = format!("Connected to {port_name}");
    });
    broadcast_status(&context);

    let mut incoming = Vec::new();
    let mut buffer = [0_u8; 64];

    loop {
        while let Ok(command) = rx.try_recv() {
            match command {
                SerialCommand::Start => {
                    incoming.clear();

                    for hex in HEX_START {
                        write_hex(&mut port, hex)?;
                    }

                    update_state(&context, |state| {
                        state.collecting = true;
                        state.status = "Collecting votes".to_string();
                    });
                    broadcast_status(&context);
                }
                SerialCommand::Stop => {
                    write_hex(&mut port, HEX_STOP)?;
                    let deadline = Instant::now() + Duration::from_millis(250);
                    let mut quiet_reads = 0;
                    while quiet_reads < 2 && Instant::now() < deadline {
                        match port.read(&mut buffer) {
                            Ok(bytes_read) if bytes_read > 0 => {
                                enqueue_bytes(&mut incoming, &buffer[..bytes_read], &context)?;
                                quiet_reads = 0;
                            }
                            Ok(_) => quiet_reads += 1,
                            Err(error) if error.kind() == ErrorKind::TimedOut => quiet_reads += 1,
                            Err(error) => return Err(error.into()),
                        }
                    }
                    incoming.clear();
                    update_state(&context, |state| {
                        state.collecting = false;
                        state.status = "Collection stopped".to_string();
                    });
                    broadcast_status(&context);
                }
                SerialCommand::Close => {
                    let _ = write_hex(&mut port, HEX_STOP);
                    update_state(&context, |state| {
                        state.connected = false;
                        state.collecting = false;
                        state.command_tx = None;
                        state.status = "Disconnected".to_string();
                    });
                    broadcast_status(&context);

                    return Ok(());
                }
                SerialCommand::Health => {
                    broadcast_status(&context);
                }
            }
        }

        let collecting = context
            .shared
            .lock()
            .map(|state| state.collecting)
            .unwrap_or(false);

        if !collecting {
            thread::sleep(Duration::from_millis(20));
            continue;
        }

        match port.read(&mut buffer) {
            Ok(bytes_read) if bytes_read > 0 => {
                enqueue_bytes(&mut incoming, &buffer[..bytes_read], &context)?;
            }
            Ok(_) => {}
            Err(error) if error.kind() == ErrorKind::TimedOut => {}
            Err(error) => return Err(error.into()),
        }
    }
}

fn enqueue_bytes(
    incoming: &mut Vec<u8>,
    bytes: &[u8],
    context: &AgentContext,
) -> anyhow::Result<()> {
    incoming.extend_from_slice(bytes);
    let frames: Vec<FrameEvent> = drain_frames(incoming)
        .into_iter()
        .map(|hex| FrameEvent {
            id: Uuid::new_v4().to_string(),
            hex,
            received_at: Utc::now().to_rfc3339(),
        })
        .collect();

    if let Some(last) = frames.last() {
        update_state(context, |state| {
            state.pending_frames += frames.len();
            state.queued_frames += frames.len();
            state.last_frame_hex = Some(last.hex.clone());
        });
    }

    for frame in frames {
        context.frame_tx.send(frame)?;
    }

    Ok(())
}

pub fn persist_frames(receiver: Receiver<FrameEvent>, context: AgentContext) -> anyhow::Result<()> {
    while let Ok(first) = receiver.recv() {
        let mut batch = vec![first];
        let deadline = Instant::now() + Duration::from_millis(25);
        while batch.len() < 256 {
            let remaining = deadline.saturating_duration_since(Instant::now());
            if remaining.is_zero() {
                break;
            }
            match receiver.recv_timeout(remaining) {
                Ok(frame) => batch.push(frame),
                Err(RecvTimeoutError::Timeout) => break,
                Err(RecvTimeoutError::Disconnected) => break,
            }
        }

        persist_batch(&context, batch)?;
    }

    Ok(())
}

fn persist_batch(context: &AgentContext, batch: Vec<FrameEvent>) -> anyhow::Result<()> {
    let queue_length = {
        let mut queue = context.queue.lock().expect("queue mutex");
        queue.push_batch(&batch)?;
        queue.len()
    };
    update_state(context, |state| {
        state.pending_frames = state.pending_frames.saturating_sub(batch.len());
        state.queued_frames = queue_length + state.pending_frames;
    });

    for frame in batch {
        let _ = context.events.send(AgentEvent::Frame(frame));
    }
    broadcast_status(context);
    Ok(())
}

pub fn drain_frames(incoming: &mut Vec<u8>) -> Vec<String> {
    let mut frames = Vec::new();
    let mut offset = 0;

    while incoming.len() - offset >= FRAME_LENGTH {
        let frame = &incoming[offset..offset + FRAME_LENGTH];

        if is_valid_frame(frame) {
            frames.push(bytes_to_hex(frame));
            offset += FRAME_LENGTH;
        } else {
            offset += 1;
        }
    }

    incoming.drain(..offset);

    frames
}

fn is_valid_frame(frame: &[u8]) -> bool {
    (0x20..=0x2f).contains(&frame[0])
        && matches!(
            frame[1] & 0xf0,
            0x80 | 0x90 | 0xa0 | 0xb0 | 0xc0 | 0xd0 | 0xe0
        )
        && frame[0] ^ frame[1] == frame[2]
}

fn write_hex(port: &mut Box<dyn SerialPort>, hex: &str) -> anyhow::Result<()> {
    let bytes = hex_to_bytes(hex)?;
    port.write_all(&bytes)?;
    port.flush()?;

    Ok(())
}

fn hex_to_bytes(hex: &str) -> anyhow::Result<Vec<u8>> {
    let normalized = hex.trim();

    if normalized.len() % 2 != 0 {
        anyhow::bail!("hex string has odd length: {normalized}");
    }

    (0..normalized.len())
        .step_by(2)
        .map(|index| {
            u8::from_str_radix(&normalized[index..index + 2], 16)
                .map_err(|error| anyhow::anyhow!("invalid hex {normalized}: {error}"))
        })
        .collect()
}

fn bytes_to_hex(bytes: &[u8]) -> String {
    bytes
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect::<Vec<_>>()
        .join("")
}

fn update_state(context: &AgentContext, update: impl FnOnce(&mut crate::SharedState)) {
    if let Ok(mut state) = context.shared.lock() {
        update(&mut state);
    }
}

fn broadcast_status(context: &AgentContext) {
    let status = context
        .shared
        .lock()
        .map(|state| state.status_payload())
        .ok();

    if let Some(status) = status {
        let _ = context.events.send(AgentEvent::Status(status));
    }
}

#[allow(dead_code)]
fn _assert_send_sync(_: Arc<Mutex<crate::SharedState>>) {}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn buffers_and_persists_150_frames_before_publishing_them() {
        let path =
            std::env::temp_dir().join(format!("serial-agent-burst-{}.journal", Uuid::new_v4()));
        let queue = Arc::new(Mutex::new(
            crate::queue::FrameQueue::load(path.clone()).unwrap(),
        ));
        let shared = Arc::new(Mutex::new(crate::SharedState::default()));
        let (frame_tx, frame_rx) = crossbeam_channel::bounded(4096);
        let (events, _) = tokio::sync::broadcast::channel(512);
        let mut event_rx = events.subscribe();
        let context = AgentContext {
            shared: Arc::clone(&shared),
            queue: Arc::clone(&queue),
            frame_tx,
            events,
            token: Arc::new(String::new()),
        };
        let mut bytes = Vec::new();
        for device in 0_u16..150 {
            let first = 0x20 + (device >> 4) as u8;
            let second = 0x80 | (device & 0x0f) as u8;
            bytes.extend_from_slice(&[first, second, first ^ second]);
        }

        enqueue_bytes(&mut Vec::new(), &bytes, &context).unwrap();
        assert_eq!(shared.lock().unwrap().queued_frames, 150);
        assert_eq!(shared.lock().unwrap().pending_frames, 150);
        assert_eq!(queue.lock().unwrap().len(), 0);
        assert!(event_rx.try_recv().is_err());

        persist_batch(&context, frame_rx.try_iter().collect()).unwrap();
        assert_eq!(shared.lock().unwrap().queued_frames, 150);
        assert_eq!(shared.lock().unwrap().pending_frames, 0);
        assert_eq!(queue.lock().unwrap().len(), 150);
        assert_eq!(
            crate::queue::FrameQueue::load(path.clone()).unwrap().len(),
            150
        );
        for _ in 0..150 {
            assert!(matches!(event_rx.try_recv().unwrap(), AgentEvent::Frame(_)));
        }
        std::fs::remove_file(path).unwrap();
    }

    #[test]
    fn drains_complete_three_byte_frames() {
        let mut incoming = vec![0x20, 0x81, 0xa1, 0x20, 0x91, 0xb1, 0xff];
        let frames = drain_frames(&mut incoming);

        assert_eq!(frames, vec!["2081a1", "2091b1"]);
        assert_eq!(incoming, vec![0xff]);
    }

    #[test]
    fn resynchronizes_after_missing_byte_before_repeated_press() {
        let mut incoming = vec![0x20, 0x81, 0x20, 0x81, 0xa1];

        assert_eq!(drain_frames(&mut incoming), vec!["2081a1"]);
        assert!(incoming.is_empty());
    }

    #[test]
    fn resynchronizes_after_inserted_byte_and_keeps_partial_frame() {
        let mut incoming = vec![0x20, 0x81, 0xa1, 0xff, 0x20, 0x91];

        assert_eq!(drain_frames(&mut incoming), vec!["2081a1"]);
        assert_eq!(incoming, vec![0x20, 0x91]);

        incoming.push(0xb1);
        assert_eq!(drain_frames(&mut incoming), vec!["2091b1"]);
        assert!(incoming.is_empty());
    }

    #[test]
    fn rejects_rotated_frames_even_when_xor_matches() {
        let mut incoming = vec![0x85, 0xa5, 0x20, 0x20, 0x85, 0xa5];

        assert_eq!(drain_frames(&mut incoming), vec!["2085a5"]);
        assert!(incoming.is_empty());
    }

    #[test]
    fn accepts_protocol_button_and_device_boundaries_only() {
        let mut incoming = vec![0x20, 0x80, 0xa0, 0x2f, 0xef, 0xc0];

        assert_eq!(drain_frames(&mut incoming), vec!["2080a0", "2fefc0"]);
        assert!(incoming.is_empty());

        let mut invalid = vec![0x30, 0x80, 0xb0, 0x20, 0xf0, 0xd0];
        assert!(drain_frames(&mut invalid).is_empty());
        assert_eq!(invalid.len(), 2);
    }

    #[test]
    fn accepts_every_device_and_supported_button() {
        let buttons = [0x80_u8, 0x90, 0xa0, 0xb0, 0xc0, 0xd0, 0xe0];
        let mut incoming = Vec::new();
        let mut expected = Vec::new();

        for device in 0_u16..=255 {
            for button in buttons {
                let first = 0x20 + (device >> 4) as u8;
                let second = button | (device & 0x0f) as u8;
                let frame = [first, second, first ^ second];
                incoming.extend_from_slice(&frame);
                expected.push(bytes_to_hex(&frame));
            }
        }

        assert_eq!(drain_frames(&mut incoming), expected);
        assert!(incoming.is_empty());
    }

    #[test]
    fn recovers_after_corrupted_checksum() {
        let mut incoming = vec![0x20, 0x81, 0xa0, 0x20, 0x81, 0xa1];

        assert_eq!(drain_frames(&mut incoming), vec!["2081a1"]);
        assert!(incoming.is_empty());
    }

    #[test]
    fn serial_port_choice_keeps_port_name_separate_from_label() {
        let choice = SerialPortChoice {
            port_name: "COM3".to_string(),
            label: "COM3 - USB serial device (VID 10C4, PID EA60)".to_string(),
        };

        assert_eq!(choice.port_name, "COM3");
        assert!(choice.label.contains("USB serial device"));
    }
}
