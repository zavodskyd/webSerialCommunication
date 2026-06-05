# Variant A: Hardening Native Startup Without Rewrite

## Summary

Cieľ je odstrániť dlhý biely štart a generický `500` pri bootstrap chybe bez zmeny architektúry Laravel + NativePHP + Rust `serial-agent`.

Zvolené rozhodnutia:

- Startup UX bude `blokujúci splash` s viditeľným progresom.
- Spúšťanie migrácií bude viazané na `zmenu verzie appky`, nie na každý boot.
- `Seed` ostáva len pre prvú inštaláciu alebo prázdnu DB.
- `rust-agent` ostáva zachovaný; nejde o WebSerial vetvu.

## Implementation Changes

### 1. Zaviesť riadený startup workflow

- Pridať nový `StartupCoordinator` ako jediný orchestration bod pre native boot.
- `NativeAppServiceProvider` už nebude obsahovať priamo boot logiku databázy a serialu; bude delegovať na coordinator.
- Startup bude mať pevné kroky v tomto poradí:
  1. `resolve-build-version`
  2. `load-startup-state`
  3. `ensure-database-present`
  4. `maybe-run-migrations`
  5. `maybe-seed-initial-data`
  6. `start-rust-agent`
  7. `start-laravel-serial-bridge`
  8. `mark-startup-ready`
- Každý krok bude mať výsledok `pending|running|ok|failed`, duration v ms a krátky technický detail.

### 2. Zaviesť natívny splash a startup error UX

- Electron main otvorí hneď po štarte malé natívne splash okno s textovým progresom.
- Hlavné okno sa otvorí až po úspešnom dokončení `StartupCoordinator`.
- Pri chybe sa splash prepne do `startup failed` režimu, nie do generického Laravel `500`.
- Error obrazovka bude zobrazovať:
  - názov zlyhaného kroku
  - krátku chybu
  - build verziu
  - cestu k log súboru
  - tlačidlo `Zavrieť`
  - tlačidlo `Skúsiť znova`
- `Skúsiť znova` spustí celý startup workflow znova v tom istom procese; ak to nie je technicky spoľahlivé, fallback je relaunch appky.

### 3. Zmeniť pravidlá pre migrácie a seed

- `seedFromBundledDatabaseIfEmpty()` ostane first-install only; spúšťa sa len pri prázdnej DB.
- `runPendingMigrations()` sa prestane volať bezpodmienečne pri každom boote.
- Startup decision bude:
  - DB súbor neexistuje alebo aplikačné tabuľky sú prázdne: `migrate + seed`
  - DB existuje a `last_started_version === current_build_version`: preskočiť `migrate` aj `seed`
  - DB existuje a `last_started_version !== current_build_version`: spustiť `migrate`, `seed` preskočiť
- Po úspešnom štarte sa uloží `last_started_version = current_build_version`.
- Po neúspešnej migrácii sa `last_started_version` nesmie prepísať.
- Verzia sa bude brať z existujúceho `BuildVersion`/`config('nativephp.version')`; porovnávacia hodnota musí byť stabilná pre packaged build, nie live git fallback v dev režime.
- Startup state sa uloží mimo DB, do lokálneho file-based state v `storage/framework/`, aby bol dostupný aj keď DB bootstrap zlyhá.

### 4. Oddeliť serial štart od render chyby

- `rust-agent` a Laravel `SerialAgentBridge` ostávajú samostatné procesy.
- Ich štart bude stále súčasťou startup workflow, ale budú mať vlastné chyby a logovanie.
- Ak `serial-agent.exe` alebo `serial-agent:bridge` neštartne, startup sa ukončí na splash obrazovke ako `Serial startup failed`; hlavné UI sa neotvorí do polofunkčného stavu.
- `SerialAgentBridge` v pláne znamená Laravel command, ktorý sa pripája na lokálny WebSocket `rust-agent` sidecaru a routeuje `frame/status/ack`; nejde o WebSerial implementáciu.
- Operator UI sa nemení funkčne; mení sa len to, že sa zobrazí až po úspešnom native bootstrap procese.

### 5. Cleanup legacy WebSerial a Node helper vetvy

