use std::{
    io::{ErrorKind, Read, Write},
    sync::{Arc, Mutex},
    thread,
    time::Duration,
};

use chrono::Utc;
use crossbeam_channel::{unbounded, Receiver, Sender};
use serialport::SerialPort;
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

pub fn available_ports() -> Vec<String> {
    match serialport::available_ports() {
        Ok(ports) => ports.into_iter().map(|port| port.port_name).collect(),
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
                incoming.extend_from_slice(&buffer[..bytes_read]);

                for hex in drain_frames(&mut incoming) {
                    let frame = FrameEvent {
                        id: Uuid::new_v4().to_string(),
                        hex,
                        received_at: Utc::now().to_rfc3339(),
                    };

                    {
                        let mut queue = context.queue.lock().expect("queue mutex");
                        queue.push(frame.clone())?;
                        update_state(&context, |state| {
                            state.queued_frames = queue.len();
                            state.last_frame_hex = Some(frame.hex.clone());
                        });
                    }

                    let _ = context.events.send(AgentEvent::Frame(frame));
                    broadcast_status(&context);
                }
            }
            Ok(_) => {}
            Err(error) if error.kind() == ErrorKind::TimedOut => {}
            Err(error) => return Err(error.into()),
        }
    }
}

pub fn drain_frames(incoming: &mut Vec<u8>) -> Vec<String> {
    let mut frames = Vec::new();

    while incoming.len() >= FRAME_LENGTH {
        let frame: Vec<u8> = incoming.drain(..FRAME_LENGTH).collect();
        frames.push(bytes_to_hex(&frame));
    }

    frames
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
    fn drains_complete_three_byte_frames() {
        let mut incoming = vec![0x20, 0x81, 0xa1, 0x20, 0x91, 0xb1, 0xff];
        let frames = drain_frames(&mut incoming);

        assert_eq!(frames, vec!["2081a1", "2091b1"]);
        assert_eq!(incoming, vec![0xff]);
    }
}
