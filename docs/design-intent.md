# Design intent — veci, ktoré sú v kóde naschvál

Tento dokument je zoznam miest, ktoré pri prvom pohľade pôsobia ako bug, ale sú zámer. Keď sa do projektu pozrie nový vývojár (alebo AI agent), tieto veci by nemal "opraviť" bez toho, aby sa najprv spýtal.

Pridávajte sem ďalšie body, ktoré sa v review opakujú ako "to nie je bug, to je feature".

## Sériový reader v Electron main procese, nie v Blade view

Pôvodná implementácia mala Web Serial API priamo vo `<script>` bloku Livewire komponenty (`voting-console.blade.php`, ~870 riadkov, z toho ~650 JS). Tento prístup neustále zlyhával na Q2+ (prvých ~5s hlasov stratených, "Pripojené" preblíkavalo, "Odpojiť" nereagoval) kvôli race conditions medzi async JS state a Livewire morph cyklom.

Riešenie: sériový reader teraz beží ako samostatný Node proces v Electron main procese (`electron/serial-helper/`), ktorý komunikuje s Laravelom cez localhost HTTP. Livewire komponent je čistý — bez JS state machinery, bez `@script` bloku, bez Web Serial API.

**Architektúra**:

- Driver flag `SERIAL_DRIVER` v `.env` rozhoduje:
  - `web-serial` (default) — pôvodná Web Serial implementácia, zachovaná pre rollback.
  - `node-helper` — nový tok cez Node serial-helper.
- `node-helper` cesta:
  - Helper sa spúšťa cez NativePHP `ChildProcess::node()` v `NativeAppServiceProvider::boot()`.
  - Helper číta sériový port pomocou npm `serialport` knižnice.
  - Každý 3-byte frame postuje na `POST /internal/serial-frame` (auth cez `X-Internal-Token` bearer + localhost-only middleware).
  - Laravel rieši hlas cez `App\Services\Voting\VoteRecorder` (rovnaký service ako Livewire path).
  - Operátorská konzola používa `wire:poll.500ms="liveTick"` na refresh — žiadny JS state.

**Nepresúvať** sériovú logiku späť do Blade view ani do JS. Bola to bolesť, ktorú nechceme zopakovať.

## Permissive importy

`DeviceController::import` (CSV) a `DeviceController::loadExternalDb` (externá SQLite) akceptujú aj riadky, kde sú code stĺpce prázdne alebo nie sú validné hex hodnoty. Riadok s `code_a = ""` alebo `code_a = "n/a"` je legitímny stav "toto tlačidlo nie je na tomto zariadení namapované" — nie je to malformed input.

**Zámer:** import nesmie nikdy spadnúť na neúplných dátach. Operátor naimportuje to, čo má, a doplní zvyšok neskôr ručne.

**Nepridávať** per-row hex validáciu, kontrolu formátu jednotlivých stĺpcov, ani "reject celý import na zlom riadku" logiku, kým si to niekto explicitne neodsúhlasí.

## Inicializácia transmittera len raz za session

Operátor klikne "Pripojiť". V tom momente sa spustí celý hex handshake (`f400c00236`, `f500000101f5`, `f54b4e050200000601f0`) v `connect()`. Transmitter ostáva inicializovaný až do "Odpojiť" alebo do opustenia stránky.

Medzi otázkami sa **nepúšťa** ďalší init — len enable/disable príkazy (`5a80da` / `5b80db`) prepínajú collector.

**Nepridávať** volanie `initializeDevice()` do `startCommunication` ani inde do per-question flow. Predošlá iterácia kódu robila init→enable→disable→close pri každej otázke a Dušan to prerábal práve preto.

## Tichá ignorácia neznámych hlasov

`VotingConsole::recordVoteFromCode` vráti `accepted=false` bez throw / bez UI notifikácie v týchto prípadoch:

- kód nezodpovedá žiadnemu zariadeniu v `devices`,
- zariadenie má `weight = 0`,
- kód je `Ruka` (nie je to hlasovacie tlačidlo).

**Zámer:** operátor pozerá na výsledkový panel, kde vidí, čo sa zaregistrovalo. Toast pri každom rejection-e by bol len šum.

**Nepridávať** error notifikácie pre tieto stavy.

## Akceptácia "stale" hlasov v native runtime

`VotingConsole::recordVoteFromCode` v live runtime akceptuje aj hlasy, ktoré prídu **po** uplynutí runtime time-limitu (commit `b4f90d6`). Logika: native helper môže poslať batch hlasov s krátkym oneskorením, časovač už medzitým mohol vypršať. Ak by sme stale hlasy zahadzovali, prišli by sme o reálne stlačenia z konca okna.

**Nepridávať** check `runtime_remaining_seconds > 0` ani podobnú "deadline enforcement" logiku do prijímania hlasov, kým sa nezmení design helpera.

## `devices` je široká flat tabuľka naschvál

Sedem nullable string stĺpcov (`code_a` … `code_f`, `code_ruka`) na jeden riadok. Lookup prejde všetkých 7 stĺpcov cez `orWhere` — pri rozsahu ~100 zariadení v reálnom použití je to jednoduchšie a lacnejšie ako normalizácia.

**Nepresúvať** do `device_codes (device_id, button, hex_code)` bez konkrétneho dôvodu (napr. iný počet tlačidiel pri novom modeli Qomo, variabilný layout, …).

## `Vote` deduplikácia cez `updateOrCreate`

Hlasy sa ukladajú cez `Vote::updateOrCreate(['voting_question_id', 'device_id'], ...)`. Ak ten istý prístroj stlačí znovu na rovnakú otázku, predošlý hlas sa prepíše — platí posledné stlačenie.

DB má aj `unique(voting_question_id, device_id)` constraint, takže invariant je vynútený na úrovni schémy.

**Nepridávať** PHP-side deduplikáciu, lock-y, alebo `firstOrCreate` workaround. Constraint to už rieši.
