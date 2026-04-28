use eframe::egui;

use crate::{
    serial_worker::{self, SerialCommand, SerialPortChoice},
    AgentContext,
};

pub struct SerialAgentApp {
    context: AgentContext,
    ports: Vec<SerialPortChoice>,
    selected_port: Option<String>,
}

impl SerialAgentApp {
    pub fn new(context: AgentContext) -> Self {
        let ports = serial_worker::available_ports();
        let selected_port = ports.first().map(|port| port.port_name.clone());

        Self {
            context,
            ports,
            selected_port,
        }
    }

    fn refresh_ports(&mut self) {
        self.ports = serial_worker::available_ports();

        if self
            .selected_port
            .as_ref()
            .map_or(true, |selected| {
                !self.ports.iter().any(|port| port.port_name == *selected)
            })
        {
            self.selected_port = self.ports.first().map(|port| port.port_name.clone());
        }
    }

    fn connect(&mut self) {
        let Some(port_name) = self.selected_port.clone() else {
            return;
        };

        let is_connected = self
            .context
            .shared
            .lock()
            .map(|state| state.connected)
            .unwrap_or(false);

        if is_connected {
            return;
        }

        let tx = serial_worker::spawn(port_name, self.context.clone());

        if let Ok(mut state) = self.context.shared.lock() {
            state.command_tx = Some(tx);
        }
    }

    fn disconnect(&self) {
        let tx = self
            .context
            .shared
            .lock()
            .ok()
            .and_then(|state| state.command_tx.clone());

        if let Some(tx) = tx {
            let _ = tx.send(SerialCommand::Close);
        }
    }
}

impl eframe::App for SerialAgentApp {
    fn update(&mut self, context: &egui::Context, _frame: &mut eframe::Frame) {
        context.request_repaint_after(std::time::Duration::from_millis(250));

        let snapshot = self.context.shared.lock().ok().map(|state| {
            (
                state.connected,
                state.collecting,
                state.selected_port.clone(),
                state.status.clone(),
                state.queued_frames,
                state.ws_port,
                state.last_frame_hex.clone(),
            )
        });

        let (
            connected,
            collecting,
            connected_port,
            status,
            queued_frames,
            ws_port,
            last_frame_hex,
        ) = snapshot.unwrap_or((false, false, None, "State unavailable".to_string(), 0, None, None));

        egui::CentralPanel::default().show(context, |ui| {
            ui.heading("Serial Agent");
            ui.label("Qomo voting transceiver gateway");
            ui.separator();

            ui.horizontal(|ui| {
                ui.label("WebSocket:");
                match ws_port {
                    Some(port) => {
                        ui.monospace(format!("127.0.0.1:{port}/ws"));
                    }
                    None => {
                        ui.label("starting...");
                    }
                }
            });

            ui.horizontal(|ui| {
                ui.label("Status:");
                ui.strong(status);
            });

            ui.horizontal(|ui| {
                ui.label("Connected:");
                ui.label(if connected { "yes" } else { "no" });
                ui.label("Collecting:");
                ui.label(if collecting { "yes" } else { "no" });
            });

            if let Some(port) = connected_port {
                ui.horizontal(|ui| {
                    ui.label("Port:");
                    ui.monospace(port);
                });
            }

            ui.horizontal(|ui| {
                ui.label("Queued frames:");
                ui.label(queued_frames.to_string());
            });

            if let Some(hex) = last_frame_hex {
                ui.horizontal(|ui| {
                    ui.label("Last frame:");
                    ui.monospace(hex);
                });
            }

            ui.separator();

            ui.horizontal(|ui| {
                if ui.button("Refresh").clicked() {
                    self.refresh_ports();
                }

                let selected_text = self
                    .selected_port
                    .as_ref()
                    .and_then(|selected| {
                        self.ports
                            .iter()
                            .find(|port| port.port_name == *selected)
                            .map(|port| port.label.clone())
                    })
                    .unwrap_or_else(|| "No serial ports found".to_string());

                egui::ComboBox::from_label("Serial device")
                    .selected_text(selected_text)
                    .show_ui(ui, |ui| {
                        for port in &self.ports {
                            ui.selectable_value(
                                &mut self.selected_port,
                                Some(port.port_name.clone()),
                                &port.label,
                            );
                        }
                    });
            });

            ui.horizontal(|ui| {
                let can_connect = !connected && self.selected_port.is_some();

                if ui
                    .add_enabled(can_connect, egui::Button::new("Connect"))
                    .clicked()
                {
                    self.connect();
                }

                if ui
                    .add_enabled(connected, egui::Button::new("Disconnect"))
                    .clicked()
                {
                    self.disconnect();
                }
            });

            ui.separator();
            ui.label("Select the Qomo USB serial transceiver here. The voting app controls start/stop over WebSocket.");
        });
    }
}
