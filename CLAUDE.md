# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project: Hlasovanie (Voting)

Slovak-language voting/polling system for **Qomo wireless voting devices**, built on Laravel 12 + Livewire 3 and shipped as a **NativePHP Desktop (Electron) app**. The README is outdated — treat this section as authoritative for current state.

The original `/dashboard` + Laravel Breeze auth scaffold has been **removed** (`tests/Feature/AuthRemovedTest.php` enforces this). The app is now unauthenticated; the entry route redirects `/` → `/votings`. The legacy `<livewire:serial-communication />` component still exists at `resources/views/livewire/serial-communication.blade.php` but the primary product is the voting console.

## Architecture

### Two execution modes

1. **Web (browser)** — `php artisan serve` + `npm run dev`. Web Serial API works only in Chromium-based browsers.
2. **Desktop (NativePHP/Electron)** — `composer native:dev` runs `php artisan native:run` and `npm run dev` concurrently. Desktop is the intended production target. `app/Providers/NativeAppServiceProvider.php` opens a maximized window on boot and seeds an empty SQLite from a bundled seed DB via `App\Support\NativeDatabaseBootstrapper`.

### Voting domain (the main feature)

- `Voting` hasMany `VotingQuestion` hasMany `VotingOption` + `Vote`.
- `VotingAttendee` joins a `Voting` with a `Device` and stores the per-voting `weight` (number of votes that device casts; `0` = present but cannot vote).
- `Vote` is unique on `(voting_question_id, device_id)` — re-pressing overwrites via `updateOrCreate` in `VotingQuestion::recordVote()`. The vote stores `weight_snapshot` so later weight changes don't retroactively alter results.
- Each question has a per-question status (`draft|live|paused|closed`) AND the parent `Voting` carries runtime state (`runtime_remaining_seconds`, `runtime_timer_running`, `runtime_collector_enabled`, `runtime_results_visible`, `current_voting_question_id`). This is so the **operator console** and **presentation view** can survive page reload / desktop relaunch and stay in sync.

### Voting Livewire components (`app/Livewire/Voting/`)

- `VotingIndex` — list & create votings.
- `VotingEditor` — manage questions, options, attendees (device assignment + weight).
- `VotingConsole` — operator's live control panel. Owns timer, collector enable/disable, "show results / advance" flow. Records votes via `recordVoteFromCode($hexCode)`.
- `VotingPresentation` — fullscreen audience view. Subscribes to console state via Livewire dispatched event `console-state-updated`.

### Serial protocol (client-side only)

The serial reader logic lives **inside the Blade view**, not PHP — `resources/views/livewire/serial-communication.blade.php` and the equivalent block in `voting-console.blade.php`. It uses the Web Serial API (browser) or NativePHP's serial bridge (desktop).

Hardcoded port config and init bytes (reverse-engineered from the original Qomo software, no spec exists):
- Port: `baudRate=28800, dataBits=8, parity=none, stopBits=1, flowControl=none`
- Init: `f400c00236`, `f500000101f5`, `f54b4e050200000601f0`
- Start: `5b80db`, `5a80da`  •  Stop: `5b80db`

Received bytes → hex string → posted to a Livewire action (`SerialCommunication::checkCode` or `VotingConsole::recordVoteFromCode`). The PHP side resolves which `Device` row owns that code and which button (`A`–`F` or `Ruka`) it represents via `Device::resolveButtonName()`.

### Device model (lookup table)

`devices` is a wide flat table — one row per physical voting device with seven nullable string columns `code_a`…`code_f`, `code_ruka` holding the unique hex codes for that device's buttons. Lookup is denormalized on purpose to keep the per-keystroke hot path simple. Two import paths:

- CSV → `DeviceController::import` (column order: `device_number, code_a..code_f, code_ruka`).
- External SQLite from a legacy C# voting app → `DeviceController::loadExternalDb`. Reads table `SKDP_ParentZariadenie` columns `UniqueId, A_Code…F_Code, Ruka_Code`. Uses a runtime-injected DB connection named `external` and writes the file to `storage/app/private/temp/external.sqlite` — name is fixed, so concurrent uploads collide.

### NativePHP seeding

