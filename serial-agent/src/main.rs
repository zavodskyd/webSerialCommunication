#![cfg_attr(target_os = "windows", windows_subsystem = "windows")]

mod protocol;
mod queue;
mod serial_worker;
mod ui;
mod ws;

use std::{
    env,
    path::PathBuf,
    sync::{Arc, Mutex},
    thread,
};

use anyhow::Context;
use crossbeam_channel::{bounded, Sender};
use protocol::{AgentEvent, FrameEvent};
use tokio::sync::broadcast;

use crate::{
    queue::FrameQueue,
    serial_worker::SerialCommand,
    ui::SerialAgentApp,
};

#[derive(Clone)]
pub struct AgentContext {
    pub shared: Arc<Mutex<SharedState>>,
    pub queue: Arc<Mutex<FrameQueue>>,
    pub frame_tx: Sender<FrameEvent>,
    pub events: broadcast::Sender<AgentEvent>,
    pub token: Arc<String>,
}

#[derive(Default)]
pub struct SharedState {
    pub connected: bool,
    pub collecting: bool,
    pub selected_port: Option<String>,
    pub status: String,
    pub queued_frames: usize,
    pub pending_frames: usize,
    pub ws_port: Option<u16>,
    pub command_tx: Option<Sender<SerialCommand>>,
    pub last_frame_hex: Option<String>,
}

impl SharedState {
    pub fn status_payload(&self) -> protocol::StatusPayload {
        protocol::StatusPayload {
            connected: self.connected,
            collecting: self.collecting,
            selected_port: self.selected_port.clone(),
            queued_frames: self.queued_frames,
        }
    }
}

fn main() -> anyhow::Result<()> {
    tracing_subscriber::fmt()
        .with_env_filter(tracing_subscriber::EnvFilter::from_default_env())
        .init();

    let storage_path = env::var("STORAGE_PATH")
        .map(PathBuf::from)
        .unwrap_or_else(|_| PathBuf::from("storage"));
    let token = env::var("INTERNAL_TOKEN").unwrap_or_else(|_| "dev-token".to_string());

    let framework_path = storage_path.join("framework");
    std::fs::create_dir_all(&framework_path)
        .with_context(|| format!("failed to create {}", framework_path.display()))?;

    let queue_path = framework_path.join("serial-agent-queue.journal");
    let port_path = framework_path.join("serial-agent.port");
    let queue = FrameQueue::load(queue_path)?;
    let queued_frames = queue.len();

    let shared = Arc::new(Mutex::new(SharedState {
        status: "Starting WebSocket server...".to_string(),
        queued_frames,
        ..SharedState::default()
    }));
    let queue = Arc::new(Mutex::new(queue));
    let (events, _) = broadcast::channel(512);
    let (frame_tx, frame_rx) = bounded(4096);

    let context = AgentContext {
        shared: Arc::clone(&shared),
        queue: Arc::clone(&queue),
        frame_tx,
        events: events.clone(),
        token: Arc::new(token),
    };

    let persistence_context = context.clone();
    thread::spawn(move || {
        if let Err(error) = serial_worker::persist_frames(frame_rx, persistence_context.clone()) {
            tracing::error!(%error, "serial frame persistence failed");
            if let Ok(mut state) = persistence_context.shared.lock() {
                state.status = format!("Serial persistence error: {error}");
                state.collecting = false;
                if let Some(command_tx) = &state.command_tx {
                    let _ = command_tx.send(SerialCommand::Stop);
                }
            }
        }
    });

    let server_context = context.clone();
    thread::spawn(move || {
        let runtime = tokio::runtime::Runtime::new().expect("tokio runtime");

        if let Err(error) = runtime.block_on(ws::run_server(server_context, port_path)) {
            tracing::error!(%error, "websocket server failed");
        }
    });

    let options = eframe::NativeOptions::default();

    eframe::run_native(
        "Serial Agent",
        options,
        Box::new(|_cc| Ok(Box::new(SerialAgentApp::new(context)))),
    )
    .map_err(|error| anyhow::anyhow!("eframe failed: {error}"))
}
