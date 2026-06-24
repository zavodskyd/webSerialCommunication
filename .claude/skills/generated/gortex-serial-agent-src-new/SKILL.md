---
name: gortex-serial-agent-src-new
description: "Work in the serial-agent/src · new area — 39 symbols across 4 files (88% cohesion)"
---

# serial-agent/src · new

39 symbols | 4 files | 88% cohesion

## When to Use

Use this skill when working on files in:
- `serial-agent/src/main.rs`
- `serial-agent/src/queue.rs`
- `serial-agent/src/serial_worker.rs`
- `serial-agent/src/ui.rs`

## Key Files

| File | Symbols |
|------|---------|
| `serial-agent/src/main.rs` | main |
| `serial-agent/src/queue.rs` | persist, FrameQueue, ack_removes_frame_from_queue, path, push, ... |
| `serial-agent/src/serial_worker.rs` | hex, drain_frames, bytes_to_hex, incoming, hex_to_bytes, ... |
| `serial-agent/src/ui.rs` | ui, disconnect, connect, logic, _frame, ... |

## Entry Points

- `serial-agent/src/ui.rs::SerialAgentApp.ui`
- `serial-agent/src/main.rs::main`
- `serial-agent/src/queue.rs::ack_removes_frame_from_queue`

## Connected Communities

- **serial-agent/src · run_worker** (2 cross-edges)
- **. +1 dirs · run_server** (1 cross-edges)
- **serial-agent/src · ServerMessage** (1 cross-edges)

## How to Explore

```
get_communities with id: "community-126"
smart_context with task: "understand serial-agent/src · new", format: "gcx"
find_usages with id: "serial-agent/src/ui.rs::SerialAgentApp.ui", format: "gcx"
```

_`format: "gcx"` returns the [GCX1 compact wire format](../../docs/wire-format.md) — round-trippable, ~27% fewer tokens than JSON. Drop it for JSON output; agents using `@gortex/wire` or the Go `github.com/gortexhq/gcx-go` package decode either._
