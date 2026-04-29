use std::{net::SocketAddr, path::PathBuf, time::Duration};

use anyhow::Context;
use axum::{
    extract::{
        ws::{Message, WebSocket, WebSocketUpgrade},
        State,
    },
    response::IntoResponse,
    routing::get,
    Router,
};
use futures_util::{SinkExt, StreamExt};
use tokio::{net::TcpListener, time};

use crate::{
    protocol::{AgentCommand, AgentEvent, ClientMessage, ServerMessage},
    serial_worker::SerialCommand,
    AgentContext,
};

pub async fn run_server(context: AgentContext, port_path: PathBuf) -> anyhow::Result<()> {
    let listener = TcpListener::bind("127.0.0.1:0").await?;
    let local_addr = listener.local_addr()?;

    write_port_file(&port_path, local_addr.port())?;

    if let Ok(mut state) = context.shared.lock() {
        state.ws_port = Some(local_addr.port());
        state.status = format!("WebSocket listening on {}", local_addr);
    }

    let _ = context.events.send(AgentEvent::Status(
        context.shared.lock().expect("shared mutex").status_payload(),
    ));

    let app = Router::new()
        .route("/ws", get(ws_handler))
        .with_state(context);

    axum::serve(listener, app).await?;

    Ok(())
}

async fn ws_handler(
    State(context): State<AgentContext>,
    upgrade: WebSocketUpgrade,
) -> impl IntoResponse {
    upgrade.on_upgrade(move |socket| handle_socket(socket, context))
}

async fn handle_socket(socket: WebSocket, context: AgentContext) {
    let (mut sender, mut receiver) = socket.split();

    let Some(Ok(Message::Text(raw_hello))) = receiver.next().await else {
        return;
    };

    match serde_json::from_str::<ClientMessage>(raw_hello.as_str()) {
        Ok(ClientMessage::Hello { token }) if token == *context.token => {}
        _ => {
            let _ = send_json(
                &mut sender,
                &ServerMessage::Error {
                    error: "invalid hello".to_string(),
                },
            )
            .await;

            return;
        }
    }

    let connected = context
        .shared
        .lock()
        .map(|state| state.connected)
        .unwrap_or(false);

    if send_json(
        &mut sender,
        &ServerMessage::HelloOk {
            agent_version: env!("CARGO_PKG_VERSION").to_string(),
            connected,
        },
    )
    .await
    .is_err()
    {
        return;
    }

    let pending = context.queue.lock().expect("queue mutex").pending();

    for frame in pending {
        if send_json(&mut sender, &ServerMessage::from(frame)).await.is_err() {
            return;
        }
    }

    let mut events = context.events.subscribe();
    let mut flush_interval = time::interval(Duration::from_secs(2));

    loop {
        tokio::select! {
            inbound = receiver.next() => {
                let Some(Ok(message)) = inbound else {
                    return;
                };

                if let Message::Text(text) = message {
                    if handle_client_message(&context, &mut sender, text.as_str()).await.is_err() {
                        return;
                    }
                }
            }
            event = events.recv() => {
                match event {
                    Ok(AgentEvent::Frame(frame)) => {
                        if send_json(&mut sender, &ServerMessage::from(frame)).await.is_err() {
                            return;
                        }
                    }
                    Ok(AgentEvent::Status(status)) => {
                        if send_json(&mut sender, &ServerMessage::from(status)).await.is_err() {
                            return;
                        }
                    }
                    Err(_) => return,
                }
            }
            _ = flush_interval.tick() => {
                let pending = context.queue.lock().expect("queue mutex").pending();

                for frame in pending {
                    if send_json(&mut sender, &ServerMessage::from(frame)).await.is_err() {
                        return;
                    }
                }
            }
        }
    }
}

async fn handle_client_message(
    context: &AgentContext,
    sender: &mut futures_util::stream::SplitSink<WebSocket, Message>,
    text: &str,
) -> anyhow::Result<()> {
    match serde_json::from_str::<ClientMessage>(text)? {
        ClientMessage::Command { id, command } => {
            let result = send_command(context, command);

            send_json(
                sender,
                &ServerMessage::CommandResult {
                    id,
                    ok: result.is_ok(),
                    error: result.err(),
                },
            )
            .await?;
        }
        ClientMessage::Ack { id } => {
            let queued_frames = {
                let mut queue = context.queue.lock().expect("queue mutex");
                let _ = queue.ack(&id)?;
                queue.len()
            };

            if let Ok(mut state) = context.shared.lock() {
                state.queued_frames = queued_frames;
            }
        }
        ClientMessage::Hello { .. } => {}
    }

    Ok(())
}

fn send_command(context: &AgentContext, command: AgentCommand) -> Result<(), String> {
    if matches!(command, AgentCommand::Health) {
        let status = context
            .shared
            .lock()
            .map_err(|_| "state lock failed".to_string())?
            .status_payload();
        let _ = context.events.send(AgentEvent::Status(status));

        return Ok(());
    }

    let tx = context
        .shared
        .lock()
        .map_err(|_| "state lock failed".to_string())?
        .command_tx
        .clone()
        .ok_or_else(|| "serial port not connected".to_string())?;

    let serial_command = match command {
        AgentCommand::Start => SerialCommand::Start,
        AgentCommand::Stop => SerialCommand::Stop,
        AgentCommand::Close => SerialCommand::Close,
        AgentCommand::Health => unreachable!(),
    };

    tx.send(serial_command)
        .map_err(|_| "serial worker not reachable".to_string())
}

async fn send_json(
    sender: &mut futures_util::stream::SplitSink<WebSocket, Message>,
    message: &ServerMessage,
) -> anyhow::Result<()> {
    let payload = serde_json::to_string(message)?;
    sender.send(Message::text(payload)).await?;

    Ok(())
}

fn write_port_file(path: &PathBuf, port: u16) -> anyhow::Result<()> {
    if let Some(parent) = path.parent() {
        std::fs::create_dir_all(parent)
            .with_context(|| format!("failed to create {}", parent.display()))?;
    }

    std::fs::write(path, port.to_string())
        .with_context(|| format!("failed to write {}", path.display()))
}

#[allow(dead_code)]
fn _assert_socket_addr(_: SocketAddr) {}
