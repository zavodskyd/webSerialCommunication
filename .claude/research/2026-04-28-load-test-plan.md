# Load test plan — conference scale

Goal: prove the new architecture (Qomo → Node helper → `POST /internal/serial-frame` → `VoteRecorder` → SQLite) survives ~200 devices voting in a 30s window. That is **6.67 frames/sec sustained**, not all serialized — Qomo bursts arrive as the crowd presses simultaneously, so peaks of ~20 frames in a 100ms window are realistic.

## What to measure

1. **End-to-end latency** from POST acceptance to `Vote` row committed — p50, p95, p99. Target: p99 < 50ms on the operator's laptop.
2. **SQLite WAL throughput** under sustained 6 writes/sec mixed with 2 reads/sec (`wire:poll.500ms` on the operator panel). Watch for `database is locked` (`SQLITE_BUSY`).
3. **`firstOrCreate` contention on `voting_attendees`** — `VoteRecorder::record` calls it on every frame for unmatched attendees. Under burst load, many concurrent `INSERT … OR IGNORE` paths can stall. Pre-seed all 200 attendees before the test to skip this; then re-run with empty `voting_attendees` to measure cold-path cost.
4. **Operator browser re-render cost** — with `wire:poll.500ms` rendering a 200-row results panel, measure DevTools Performance tab Long Task count and main-thread time per poll.

## Tooling

- **`wrk`** — best fit. Single binary, Lua scripting for rotating bodies, native percentiles via `--latency`. Already on Homebrew. Use this.
- `vegeta` — also fine, body file per request gets clunky with 200 rotating hex codes.
- Custom Node script — only needed if we want timing of the Node helper itself; for the Laravel hot path `wrk` is enough.

## `wrk` invocation

`scripts/loadtest/frames.lua`:

```lua
math.randomseed(os.time())
local codes = {}
for line in io.lines("scripts/loadtest/codes.txt") do table.insert(codes, line) end
local token = os.getenv("INTERNAL_TOKEN")

wrk.method = "POST"
wrk.headers["Content-Type"] = "application/json"
wrk.headers["X-Internal-Token"] = token

request = function()
  local hex = codes[math.random(#codes)]
  return wrk.format("POST", "/internal/serial-frame", nil,
    string.format('{"hex":"%s","received_at":"%s"}', hex, os.date("!%Y-%m-%dT%H:%M:%S.000Z")))
end
```

Generate `codes.txt` by exporting `code_a` through `code_f` from `devices` for the test voting.

```bash
INTERNAL_TOKEN=$(cat storage/framework/serial-helper.token) \
  wrk -t2 -c10 -d30s -R200 \
      -s scripts/loadtest/frames.lua \
      --latency \
      http://127.0.0.1:8000
```

`-R200` gives a constant-throughput model (200 req/s, generous overshoot). `-c10` simulates burst fan-in. Report p50/p99 from `--latency` output and any non-2xx tally.

## Pass/fail gates

- p99 < 50ms, no `SQLITE_BUSY` in `storage/logs/laravel.log`, no `accepted=false` with `rejection_reason=record_failed` (would imply lock contention bubbling up).
- Operator browser stays interactive — DevTools Long Task count < 3 across the 30s window.

## Notes

- Run against `php artisan serve` first (single-worker baseline). Then NativePHP's bundled FrankenPHP/PHP-FPM to verify the production path.
- `votes` table has unique `(voting_question_id, device_id)`. Random hex selection means most frames hit `updateOrCreate`'s update branch — that's the realistic path, keep it.
