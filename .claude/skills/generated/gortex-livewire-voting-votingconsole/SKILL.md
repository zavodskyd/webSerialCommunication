---
name: gortex-livewire-voting-votingconsole
description: "Work in the Livewire/Voting · VotingConsole area — 45 symbols across 3 files (97% cohesion)"
---

# Livewire/Voting · VotingConsole

45 symbols | 3 files | 97% cohesion

## When to Use

Use this skill when working on files in:
- `app/Livewire/Voting/VotingConsole.php`
- `app/Livewire/Voting/VotingEditor.php`
- `app/Livewire/Voting/VotingIndex.php`

## Key Files

| File | Symbols |
|------|---------|
| `app/Livewire/Voting/VotingConsole.php` | finishQuestion, selectQuestion, startQuestion, currentQuestion, persistRuntimeState, ... |
| `app/Livewire/Voting/VotingEditor.php` | moveQuestionToOrder |
| `app/Livewire/Voting/VotingIndex.php` | toggleShowAll, copyVoting, archiveVoting, VotingIndex, createVoting, ... |

## Entry Points

- `app/Livewire/Voting/VotingIndex.php::VotingIndex.copyVoting`
- `app/Livewire/Voting/VotingConsole.php::VotingConsole.mount`

## Connected Communities

- **Services** (1 cross-edges)

## How to Explore

```
get_communities with id: "community-52"
smart_context with task: "understand Livewire/Voting · VotingConsole", format: "gcx"
find_usages with id: "app/Livewire/Voting/VotingIndex.php::VotingIndex.copyVoting", format: "gcx"
```

_`format: "gcx"` returns the [GCX1 compact wire format](../../docs/wire-format.md) — round-trippable, ~27% fewer tokens than JSON. Drop it for JSON output; agents using `@gortex/wire` or the Go `github.com/gortexhq/gcx-go` package decode either._
