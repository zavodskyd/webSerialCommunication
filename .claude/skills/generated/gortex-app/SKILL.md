---
name: gortex-app
description: "Work in the . · App area — 143 symbols across 1 files (100% cohesion)"
---

# . · App

143 symbols | 1 files | 100% cohesion

## When to Use

Use this skill when working on files in:
- `_ide_helper.php`

## Key Files

| File | Symbols |
|------|---------|
| `_ide_helper.php` | getLoadedProviders, beforeBootstrapping, hasMacro, afterResolving, loadEnvironmentFrom, ... |

## How to Explore

```
get_communities with id: "community-1"
smart_context with task: "understand . · App", format: "gcx"
```

_`format: "gcx"` returns the [GCX1 compact wire format](../../docs/wire-format.md) — round-trippable, ~27% fewer tokens than JSON. Drop it for JSON output; agents using `@gortex/wire` or the Go `github.com/gortexhq/gcx-go` package decode either._