`PrepareNativeSeedDatabase` (artisan `native:prepare-seed-database`) runs as a `prebuild` hook (see `config/nativephp.php`) and copies the current dev SQLite to `database/nativephp-seed.sqlite`. On first launch in the bundled desktop app, `NativeDatabaseBootstrapper::seedFromBundledDatabaseIfEmpty()` ATTACH-copies tables from that seed into the user's empty local DB. It only runs when `config('nativephp-internal.running')` is true and the application tables (`users, devices, votings, voting_questions, voting_options, voting_attendees, votes`) are empty.

## Commands

```bash
# Web dev
php artisan serve
npm run dev
npm run build

# Desktop dev (Electron)
composer native:dev          # runs native:run + npm run dev concurrently
php artisan native:run        # NativePHP only
php artisan native:prepare-seed-database   # snapshot dev DB into the bundled seed

# DB
php artisan migrate
php artisan migrate:fresh

# Tests (Pest 3, RefreshDatabase auto-applied to tests/Feature)
php artisan test --compact
php artisan test --compact --filter=VotingConsoleTest
vendor/bin/pest tests/Feature/SerialCommunicationTest.php

# Format (run after editing PHP)
vendor/bin/pint --dirty --format agent
```

## Conventions specific to this project

- UI strings are **Slovak** (`Hlasovanie`, `Stlačená hodnota`, `Zariadenie`). Keep that voice when adding labels.
- Migrations use the modern Laravel 12 layout — middleware in `bootstrap/app.php`, no `app/Http/Kernel.php`, no `app/Console/Kernel.php`.
- When changing voting runtime behavior, update both `VotingConsole` (write side) and `VotingPresentation` (read side); the contract is the `console-state-updated` event payload + the `runtime_*` columns on `votings`.
- The serial protocol is duplicated between `serial-communication.blade.php` and `voting-console.blade.php`. If you change init bytes or parsing, update both.
- Tests prefer feature tests + factories over unit tests — see `tests/Feature/VotingConsoleTest.php` for the canonical pattern (Livewire `Livewire::test(Component::class, [...])`).

## Serial driver flag

`config/serial.php` exposes `serial.driver`, sourced from env `SERIAL_DRIVER` (default `web-serial`). Flipping it to `node-helper` is the cutover switch from the in-Livewire Web Serial reader to the Electron-main Node serial helper that posts frames into `/internal/serial-frame`. Default stays `web-serial` until the helper ships and Phase 5's UI cutover lands.

## Project docs to read before changing behaviour

- `docs/technical-overview.md` — architektúra, routy, dátový model, sériový protokol.
- `docs/design-intent.md` — **read this before "fixing" anything that looks weird.** Lists deliberate design choices (lenient imports, init-once-per-connection, silent vote rejection, stale-vote acceptance, wide `devices` table, `updateOrCreate` vote dedup). Don't propose tightening these without an explicit ask.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.3
- laravel/framework (LARAVEL) - v12
- laravel/prompts (PROMPTS) - v0
- livewire/livewire (LIVEWIRE) - v3
- livewire/volt (VOLT) - v1
- laravel/boost (BOOST) - v2
- laravel/breeze (BREEZE) - v2
- laravel/mcp (MCP) - v0
- laravel/pint (PINT) - v1
- pestphp/pest (PEST) - v3
- phpunit/phpunit (PHPUNIT) - v11
- tailwindcss (TAILWINDCSS) - v3

## Skills Activation

This project has domain-specific skills available. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

