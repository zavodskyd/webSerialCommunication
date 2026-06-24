---
name: gortex-url
description: "Work in the . · URL area — 45 symbols across 1 files (100% cohesion)"
---

# . · URL

45 symbols | 1 files | 100% cohesion

## When to Use

Use this skill when working on files in:
- `_ide_helper.php`

## Key Files

| File | Symbols |
|------|---------|
| `_ide_helper.php` | setRoutes, formatParameters, macro, hasValidSignature, withKeyResolver, ... |

## How to Explore

```
get_communities with id: "community-38"
smart_context with task: "understand . · URL", format: "gcx"
```

_`format: "gcx"` returns the [GCX1 compact wire format](../../docs/wire-format.md) — round-trippable, ~27% fewer tokens than JSON. Drop it for JSON output; agents using `@gortex/wire` or the Go `github.com/gortexhq/gcx-go` package decode either._
