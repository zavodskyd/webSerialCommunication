---
name: gortex-libs-menubar-1-dirs
description: "Work in the libs/menubar +1 dirs area — 44 symbols across 3 files (98% cohesion)"
---

# libs/menubar +1 dirs

44 symbols | 3 files | 98% cohesion

## When to Use

Use this skill when working on files in:
- ``
- `nativephp/electron/electron-plugin/src/libs/menubar/Menubar.ts`
- `nativephp/electron/electron-plugin/src/libs/menubar/index.ts`

## Key Files

| File | Symbols |
|------|---------|
| `` | existsSync, join, fs |
| `nativephp/electron/electron-plugin/src/libs/menubar/Menubar.ts` | _positioner, clicked, key, app, _app, ... |
| `nativephp/electron/electron-plugin/src/libs/menubar/index.ts` | options, menubar |

## Entry Points

- `nativephp/electron/electron-plugin/src/libs/menubar/Menubar.ts::Menubar.constructor`

## How to Explore

```
get_communities with id: "community-87"
smart_context with task: "understand libs/menubar +1 dirs", format: "gcx"
find_usages with id: "nativephp/electron/electron-plugin/src/libs/menubar/Menubar.ts::Menubar.constructor", format: "gcx"
```

_`format: "gcx"` returns the [GCX1 compact wire format](../../docs/wire-format.md) — round-trippable, ~27% fewer tokens than JSON. Drop it for JSON output; agents using `@gortex/wire` or the Go `github.com/gortexhq/gcx-go` package decode either._