- `laravel-best-practices` — Apply this skill whenever writing, reviewing, or refactoring Laravel PHP code. This includes creating or modifying controllers, models, migrations, form requests, policies, jobs, scheduled commands, service classes, and Eloquent queries. Triggers for N+1 and query performance issues, caching strategies, authorization and security patterns, validation, error handling, queue and job configuration, route definitions, and architectural decisions. Also use for Laravel code reviews and refactoring existing Laravel code to follow best practices. Covers any task involving Laravel backend PHP code patterns.
- `livewire-development` — Use for any task or question involving Livewire. Activate if user mentions Livewire, wire: directives, or Livewire-specific concepts like wire:model, wire:click, invoke this skill. Covers building new components, debugging reactivity issues, real-time form validation, loading states, migrating from Livewire 2 to 3, converting component formats (SFC/MFC/class-based), and performance optimization. Do not use for non-Livewire reactive UI (React, Vue, Alpine-only, Inertia.js) or standard Laravel forms without Livewire.
- `volt-development` — Develops single-file Livewire components with Volt. Activates when creating Volt components, converting Livewire to Volt, working with @volt directive, functional or class-based Volt APIs; or when the user mentions Volt, single-file components, functional Livewire, or inline component logic in Blade files.
- `mcp-development` — Use this skill for Laravel MCP development. Trigger when creating or editing MCP tools, resources, prompts, servers, or UI apps in Laravel projects. Covers: artisan make:mcp-* generators, routes/ai.php, Tool/Resource/Prompt/AppResource classes, schema validation, shouldRegister(), OAuth setup, URI templates, read-only attributes, MCP debugging, MCP UI apps, the x-mcp::app Blade component, createMcpApp(), default AppResource handle() auto-infers view from class name, Response::view(), AppMeta/Csp/Permissions/appMeta() configuration, #[RendersApp] attribute, Library enum for CDN libraries (Tailwind, Alpine), and host theming via CSS variables. Use this whenever the user mentions MCP apps, MCP UI, interactive MCP resources, styling MCP apps with Tailwind or Alpine, or building visual interfaces for AI agents.
- `pest-testing` — Use this skill for Pest PHP testing in Laravel projects only. Trigger whenever any test is being written, edited, fixed, or refactored — including fixing tests that broke after a code change, adding assertions, converting PHPUnit to Pest, adding datasets, and TDD workflows. Always activate when the user asks how to write something in Pest, mentions test files or directories (tests/Feature, tests/Unit) or architecture tests. Covers: test()/it()/expect() syntax, datasets, mocking, browser testing, arch(), Livewire component tests, RefreshDatabase, and all Pest 3 features. Do not use for editing factories, seeders, migrations, controllers, models, or non-test PHP code.
- `tailwindcss-development` — Always invoke when the user's message includes 'tailwind' in any form. Also invoke for: building responsive grid layouts (multi-column card grids, product grids), flex/grid page structures (dashboards with sidebars, fixed topbars, mobile-toggle navs), styling UI components (cards, tables, navbars, pricing sections, forms, inputs, badges), adding dark mode variants, fixing spacing or typography, and Tailwind v3/v4 work. The core use case: writing or fixing Tailwind utility classes in HTML templates (Blade, JSX, Vue). Skip for backend PHP logic, database queries, API routes, JavaScript with no HTML/CSS component, CSS file audits, build tool configuration, and vanilla CSS.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.
- To check environment variables, read the `.env` file directly.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app/Console/Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== livewire/core rules ===

# Livewire

- Livewire allow to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== volt/core rules ===

# Livewire Volt

- Single-file Livewire components: PHP logic and Blade templates in one file.
- Always check existing Volt components to determine functional vs class-based style.
- IMPORTANT: Always use `search-docs` tool for version-specific Volt documentation and updated code examples.
- IMPORTANT: Activate `volt-development` every time you're working with a Volt or single-file component-related task.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

</laravel-boost-guidelines>

<!-- rtk-instructions v2 -->
# RTK (Rust Token Killer) - Token-Optimized Commands

## Golden Rule

**Always prefix commands with `rtk`**. If RTK has a dedicated filter, it uses it. If not, it passes through unchanged. This means RTK is always safe to use.

**Important**: Even in command chains with `&&`, use `rtk`:
```bash
# ❌ Wrong
git add . && git commit -m "msg" && git push

# ✅ Correct
rtk git add . && rtk git commit -m "msg" && rtk git push
```

## RTK Commands by Workflow

### Build & Compile (80-90% savings)
```bash
rtk cargo build         # Cargo build output
rtk cargo check         # Cargo check output
rtk cargo clippy        # Clippy warnings grouped by file (80%)
rtk tsc                 # TypeScript errors grouped by file/code (83%)
rtk lint                # ESLint/Biome violations grouped (84%)
rtk prettier --check    # Files needing format only (70%)
rtk next build          # Next.js build with route metrics (87%)
```

