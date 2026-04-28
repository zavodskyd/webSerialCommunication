# NativePHP versioning + auto-update

## How the comparison works

electron-updater (v6.7.3, vendored under NativePHP 2.1) parses the current app version with `semver.parse(this.app.version)` and compares against the latest release's `version` field with `semver.gt` / `semver.eq` / `semver.lt`. Source: `vendor/nativephp/desktop/resources/electron/node_modules/electron-updater/out/AppUpdater.js:212-217, 340-358`. **Strict semver** — `1.0.0+abc123` (build metadata after `+`) is **ignored** by precedence per the semver spec, so build hashes don't break comparison but also don't differentiate versions.

`this.app.version` resolves to `app.getVersion()` which reads from the **bundled `package.json`** at runtime, NOT from `config('nativephp.version')`. The PHP config value flows in only at build time: `electron-builder.mjs` reads `process.env.NATIVEPHP_APP_VERSION` and writes it into `extraMetadata.version` (`vendor/nativephp/desktop/resources/electron/electron-builder.mjs:10, 132`), which electron-builder then injects into the packaged `package.json`.

So the chain is: `config/nativephp.php` → `NATIVEPHP_APP_VERSION` env var (read by `php artisan native:bundle` during build) → `extraMetadata.version` → packaged `package.json` → `app.getVersion()` → electron-updater compare.

## Auto-incrementing the version on every build

`config/nativephp.php:11` already supports env: `'version' => env('NATIVEPHP_APP_VERSION', '1.0.0')`. Two paths:

1. **CI-driven** (recommended): GitHub Actions sets `NATIVEPHP_APP_VERSION` from the git tag (`vX.Y.Z`) before invoking `php artisan native:bundle`. Tag the release, version is the tag minus the `v`. No file mutation needed.
2. **Local prebuild bump**: a `php artisan native:bump-version` command that mutates `.env` (writes `NATIVEPHP_APP_VERSION=X.Y.Z+1`). Risk: developer commits a stale `.env`. Skip unless CI isn't available.

## Does `StampBuildVersion` need to update `config/nativephp.php`?

**No.** `StampBuildVersion` writes to `bootstrap/cache/build-version.txt` (via `BuildVersion::stampFilePath()`) for the operator-console footer display. That's a UI-only stamp — `BASE+SHA-COMMITTS.BUILDTS`. The base it reads (`config('nativephp.version')`) is the same value the build pipeline pumps into `NATIVEPHP_APP_VERSION`. As long as the env var is set before `native:bundle`, the auto-updater and the UI stamp share the same base and stay in sync.

The `+SHA-…` suffix in the stamp is build metadata in semver terms — purely informational, ignored by version comparison. That's correct: the updater compares clean semver, the operator sees the SHA for diagnostics.

## Recommended pattern

1. Drop the hardcoded `1.0.0` default; require `NATIVEPHP_APP_VERSION` to be set in CI release jobs. Fail-loud if missing.
2. Keep `StampBuildVersion` exactly as-is — it's the correct shape (semver base + build metadata).
3. Add to the prebuild hook list (`config/nativephp.php:174`):
   ```php
   'prebuild' => [
       'php artisan native:prepare-seed-database',
       'php artisan build:stamp-version',
   ],
   ```
   No `bump-version` step needed if CI sets the env var.
4. Operator-console footer should render `BuildVersion::current()` (the stamp). The auto-updater compares against the underlying `NATIVEPHP_APP_VERSION`. Both sourced from the same release tag → no drift.

## Concrete recommendation

**Skip `php artisan native:bump-version`.** It adds a mutation step that's easy to forget and easy to commit. Instead: tag releases, have CI export `NATIVEPHP_APP_VERSION=${GITHUB_REF_NAME#v}` before `native:bundle`. Single source of truth. The existing `StampBuildVersion` already gives the operator the build hash for in-field diagnosis.

## Sources

- electron-updater AppUpdater.js: `vendor/nativephp/desktop/resources/electron/node_modules/electron-updater/out/AppUpdater.js:212-358`
- electron-builder: `vendor/nativephp/desktop/resources/electron/electron-builder.mjs:10, 132`
- semver precedence rules: https://semver.org/#spec-item-10 (build metadata ignored)
- electron-updater docs: https://www.electron.build/auto-update
- NativePHP updater docs: https://nativephp.com/docs/desktop/2/digging-deeper/auto-updates
