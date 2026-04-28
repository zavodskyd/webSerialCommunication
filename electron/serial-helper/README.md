# Voting serial helper

Node process spawned by NativePHP's main process. Reads bytes from the Qomo
USB-serial transmitter and POSTs each parsed 3-byte frame to Laravel.

This replaces the previous Web Serial API implementation that lived inside a
Livewire `@script` block in `voting-console.blade.php`. See
`docs/design-intent.md` ("Sériový reader v Electron main, nie v Blade view")
for context — do not refactor it back into a Blade view.

## Install

From the repo root:

```bash
cd electron/serial-helper
npm install
```

If you're running under Electron and the prebuilt `serialport` bindings don't
match your Electron version's Node ABI, rebuild them:

```bash
cd electron/serial-helper
npx electron-rebuild -f -w serialport
```

This typically only matters on Windows; macOS and Linux usually have matching
prebuilds.

## Run standalone (debug)

```bash
LARAVEL_BASE=$(pwd) \
LARAVEL_URL=http://127.0.0.1:8101 \
node electron/serial-helper/index.js
```

The helper:

1. Reads its bearer token from `storage/framework/serial-helper.token`
   (Laravel writes this on boot).
2. Listens on a free localhost port and writes that port to
   `storage/framework/serial-helper.port`.
3. Accepts control commands at `POST /control` (token required).
4. POSTs each received 3-byte frame to `{LARAVEL_URL}/internal/serial-frame`.

Logs go to `storage/logs/serial-helper.log` (JSON-per-line) and stdout.

## Control plane

| Command       | Body                                | Effect                                                  |
|---------------|-------------------------------------|---------------------------------------------------------|
| `list_ports`  | `{}`                                | Returns available USB-serial ports                      |
| `open`        | `{ port_path: "COM3" }`             | Opens the named port (28800 baud, 8N1, no flow control) |
| `init`        | `{}`                                | Sends Qomo init handshake (`f400…`, `f500…`, `f54b…`)   |
| `start`       | `{}`                                | Drains stale bytes, sends `5b80db` + `5a80da` to enable collector |
| `stop`        | `{}`                                | Sends `5b80db` to disable collector                     |
| `close`       | `{}`                                | Closes the port                                         |

All commands require `X-Internal-Token` header matching the contents of
`storage/framework/serial-helper.token`.

## Design notes

- The helper has **no concept of votings or questions**. It just forwards
  every parsed 3-byte frame to Laravel. Laravel decides whether to accept
  the vote (per `VoteRecorder` service).
- The helper does **not** have a code lookup table — Laravel's `Device`
  model is the authority on which hex codes belong to which device.
- The `start` command drains `incomingBytes` before sending the enable
  command. This prevents echo frames from the previous question's tail
  from arriving as the next question's first vote.
- Every action is logged. If something goes wrong on conference day,
  `tail -f storage/logs/serial-helper.log` is the diagnostic.
- The helper is feature-flagged off by default. Set `SERIAL_DRIVER=node-helper`
  in `.env` to switch the operator console over to the helper-driven flow.
  Default is `web-serial` (current behaviour preserved).
