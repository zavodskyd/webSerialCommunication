use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(tag = "type", rename_all = "snake_case")]
pub enum ClientMessage {
    Hello { token: String },
    Command { id: String, command: AgentCommand },
    Ack { id: String },
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum AgentCommand {
    Start,
    Stop,
    Close,
    Health,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(tag = "type", rename_all = "snake_case")]
pub enum ServerMessage {
    HelloOk {
        agent_version: String,
        connected: bool,
    },
    CommandResult {
        id: String,
        ok: bool,
        error: Option<String>,
    },
    Frame {
        id: String,
        hex: String,
        received_at: String,
    },
    Status {
        connected: bool,
        collecting: bool,
        selected_port: Option<String>,
        queued_frames: usize,
    },
    Error {
        error: String,
    },
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct FrameEvent {
    pub id: String,
    pub hex: String,
    pub received_at: String,
}

impl From<FrameEvent> for ServerMessage {
    fn from(frame: FrameEvent) -> Self {
        Self::Frame {
            id: frame.id,
            hex: frame.hex,
            received_at: frame.received_at,
        }
    }
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct StatusPayload {
    pub connected: bool,
    pub collecting: bool,
    pub selected_port: Option<String>,
    pub queued_frames: usize,
}

impl From<StatusPayload> for ServerMessage {
    fn from(status: StatusPayload) -> Self {
        Self::Status {
            connected: status.connected,
            collecting: status.collecting,
            selected_port: status.selected_port,
            queued_frames: status.queued_frames,
        }
    }
}

#[derive(Debug, Clone)]
pub enum AgentEvent {
    Frame(FrameEvent),
    Status(StatusPayload),
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parses_start_command() {
        let message: ClientMessage = serde_json::from_str(
            r#"{"type":"command","id":"abc","command":"start"}"#,
        )
        .unwrap();

        match message {
            ClientMessage::Command { id, command } => {
                assert_eq!(id, "abc");
                assert!(matches!(command, AgentCommand::Start));
            }
            _ => panic!("expected command"),
        }
    }

    #[test]
    fn serializes_frame_event() {
        let frame = ServerMessage::from(FrameEvent {
            id: "frame-1".to_string(),
            hex: "2081a1".to_string(),
            received_at: "2026-04-28T12:00:00Z".to_string(),
        });

        let json = serde_json::to_string(&frame).unwrap();

        assert!(json.contains(r#""type":"frame""#));
        assert!(json.contains(r#""hex":"2081a1""#));
    }
}
