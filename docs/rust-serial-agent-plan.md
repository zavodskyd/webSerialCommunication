# Rust Win64 Serial Agent Plan

## Summary

The voting application will move serial communication out of Livewire, Web Serial, and Electron Node modules into a standalone native Win64 process: `serial-agent.exe`.

The first implementation phase is intentionally limited to the agent itself. Laravel integration will be added only after the agent is verified on Windows with the real Qomo transceiver.

## Target Boot Flow

```text
Laravel NativePHP boot
-> NativeAppServiceProvider starts serial-agent.exe
-> serial-agent.exe opens its own UI
-> user selects a serial device and confirms Connect
-> serial-agent opens the USB transceiver and runs the init handshake
-> Laravel communicates with serial-agent over localhost WebSocket
-> serial-agent sends parsed vote frames to Laravel
```

## Agent Responsibilities

- Run as a standalone Win64 native app.
- Show a small UI for:
  - listing serial ports,
  - refreshing the port list,
  - selecting a port,
  - connecting/disconnecting,
  - showing connected/collecting/status information.
- Open the transceiver with `28800 8N1`.
- Run the Qomo init handshake once per connection:
  - `f400c00236`
  - `f500000101f5`
  - `f54b4e050200000601f0`
- Expose a WebSocket server on `127.0.0.1:0`.
- Write the selected WebSocket port to `STORAGE_PATH/framework/serial-agent.port`.
- Require an `INTERNAL_TOKEN` handshake before accepting commands.
- Accept `start`, `stop`, `close`, and `health` commands over WebSocket.
- Parse serial input into exact 3-byte frames and send those frames to Laravel over WebSocket events.
- Queue frames on disk until Laravel acknowledges them.

## WebSocket Contract

Endpoint:

```text
ws://127.0.0.1:{port}/ws
```

Authentication:

```json
{ "type": "hello", "token": "..." }
```

Success:

```json
{ "type": "hello_ok", "agent_version": "...", "connected": false }
```

Commands:

```json
{ "type": "command", "id": "uuid", "command": "start" }
{ "type": "command", "id": "uuid", "command": "stop" }
{ "type": "command", "id": "uuid", "command": "close" }
{ "type": "command", "id": "uuid", "command": "health" }
```

Command result:

```json
{ "type": "command_result", "id": "uuid", "ok": true, "error": null }
```

Frame event:

```json
{ "type": "frame", "id": "uuid", "hex": "2081a1", "received_at": "ISO-8601" }
```

Ack:

```json
{ "type": "ack", "id": "uuid" }
```

Status event:

```json
{ "type": "status", "connected": true, "collecting": false, "selected_port": "COM3", "queued_frames": 0 }
```

## GitHub CI Requirement

Before Laravel is wired to this agent, GitHub Actions must include a native Windows build job for the Rust agent.

Required CI behavior:

- Run on `windows-latest`.
- Install stable Rust toolchain.
- Build `serial-agent` for `x86_64-pc-windows-msvc`.
- Run Rust tests.
- Upload `serial-agent.exe` as an artifact.
- Fail the PR if the Windows build fails.

Reason: the production problem is Windows-specific. Cross-building or only checking the agent on macOS is not enough proof that serial access, native UI, and the final `.exe` work correctly on the deployment target.

## Phase 1 Acceptance Criteria

- `serial-agent` project exists and builds on Windows.
- Agent UI opens on Windows.
- Agent lists COM ports.
- Agent connects to the Qomo transceiver and runs the init handshake.
- WebSocket auth rejects invalid tokens.
- `start` and `stop` commands write the expected transceiver control bytes.
- First button press after `start` is emitted immediately as a 3-byte frame.
- Q1 and Q2+ can be manually verified on Windows without losing the first press.

## Deferred Until Agent Is Verified

- NativePHP boot integration.
- Laravel bridge command.
- Livewire console `rust-agent` mode.
- Replacing the production driver.
- Removing or deprecating the old `node-helper` path.
