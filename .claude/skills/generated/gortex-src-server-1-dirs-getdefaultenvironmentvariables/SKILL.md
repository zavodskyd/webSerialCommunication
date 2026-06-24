---
name: gortex-src-server-1-dirs-getdefaultenvironmentvariables
description: "Work in the src/server +1 dirs · getDefaultEnvironmentVariables area — 56 symbols across 5 files (99% cohesion)"
---

# src/server +1 dirs · getDefaultEnvironmentVariables

56 symbols | 5 files | 99% cohesion

## When to Use

Use this skill when working on files in:
- `_ide_helper.php`
- `nativephp/electron/electron-plugin/src/server/ProcessResult.ts`
- `nativephp/electron/electron-plugin/src/server/index.ts`
- `nativephp/electron/electron-plugin/src/server/php.ts`
- `nativephp/electron/electron-plugin/src/server/utils.ts`

## Key Files

| File | Symbols |
|------|---------|
| `_ide_helper.php` | set |
| `nativephp/electron/electron-plugin/src/server/ProcessResult.ts` | ProcessResult |
| `nativephp/electron/electron-plugin/src/server/index.ts` | killScheduler, result, startPhpApp, runScheduler |
| `nativephp/electron/electron-plugin/src/server/php.ts` | secret, options, getDefaultEnvironmentVariables, phpOptions, phpIniSettings, ... |
| `nativephp/electron/electron-plugin/src/server/utils.ts` | appendCookie, cookie |

## Entry Points

- `nativephp/electron/electron-plugin/src/server/php.ts::serveApp`

## Connected Communities

- **src/server +1 dirs · getPhpPort** (1 cross-edges)

## How to Explore

```
get_communities with id: "community-102"
smart_context with task: "understand src/server +1 dirs · getDefaultEnvironmentVariables", format: "gcx"
find_usages with id: "nativephp/electron/electron-plugin/src/server/php.ts::serveApp", format: "gcx"
```

_`format: "gcx"` returns the [GCX1 compact wire format](../../docs/wire-format.md) — round-trippable, ~27% fewer tokens than JSON. Drop it for JSON output; agents using `@gortex/wire` or the Go `github.com/gortexhq/gcx-go` package decode either._