### Test (60-99% savings)
```bash
rtk cargo test          # Cargo test failures only (90%)
rtk go test             # Go test failures only (90%)
rtk jest                # Jest failures only (99.5%)
rtk vitest              # Vitest failures only (99.5%)
rtk playwright test     # Playwright failures only (94%)
rtk pytest              # Python test failures only (90%)
rtk rake test           # Ruby test failures only (90%)
rtk rspec               # RSpec test failures only (60%)
rtk test <cmd>          # Generic test wrapper - failures only
```

### Git (59-80% savings)
```bash
rtk git status          # Compact status
rtk git log             # Compact log (works with all git flags)
rtk git diff            # Compact diff (80%)
rtk git show            # Compact show (80%)
rtk git add             # Ultra-compact confirmations (59%)
rtk git commit          # Ultra-compact confirmations (59%)
rtk git push            # Ultra-compact confirmations
rtk git pull            # Ultra-compact confirmations
rtk git branch          # Compact branch list
rtk git fetch           # Compact fetch
rtk git stash           # Compact stash
rtk git worktree        # Compact worktree
```

Note: Git passthrough works for ALL subcommands, even those not explicitly listed.

### GitHub (26-87% savings)
```bash
rtk gh pr view <num>    # Compact PR view (87%)
rtk gh pr checks        # Compact PR checks (79%)
rtk gh run list         # Compact workflow runs (82%)
rtk gh issue list       # Compact issue list (80%)
rtk gh api              # Compact API responses (26%)
```

### JavaScript/TypeScript Tooling (70-90% savings)
```bash
rtk pnpm list           # Compact dependency tree (70%)
rtk pnpm outdated       # Compact outdated packages (80%)
rtk pnpm install        # Compact install output (90%)
rtk npm run <script>    # Compact npm script output
rtk npx <cmd>           # Compact npx command output
rtk prisma              # Prisma without ASCII art (88%)
```

### Files & Search (60-75% savings)
```bash
rtk ls <path>           # Tree format, compact (65%)
rtk read <file>         # Code reading with filtering (60%)
rtk grep <pattern>      # Search grouped by file (75%)
rtk find <pattern>      # Find grouped by directory (70%)
```

### Analysis & Debug (70-90% savings)
```bash
rtk err <cmd>           # Filter errors only from any command
rtk log <file>          # Deduplicated logs with counts
rtk json <file>         # JSON structure without values
rtk deps                # Dependency overview
rtk env                 # Environment variables compact
rtk summary <cmd>       # Smart summary of command output
rtk diff                # Ultra-compact diffs
```

### Infrastructure (85% savings)
```bash
rtk docker ps           # Compact container list
rtk docker images       # Compact image list
rtk docker logs <c>     # Deduplicated logs
rtk kubectl get         # Compact resource list
rtk kubectl logs        # Deduplicated pod logs
```

### Network (65-70% savings)
```bash
rtk curl <url>          # Compact HTTP responses (70%)
rtk wget <url>          # Compact download output (65%)
```

### Meta Commands
```bash
rtk gain                # View token savings statistics
rtk gain --history      # View command history with savings
rtk discover            # Analyze Claude Code sessions for missed RTK usage
rtk proxy <cmd>         # Run command without filtering (for debugging)
rtk init                # Add RTK instructions to CLAUDE.md
rtk init --global       # Add RTK to ~/.claude/CLAUDE.md
```

## Token Savings Overview

| Category | Commands | Typical Savings |
|----------|----------|-----------------|
| Tests | vitest, playwright, cargo test | 90-99% |
| Build | next, tsc, lint, prettier | 70-87% |
| Git | status, log, diff, add, commit | 59-80% |
| GitHub | gh pr, gh run, gh issue | 26-87% |
| Package Managers | pnpm, npm, npx | 70-90% |
| Files | ls, read, grep, find | 60-75% |
| Infrastructure | docker, kubectl | 85% |
| Network | curl, wget | 65-70% |

Overall average: **60-90% token reduction** on common development operations.
<!-- /rtk-instructions -->
