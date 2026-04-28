# Master plan — move serial out of Livewire, productionize for conferences

> **Status**: ready to execute. Supersedes `2026-04-28-serial-electron-main-refactor-plan.md` (which pre-dated the PR #8 hardware test) and consolidates everything we've learned across PRs #1–#8 plus the production-readiness research.
>
> **Trigger fired 2026-04-28**: PR #8 is on `main`, `$this->skipRender()` is live on `syncRemainingSeconds`, and Dušan still reports identical symptoms on hardware (Q2+ first-press lost, "Pripojené" status flickers, "Odpojiť" unresponsive). The Web-Serial-in-Livewire integration cannot be patched into reliability — it must be replaced.

---

## 1. Bug history & what we know now

### What we shipped (all merged into `main`)

| PR  | Change                                                               | On hardware? |
|-----|----------------------------------------------------------------------|--------------|
| #1  | 150ms buffer drain + Esc/click-outside on results modal              | Partial — fixed initial bugs              |
| #2  | Isolated SQLite uploads (uniqid filename + table-presence check)     | N/A — different concern                   |
| #3  | `voting.finished_at` stamping when last question closes              | N/A — admin/audit                         |
| #4  | Init device once per connection, not per question                    | Aligns with Dušan's stated design         |
| #5  | Pest tests for `DeviceController` import paths                       | Net positive                              |
| #6  | Design intent moved to `docs/design-intent.md`                       | Net positive                              |
| #7  | GitHub Actions CI (Pint + Vite + Pest)                               | Net positive                              |
| #8  | `skipRender()` on `syncRemainingSeconds` + deterministic flush + always-queue | **Did not fix the symptoms** |

### What Dušan reports on `main` after #8

- First voting works correctly
- Q2+ loses ~5s of first presses; they appear in a batch at the 5s mark
- Visual flicker on "Pauza" / "Ukončiť otázku" buttons at the 5s mark (enable→disable→enable)
- **Visual flicker on the "Pripojené" status text** — this is a smoking gun (text is hardcoded "Nepripojené" in Blade; only a Livewire DOM morph can revert it)
- "Odpojiť" button doesn't respond (suspected: same JS-state-stuck-true symptom)
- No runtime errors in DevTools (just CSS preload warnings, irrelevant)

### What this rules out

- `syncRemainingSeconds` is **not** the 5s morph trigger (we patched it; flicker remains)
- The race-conditions I hypothesized in the JS state machine — patching the obvious ones (deterministic flush, always-queue) didn't move the needle
- The serial buffer drain isn't the issue (already in PR #1, behaviour same regardless)

### What's still possible (we cannot prove which from code-reading alone)

| Hypothesis                                                                          | Probability | Distinguishing test                                                |
|-------------------------------------------------------------------------------------|-------------|--------------------------------------------------------------------|
| Some other `wire:poll` we haven't found is firing every 5s                          | Low         | `grep -rn 'wire:poll'` returned nothing on the operator side       |
| Electron/Chromium Web Serial driver buffers bytes for ~5s on Windows                | High        | Direct measurement: `console.log` byte arrival time in `readData()`|
| Livewire 3 internal heartbeat / connection keepalive at 5s morphs the component     | Medium      | Network tab inspection during a Q2 start                           |
| Operator's own `componentWire.recordVoteFromCode` is queued behind a stuck request  | Low         | Network tab inspection                                             |
| Some hardware-level latency in the Qomo transmitter when re-enabling collector      | Low         | Tested against a different transmitter                             |

**Critical insight**: regardless of which hypothesis is correct, **all of them go away if the serial reader runs in the Electron main process via Node's `serialport` library**. The fragile boundary is Web Serial ↔ Chromium ↔ Electron renderer ↔ Livewire morphing. Removing that boundary removes the bug class.

---

## 2. Decision: option B — move serial to Electron main process

We are committing to this path. PR #8 demonstrated that the Livewire+@script+Web Serial pattern is unsalvageable for real-time hardware integration on Windows under Electron. Throwing more patches at it is sunk cost.

### What we keep (do NOT throw away)

- Laravel 12 + Livewire 3 + NativePHP/Electron stack
- SQLite as local DB
- Domain model: `Voting → VotingQuestion → VotingOption / Vote`, `Device`, `VotingAttendee` with weight
- DB-level vote dedup: `unique(voting_question_id, device_id)` + `updateOrCreate`
- `runtime_*` state machine on `votings` for crash recovery
- Operator console + presentation view split
- Per-question status state machine (`draft|live|paused|closed`)
- Slovak UX
- Lenient imports + design intent (per `docs/design-intent.md`)
- All 61 existing Pest tests
- The CI we just shipped

### What we replace

- Web Serial API in `voting-console.blade.php` `@script` block (650 LOC)
- `state` object as JS-side source of truth for serial connectivity
- `componentWire.recordVoteFromCode()` Livewire AJAX as the per-vote hot path
- The legacy `app/Livewire/SerialCommunication.php` + `serial-communication.blade.php`

### Replaced with

- **Node serial helper** (`electron/serial-helper/index.js`) using `serialport@13` + `express`, spawned by NativePHP main process at app boot
- **HTTP/JSON IPC** over `127.0.0.1` with bearer-token auth
- **`vote_events` append-only audit table** as both audit trail and IPC sink
- **`wire:poll.500ms` on the results panel** during live questions for UI updates (cheap because re-renders are now data-driven, not state-driven)
- **`VoteRecorder` service** extracted from `VotingConsole` — pure, testable, no Livewire dependency

---

## 3. Target architecture

```
┌────────────────────────────────────────────────────────────────────────┐
│  Electron main process (NativePHP-managed)                             │
│                                                                        │
│  ┌─────────────────────────────────┐    ┌────────────────────────────┐ │
│  │ Laravel (PHP-FPM)               │    │ Node serial-helper         │ │
│  │  - Livewire VotingConsole       │    │  - opens COM port          │ │
│  │  - SerialFrameController        │◄──►│  - sends init/start/stop   │ │
│  │  - SerialControlController      │    │    hex commands            │ │
│  │  - VoteRecorder service         │    │  - reads + parses 3-byte   │ │
│  │  - VoteEvent model              │    │    frames                  │ │
│  │  - SQLite (votes, vote_events)  │    │  - POSTs frames to Laravel │ │
│  └────────────┬────────────────────┘    └────────────────────────────┘ │
│               │                                                        │
│               │ HTTP/JSON localhost (127.0.0.1)                        │
│               │   POST /internal/serial-frame    (Node → Laravel)      │
│               │   POST /internal/serial-control  (Laravel → Node)      │
│               │                                                        │
│  ┌────────────▼────────────────────────────────────────────────────┐   │
│  │ Renderer (Chromium) — Livewire console + presentation          │   │
│  │  - wire:poll.500ms on results panel (only while collector on)  │   │
│  │  - No Web Serial. No @script. No JS state machine.             │   │
│  │  - Connect/Disconnect/Start buttons → wire:click → Livewire    │   │
│  │    methods → forward to Node helper via control plane          │   │
│  └────────────────────────────────────────────────────────────────┘   │
└────────────────────────────────────────────────────────────────────────┘
```

### Why this is reliable

- The serial reader is a normal Node process. `serialport` library has been production-battle-tested for 10+ years across Windows/macOS/Linux. Direct native bindings, no Chromium intermediary.
- Each frame is a simple HTTP POST — sub-millisecond on localhost. No request batching, no Livewire snapshot serialization, no morphing.
- The UI doesn't need to know serial is happening. It polls a results panel every 500ms during a live question. That's it.
- Failure modes are debuggable: helper crashes → process supervisor restarts; Laravel crashes → helper logs failed POSTs; either side can be inspected with standard tooling.

---

## 4. IPC contract

### Auth

Single bearer token generated at app boot (32-byte hex), written to `storage/framework/serial-helper.token`. Both sides read it. Requests without correct `X-Internal-Token` header → 401. Token regenerates on each cold start.

### Node → Laravel: `POST /internal/serial-frame`

```json
{
  "hex": "208a3f",
  "received_at": "2026-04-28T13:42:11.123Z"
}
```

Response (informational; helper logs but doesn't act on it):
```json
{
  "accepted": true,
  "device_number": "001",
  "button": "A",
  "voting_question_id": 42,
  "rejection_reason": null
}
```

### Laravel → Node: `POST /control` (port written to `storage/framework/serial-helper.port` at boot)

```json
{ "command": "list_ports" }
{ "command": "open", "port_path": "COM3" }
{ "command": "init" }
{ "command": "start" }
{ "command": "stop" }
{ "command": "close" }
```

`list_ports` response:
```json
{ "ports": [
    { "path": "COM3", "manufacturer": "Prolific", "vendor_id": "067B" }
  ]
}
```

Other commands return `{ "ok": true }` or `{ "ok": false, "error": "..." }`.

### Network safety

Both endpoints use a `LocalhostOnly` middleware that aborts any request from non-loopback. Combined with the bearer token, this is sufficient for single-machine IPC. (Per-Dušan's design — local Windows machine, no internet at the conference venue.)

---

## 5. Phased implementation

Each phase is **independently shippable**. If we hit a blocker in phase N, phases 1..N-1 are still valuable on their own.

### Phase 0 — preflight (½ day)

**Risk-reduction work that must happen first.**

- Create a tiny throwaway Node script using `serialport@13`. Open COM3 (or whatever Dušan's Qomo enumerates as on his Windows box), send the init bytes from `docs/design-intent.md`, log received frames. Confirm:
  1. `serialport`'s prebuilt native bindings match Electron's bundled Node ABI on Windows. If not, document `electron-rebuild` step.
  2. Frames arrive at the same cadence as via Web Serial (rules out the "Web Serial buffers for 5s" hypothesis empirically).
  3. The hex parser ports cleanly from the existing `processIncomingMessages` JS to Node.
- **Decision gate**: if `serialport` works and frames arrive in real-time, proceed. If frames are still 5s-buffered in Node, the issue is below the JS layer entirely (Windows driver / Qomo firmware) and we have a deeper investigation, not a refactor.

**Deliverable**: throwaway script + a 2-paragraph note in `.claude/research/` confirming what we measured.

### Phase 1 — extract `VoteRecorder` service (½ day, **independently shippable**)

Pure refactor. No behaviour change. No Livewire change.

- New: `app/Services/Voting/VoteRecorder.php`
- New: `app/Services/Voting/VoteRecordingResult.php` (DTO with the same shape as the current return array)
- Move the inner logic of `VotingConsole::recordVoteFromCode` (lines 243–341) into `VoteRecorder::record(string $code, Voting $voting, VotingQuestion $question): VoteRecordingResult`
- `VotingConsole::recordVoteFromCode` becomes 5 lines: resolve current question, delegate to service, copy `lastMatchedDeviceNumber` / `lastButtonName` / `lastVoteMessage` from result onto `$this`, return result-as-array

**Tests**: add `tests/Unit/Services/Voting/VoteRecorderTest.php` — pure unit tests for the service (no Livewire, no HTTP). All existing `VotingConsoleTest` tests still pass.

**PR**: standalone. Ship it regardless of whether anything else lands. Net positive: testable in isolation, no Livewire required to exercise vote-recording logic.

### Phase 2 — `vote_events` append-only audit table (½ day, **independently shippable**)

Pulled forward from production-readiness research. Useful even if we never ship the helper.

- New migration: `database/migrations/YYYY_MM_DD_HHMMSS_create_vote_events_table.php`
  ```php
  Schema::create('vote_events', function (Blueprint $table) {
      $table->id();
      $table->foreignId('voting_id')->constrained()->cascadeOnDelete();
      $table->foreignId('voting_question_id')->nullable()->constrained()->nullOnDelete();
      $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
      $table->string('raw_hex', 16);
      $table->string('source', 16);  // 'web-serial' | 'node-helper' | 'manual'
      $table->boolean('accepted')->default(false);
      $table->string('rejection_reason')->nullable();
      $table->timestampTz('received_at')->index();
      $table->timestamps();
  });
  ```
- New: `app/Models/VoteEvent.php` with relationships
- `VoteRecorder::record()` writes one row per call, regardless of accept/reject

**Tests**: feature tests asserting one event row per call, accepted + rejected paths covered, timestamps preserved.

**PR**: standalone. Audit trail is a conference-day insurance policy — even one accidental power-cycle mid-vote and Dušan can replay the events to recover state.

### Phase 3 — Node serial helper (2 days)

The big one.

- New directory: `electron/serial-helper/` (sibling to existing NativePHP electron resources)
- `package.json` declaring `serialport@13`, `express@4`, `node-fetch@3`
- `index.js` (~250 LOC):
  - On startup: read `storage/framework/serial-helper.token` and `serial-helper.laravel_port`
  - Spawn Express server on a free port; write port to `storage/framework/serial-helper.port`
  - Maintain `state = { port: null, isOpen: false, isCollecting: false, byteBuffer: [] }`
  - Implement `/control` POST endpoint with the 6 commands above
  - On `start`, install reader: `port.on('data', chunk => parseFrames(chunk))`
  - `parseFrames` ports the 3-byte frame logic from JS line-for-line (it's already correct, just re-host it)
  - For each parsed frame: `fetch('http://127.0.0.1:LARAVEL_PORT/internal/serial-frame', { method: POST, body: JSON.stringify(frame) })`
  - Log every action to `storage/logs/serial-helper.log` with rotating filenames
  - SIGTERM handler: close port, exit cleanly
- Modify `app/Providers/NativeAppServiceProvider.php` to spawn the helper via `Native\Laravel\Facades\ChildProcess::start('serial-helper', ['node', resource_path('../electron/serial-helper/index.js')])`
- Existing Windows shutdown cleanup work in commit `2436b18` covers the helper teardown

**Tests**: a tiny Node test using `serialport`'s `mock-binding` to simulate frame arrival, assert HTTP POST shape. Run in CI behind a `node-helper` job.

**PR**: ships the helper but does NOT cut UI over to it yet. Behind feature flag `config('serial.driver') === 'node-helper'`. Old Web Serial path still default.

### Phase 4 — Laravel internal endpoints (½ day)

- New: `app/Http/Middleware/LocalhostOnly.php` — abort 403 if request IP not loopback
- New: `app/Http/Controllers/Internal/SerialFrameController.php` — invokable, takes `VoteRecorder`, resolves current voting/question from DB, delegates to service, returns result as JSON
- New: `app/Http/Controllers/Internal/SerialControlController.php` — proxies to helper's `/control` endpoint
- Routes registered in `routes/web.php` under a `Route::middleware(['localhost-only', 'internal-token'])` group
- New: `app/Http/Middleware/InternalToken.php` — checks `X-Internal-Token` against `storage/framework/serial-helper.token`

**Tests**: Pest feature tests posting JSON, asserting:
- Auth: 401 without token, 401 with wrong token, 200 with correct token
- Localhost-only: 403 from non-loopback (mock the request)
- Frame recording: vote_events row written, Vote row written when accepted
- Token: rotates correctly on app boot

**PR**: ships endpoints + tests. Still feature-flagged off by default.

### Phase 5 — UI cutover (1.5 days)

The visible win.

- Remove the entire `@script` block from `voting-console.blade.php` (lines 226–880, ~650 LOC)
- Remove all `wire:ignore` regions that exist solely to protect JS state
- Remove `data-usb-indicator`, `data-usb-status`, `data-collector-status`, etc. — all driven by Blade now
- Add `wire:poll.500ms` on the results panel — only while `$collectorEnabled` is true (Livewire 3 supports conditional polling: `wire:poll.500ms="$refresh"`)
- Connect button → `wire:click="connectSerial()"` → Livewire method calls `SerialControlController::open` via internal HTTP
- Disconnect button → `wire:click="disconnectSerial()"` → ditto with `close`
- Start question button: existing `startQuestion()` Livewire method, but no longer needs to coordinate with frontend JS — just sets state and posts `start` command to helper
- Port picker: a simple `<select>` populated from `list_ports` response, refresh button next to it

**Tests**: existing 19 `VotingConsoleTest` tests must continue to pass (they exercise `recordVoteFromCode` which still exists, just thinner). Add a feature test that simulates the full flow with the helper mocked via `Http::fake()`.

**PR**: this is the cutover. Default driver becomes `node-helper`. Web Serial path can stay behind the flag for one release as fallback, then deleted in Phase 6.

### Phase 6 — cleanup (½ day)

- Delete `app/Livewire/SerialCommunication.php` and `resources/views/livewire/serial-communication.blade.php` (legacy, removed from routes by previous auth-removal work)
- Delete the Web Serial fallback path; `config('serial.driver')` becomes a constant
- Update `docs/technical-overview.md` to reflect new architecture (the section on "Komponenty a zodpovednosti" is now half wrong)
- Add new section to `docs/design-intent.md`:
  > **Sériový reader beží v Electron main procese, nie v Livewiru.** Pôvodná implementácia mala Web Serial API priamo vo `<script>` bloku Livewire komponenty, ktorá zlyhávala pri Q2+ kvôli race conditions medzi JS state a Livewire morph cyklom. Reader je teraz Node proces (`electron/serial-helper/`), ktorý komunikuje s Laravelom cez localhost HTTP. **Neprerábať späť do Blade view bez zámeru** — bola to bolesť, ktorú nechceme zopakovať.
- Delete the now-stale `serial-communication.blade.php` reference in CLAUDE.md project notes

**PR**: cleanup. Ship after one week of operating on the new path without issues.

---

## 6. Total timeline

| Day  | Work                                          | Shippable?              |
|------|-----------------------------------------------|-------------------------|
| 1    | Phase 0 preflight + Phase 1 service extract   | Phase 1 PR              |
| 2    | Phase 2 vote_events table                     | Phase 2 PR              |
| 3–4  | Phase 3 Node helper                           | Phase 3 PR (flag-gated) |
| 5    | Phase 4 Laravel endpoints + tests             | Phase 4 PR              |
| 6    | Phase 5 UI cutover                            | Phase 5 PR (the big one)|
| 7    | Phase 6 cleanup + docs                        | Phase 6 PR              |

**Total: 6–7 working days**, six small-to-medium PRs that Dušan can review one at a time. If at any point we discover a blocker, every prior phase is already on `main` and net-positive.

---

## 7. Risk register

| Risk                                                               | Likelihood | Impact | Mitigation                                                                    |
|--------------------------------------------------------------------|------------|--------|-------------------------------------------------------------------------------|
| `serialport` ABI mismatch on Windows under Electron                | Medium     | High   | Phase 0 preflight verifies. `electron-rebuild` is documented escape hatch.    |
| Phase 0 reveals frames are still 5s-buffered in Node               | Low        | High   | Stop. Investigate Windows driver / Qomo. Don't refactor on a wrong premise.   |
| Helper crashes mid-vote                                            | Low        | Medium | NativePHP ChildProcess auto-restart. `vote_events` captures frames already POSTed.|
| Helper's localhost POST blocked by Windows firewall                | Low        | High   | Loopback traffic is exempt by default. Smoke-test on Win10/11 in Phase 0.     |
| `wire:poll.500ms` causes flicker on slow machine                   | Low        | Low    | Re-renders are data-driven, no JS state to clobber. If perceptible, swap to Reverb (overkill but available). |
| Two helpers race on same COM port                                  | Low        | Medium | Single-instance lock via PID file. Helper bails on startup if file exists.    |
| Conference-day operator can't debug                                | Medium     | Low    | `tail -f storage/logs/serial-helper.log` is sufficient.                       |
| Estimate slips                                                     | Medium     | Medium | Phases 1, 2, 4, 6 each independently shippable. Phase 3 + 5 are the long pole.|
| Dušan tests Phase 3 on hardware and frames don't match Web Serial timing exactly | Low | Medium | Hex parser is line-for-line ported. Cross-check frame log between Web Serial and helper. |

---

## 8. Open questions for Dušan

These influence small decisions in the plan. Answers in passing are enough.

1. **Iné non-Qomo USB-serial hardvér** na tom istom Windows počítači? (Driver port-picker UX scope)
2. **Licencia `serialport@13` (MIT)** je OK pre projekt?
3. **CSV export `vote_events`** priamo z operátorskej konzoly (pridáva 1h do Phase 2), alebo stačí cez SQLite Studio v debug móde?
4. **Vždy len 1 operátorský počítač** na konferencii, alebo môžu byť viacerí súčasne? (Driver localhost-only assumption permanence)
5. **Aký Node ABI verziu** má jeho Electron build (NativePHP 2.1)? Potrebujeme to vedieť pre `serialport` prebuilt binaries.

---

## 9. Status of in-flight work

- All 8 PRs merged to `main`. Branch state is clean.
- Open: PR #6 (`docs/design-intent.md`), PR #7 (CI), PR #8 (defensive fix). Per `gh pr list` they're all marked merged. Confirmed.
- No work-in-progress branches blocking this plan.

---

## 10. Success criteria

The refactor is **done** when:

- ✅ `voting-console.blade.php` is < 350 lines (currently 870)
- ✅ Zero `wire:ignore` regions in `voting-console.blade.php` for state-protection purposes (only acceptable wire:ignore: legitimate static UI like the question list during edit-disabled state)
- ✅ `php artisan test --compact` passes 65+ tests (existing 61 + at minimum 4 new internal-route tests)
- ✅ CI is green
- ✅ 5+ consecutive questions on Qomo with **zero lost first-presses**
- ✅ "Pripojené" status text doesn't flicker
- ✅ "Odpojiť" responds within 200ms of click
- ✅ `vote_events` table contains a complete row-per-frame audit log for a test session, exportable as CSV
- ✅ `docs/technical-overview.md` reflects the new architecture
- ✅ `docs/design-intent.md` documents why serial lives in Electron main

---

## 11. Rollback plan

If at any point the new path proves worse than the old:

- Phases 1, 2, 4 are pure additions. Keep them; they're useful regardless.
- Phase 3 helper is feature-flagged via `config('serial.driver')`. Flip back to `web` and the Web Serial path is live again.
- Phase 5 UI cutover is the only destructive change. The deletion of the `@script` block lives on a single commit; revert is `git revert` of one PR.

Worst case: 1-PR revert restores the pre-refactor state. We're never more than one commit away from rollback.

---

## 12. What this plan deliberately does NOT do

- ❌ Rewrite in a new language/framework (Tauri/Rust, Phoenix LiveView, etc.). Throws away 10 years of conference-tested edge-case knowledge.
- ❌ Replace Livewire with Inertia/Vue/React. Livewire works fine for everything except real-time hardware. Don't replace what works.
- ❌ Add Reverb / Echo / WebSocket broadcasting. Overkill for single-machine local app. `wire:poll.500ms` is sufficient.
- ❌ Add Redis / queue layer. Same reason.
- ❌ Tighten the lenient import behaviour or any other design-intent decision. Per `docs/design-intent.md`, those are features.
- ❌ Add new Laravel auth. App is intentionally unauthenticated single-operator.
- ❌ Modify the `runtime_*` crash recovery mechanism on `votings`. It works.
- ❌ Touch the presentation view's `console-state-updated` event contract. It works.

---

## 13. Decision log

- **2026-04-28**: PR #8 demonstrated that incremental patching of the Web-Serial-in-Livewire flow doesn't yield reliable behaviour on hardware. Pivoting to architectural refactor.
- **2026-04-28**: Chose option B (Node helper in Electron main) over option C (separate `.exe` agent talking to Laravel via WebSocket). Rationale: option B reuses NativePHP's existing process management, no new daemon to install, unified app distribution.
- **2026-04-28**: Chose HTTP/JSON over Unix socket / named pipe / stdin/stdout pipe. Rationale: standard tooling for debugging, language-agnostic, sub-millisecond on localhost, easy to mock in tests.

---

**Next action**: await Dušan's answers to the 5 open questions, then start Phase 0 preflight. Phase 1 (`VoteRecorder` extraction) can run in parallel — pure refactor, zero blockers.
