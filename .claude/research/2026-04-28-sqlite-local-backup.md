# SQLite local backup — periodic snapshots

App is local, single-user, offline. Goal: protect against PC crash, user mistake (accidental "delete voting"), or disk corruption between sessions. No cloud, no replication, no sidecar process. Just snapshot the SQLite file periodically.

## 1. When to snapshot

Tied to operator workflow, not wall-clock cron. What Dušan needs to recover from:

- **App boot** — snapshot the previous session's final state before any new writes. One snapshot per launch.
- **Voting session start** — `VotingConsole::mount` when entering an active console. Captures the pre-event baseline so a botched voting doesn't poison the seed.
- **Voting session finish** — when `voting.finished_at` is stamped (PR #3). The "definitive results" snapshot.
- **Every 5 min while live** — Laravel scheduler. Insurance for power loss mid-session.

Skip per-press snapshots — too noisy, no recovery story benefits over the 5-min cadence.

## 2. How to snapshot safely

SQLite's `VACUUM INTO 'path.sqlite'` is the right primitive: atomic, consistent across WAL writers (no need to checkpoint first), produces a defragmented copy. Single-statement, runs in milliseconds for this DB size.

```php
// app/Console/Commands/VotingBackup.php
#[AsCommand('voting:backup', 'Snapshot the SQLite DB to the backups directory.')]
class VotingBackup extends Command
{
    public function handle(BackupManager $backups): int
    {
        $path = $backups->snapshot();           // returns absolute path
        $backups->prune(keep: 30);
        $this->info('Snapshot: ' . basename($path));
        return self::SUCCESS;
    }
}
```

`BackupManager::snapshot()`:

```php
$file = sprintf('voting-%s.sqlite', now()->format('Y-m-d-His'));
$path = $this->backupDir() . DIRECTORY_SEPARATOR . $file;
DB::statement('VACUUM INTO ?', [$path]);
return $path;
```

`VACUUM INTO` does NOT lock writers (per SQLite docs) — it reads through MVCC. Correct primitive for a live DB.

## 3. Where to store

- Dev: `storage_path('backups')`.
- Production (NativePHP): user data path. NativePHP exposes `storage_path()` already mapped to `%APPDATA%/Hlasovanie/storage` on Windows — same call, no branching needed.
- Filename: `voting-YYYY-MM-DD-HHmmss.sqlite` (sortable lex order = chronological).
- Add `storage/backups/` to `.gitignore`.

## 4. Retention

Keep last 30. Prune logic:

```php
public function prune(int $keep): void
{
    $files = collect(glob($this->backupDir() . '/voting-*.sqlite'))
        ->sortDesc()
        ->slice($keep);
    $files->each(fn ($f) => @unlink($f));
}
```

30 × ~50MB upper bound = 1.5GB max. Trim to 10 if disk is a concern.

## 5. Restore UX

Operator-facing, two-tier:

- **Footer button "Otvoriť priečinok záloh"** — `Native\Laravel\Facades\Shell::openFolder($path)`. Always works, no UI complexity. Ship this first.
- **Optional later: "Obnoviť zo zálohy" modal** — list snapshots with timestamp + size, click → confirm → app copies the chosen file over `database.sqlite` and restarts via `Native\Laravel\Facades\App::restart()`. Don't build this until Dušan asks.

## 6. Wiring — recommended combo

Best: **(a) Livewire hooks on session start/end + (b) Laravel scheduler every 5 min while live**.

- (a) `VotingConsole::mount` and the `voting:close` flow each dispatch `dispatch_sync(new SnapshotJob())` or simply call `BackupManager::snapshot()` directly. Inline is fine — VACUUM INTO is cheap and idempotent.
- (b) `routes/console.php`:
  ```php
  Schedule::command('voting:backup')
      ->everyFiveMinutes()
      ->when(fn () => Voting::whereNotNull('current_voting_question_id')->exists());
  ```
  Skips when nothing's live. NativePHP runs the scheduler tick automatically.
- (c) NativePHP background job — overkill, the scheduler tick is identical with less code.

App-boot snapshot: a one-liner in `NativeAppServiceProvider::boot()` after the DB bootstrapper, gated behind `config('nativephp-internal.running')`.

## 7. What NOT to do

- **`cp database.sqlite backup.sqlite`** — not crash-consistent under WAL. Mid-write copy gives a torn DB.
- **`sqlite3 .dump | sqlite3 backup.sqlite`** — text format, slow, loses indexes' physical layout. Wrong tool.
- **Litestream** — overkill for offline desktop. Streaming replication needs a sidecar process and gives PITR you don't need; the user's own 5-min cadence is sufficient.
- **Backup the WAL/SHM files separately** — `VACUUM INTO` already produces a self-contained file with all committed state.

## 8. Test plan

1. Run `php artisan voting:backup`. Confirm a file exists, > 0 bytes.
2. Open the snapshot with `sqlite3 backup.sqlite "PRAGMA integrity_check;"` → must print `ok`.
3. Replace `database.sqlite` with the snapshot, restart app, navigate to `/votings` → verify all votings/questions/devices load identically to pre-restore state.
4. Run during an active voting (collector enabled) — confirm no `database is locked` errors in `storage/logs/laravel.log`, votes continue arriving normally.
5. Run prune with `keep=3` against 5 fake snapshots, confirm only 3 remain.

Pest feature test: `tests/Feature/VotingBackupTest.php` — invoke command on a seeded DB, assert file exists, integrity_check via `DB::connection()->getPdo()->query()`.

## 9. Effort

**~3 hours.** `BackupManager` class + artisan command + scheduler entry + `Shell::openFolder()` button + one feature test. No new dependencies.
