# Serial Agent

Standalone native serial gateway for the NativePHP voting app.

This first phase intentionally builds only the agent. Laravel integration is deferred until the agent is verified on Windows with the real Qomo transceiver.

## Runtime

- Opens a small native UI for selecting a serial port.
- Opens a localhost WebSocket server on `127.0.0.1:0`.
- Writes the chosen WebSocket port to `STORAGE_PATH/framework/serial-agent.port`.
- Requires an `INTERNAL_TOKEN` WebSocket hello before accepting commands.
- Reads 3-byte serial frames while collecting.

## Development

```bash
cargo run --manifest-path serial-agent/Cargo.toml
```

For local manual testing without Laravel:

```bash
STORAGE_PATH=/tmp/serial-agent-storage INTERNAL_TOKEN=dev-token cargo run --manifest-path serial-agent/Cargo.toml
```

## Windows Build

The production binary must be built and tested on Windows:

```powershell
cargo build --manifest-path serial-agent/Cargo.toml --release --target x86_64-pc-windows-msvc
```

The resulting executable should be copied to:

```text
extras/serial-agent/serial-agent.exe
```

NativePHP integration is intentionally not part of this phase.