- Po stabilizácii startup workflow odstrániť z aplikácie celé `web-serial` a `node-helper` vetvenie; cieľový stav je jediný podporovaný driver `rust-agent`.
- `config/serial.php` zmeniť tak, aby default aj explicitne podporovaný driver bol iba `rust-agent`.
- Z `VotingConsole` odstrániť helper/webserial vetvenie ako `isHelperDriver()`, `SerialHelperClient` volania a všetky flowy pre `open/init/close/start/stop` cez node helper; UI ostane naviazané len na `rust-agent` stav a command API.
- Odstrániť už nepoužívané helper vrstvy a debug endpointy naviazané na `SerialHelperClient`, `SerialHelperDiagnostics`, internal token HTTP kontroléry a related middleware, ak po prechode na `rust-agent` neostane žiadny spotrebiteľ.
- Vyčistiť Electron main a ostatné natívne bootstrap časti od poznámok alebo fallbackov pre `web-serial` / `node-helper`, aby bootstrap a runtime explicitne predpokladali len `rust-agent`.
- Zjednotiť `VoteEvent.source` produkciu na `rust-agent`; historické exporty môžu staré hodnoty čítať, ale nový runtime už nemá generovať `web-serial` ani `node-helper`.
- Upraviť testy a fixture dáta tak, aby primárny coverage bol len pre `rust-agent`; testy pokrývajúce legacy helper/webserial odstrániť alebo nahradiť migration/backward-compat testami tam, kde sa ešte čítajú historické dáta.

## Important Interfaces and Artifacts

- Nový interný service/interface: `StartupCoordinator`.
- Nový interný file-based state, napríklad `storage/framework/native-startup-state.json`, s minimálne týmito kľúčmi:
  - `last_started_version`
  - `last_successful_started_at`
  - `last_failed_step`
  - `last_failed_message`
- Nový interný startup log, napríklad `storage/logs/native-startup.log`.
- Splash renderer dostane jednoduchý IPC/event payload s poľami:
  - `step`
  - `label`
  - `status`
  - `detail`
  - `duration_ms`
- `config('serial.driver')` sa po cleanupe zužuje na jedinú podporovanú hodnotu `rust-agent`.
- Žiadne zmeny vo verejných HTTP API ani vo formáte hlasovacích dát.

## Test Plan

- Feature test: prvý native boot s prázdnou DB spustí `migrate + seed`.
- Feature test: ďalší boot s rovnakou build verziou preskočí `migrate` aj `seed`.
- Feature test: boot po zmene build verzie spustí `migrate`, ale nespustí `seed`, ak DB už obsahuje dáta.
- Feature test: zlyhanie migrácie neprepíše `last_started_version` a uloží failure state.
- Feature test: zlyhanie štartu `serial-agent` ukončí startup vo failed stave.
- Feature test: `SerialAgentBridge` failure sa zobrazí ako startup failure, nie ako tichý Laravel `500`.
- Feature test: app beží korektne bez `web-serial` a `node-helper` vetvy, pričom operator konzola používa výhradne `rust-agent`.
- Feature test: historické `VoteEvent` záznamy so source `web-serial` alebo `node-helper` sa stále dajú čítať/exportovať, ak majú zostať podporované pre archivované dáta.
- Unit test: startup state store správne číta a zapisuje verziu a failure metadata.
- Unit test: splash progress payload mapuje jednotlivé kroky na očakávané labely.
- Manual acceptance:
  - cold first install: splash, potom otvorenie hlavného okna
  - bežný opakovaný štart: výrazne kratší štart bez bielej obrazovky
  - update build: splash s migráciou a následne úspešný štart
  - simulovaná chyba migrácie: error splash namiesto `500`
  - simulovaná chyba serial agenta: error splash s jasnou príčinou

## Assumptions and Defaults

- Dokument je uložený v `docs/native-startup-hardening-plan.md`.
- `BuildVersion` zostáva zdrojom identifikácie builda; packaged build používa stamped verziu.
- File-based startup state je správna voľba; nepoužíva sa DB ani cache ako source of truth pre bootstrap rozhodnutia.
- `Retry` na error splash preferuje znovuspustenie workflow; ak by bol proces po zlyhaní nekonzistentný, implementácia má použiť relaunch appky namiesto in-process retry.
- Legacy `web-serial` a `node-helper` kód sa po tejto etape považuje za odstrániteľný; backward compatibility sa rieši len pre čítanie historických dát, nie pre beh runtime.
- Scope nezahŕňa Tauri, zmenu hlasovacej logiky, zmenu serial protokolu ani redizajn operator UI.
