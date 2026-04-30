# CODEX_NATIVEPHP_GUIDE.md (Desktop Focus)

## Purpose

This file provides guidance for AI agents (Codex, Cursor, etc.) working on this repository.

This project uses NativePHP Desktop (Electron-based). Treat it as a Laravel application running inside a native desktop environment.

---

## Project Type

This is **NativePHP Desktop**:

- package: nativephp/electron
- targets: macOS, Windows, Linux
- runtime: Electron

⚠️ Never use Mobile APIs.

---

## Primary Knowledge Sources

Always search in this order:

1. Laravel Boost MCP (MANDATORY if available)
2. NativePHP Desktop docs
3. Laravel docs
4. vendor/nativephp/* source code

Docs:

- https://nativephp.com/docs/desktop/2/
- https://nativephp.com/docs/
- https://laravel.com/docs

---

## Laravel Boost Usage

Use Boost before guessing anything.

Typical tools:
- search-docs
- logs
- routes
- models

Never hallucinate APIs.

---

## NativePHP Desktop Concept

This app is:

- Laravel backend
- running inside Electron
- with access to OS-level features

### Important differences from web apps:

- filesystem is local
- no classic hosting environment
- OS APIs available (menus, windows, notifications)
- browser behavior != production browser

---

## Important Files

Check first:

- config/nativephp.php
- composer.json
- package.json
- vite.config.*
- routes/*
- app/*
- resources/*
- .env

---

## NativePHP Commands

Always verify:

```bash
php artisan list native
```

Typical:

```bash
php artisan native:install
php artisan native:run
php artisan native:watch
php artisan native:build
php artisan native:package
```

---

## Development Workflow

Before work:

```bash
composer install
npm install
php artisan about
php artisan native:version
```

After changes:

```bash
vendor/bin/pint
php artisan test
npm run build
```

Run app:

```bash
php artisan native:run
```

---

## Desktop-specific Rules

- Uses Electron
- Window/menu behavior must follow OS conventions
- Always check docs before using:
  - menus
  - tray
  - notifications
  - file dialogs

- Test behavior on:
  - macOS
  - Windows

---

## Configuration Rules

When editing config/nativephp.php:

- DO NOT change app ID
- DO NOT change bundle identifiers
- DO NOT break packaging config

---

## Coding Rules

- Follow Laravel standards
- Keep controllers thin
- Use services for logic
- Avoid OS-specific hacks unless required
- Isolate Electron-related logic

---

## When Unsure

DO NOT GUESS.

Steps:

1. Boost search-docs
2. NativePHP Desktop docs
3. vendor/nativephp/*
4. existing project code

---

## Anti-patterns

Avoid:

- treating app as pure web app
- using Mobile APIs
- skipping Boost
- editing Electron internals blindly

---

## Key Rule

This is a **desktop-native Laravel app via Electron**, not a traditional web app.
