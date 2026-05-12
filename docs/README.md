# Serial Communication

Laravel 11 aplikácia na komunikáciu s hlasovacím zariadením Qomo cez Web Serial API, párovanie prijatých hex kódov na zariadenia uložené v databáze a import mapovania kódov z CSV alebo externej SQLite databázy.

## Čo projekt robí

- pripojí sa k zariadeniu cez Web Serial API v prehliadači,
- odošle inicializačné a štartovacie hex príkazy do zariadenia,
- číta prichádzajúce hex hodnoty zo sériového portu,
- porovná ich s tabuľkou `devices`,
- zobrazí, ku ktorému zariadeniu a tlačidlu prijatý kód patrí,
- umožní naplniť databázu zariadení cez CSV alebo z externej SQLite databázy.

## Stack

- PHP 8.2+
- Laravel 11
- Livewire 3 + Volt
- Blade + Alpine.js
- Vite + Tailwind CSS
- SQLite ako predvolená lokálna databáza
- Pest pre testy

## Hlavné časti aplikácie

- `app/Livewire/SerialCommunication.php`
  Serverová časť Livewire komponentu. Načíta zoznam zariadení a mapuje prijaté kódy na konkrétne tlačidlá `A-F` a `Ruka`.
- `resources/views/livewire/serial-communication.blade.php`
  Frontend logika pre Web Serial API. Ovláda pripojenie, inicializáciu zariadenia, čítanie dát a volanie Livewire akcie `checkCode()`.
- `app/Http/Controllers/DeviceController.php`
  Import zariadení z CSV a z externej SQLite databázy z externej C# hlasovacej aplikácie.
- `app/Models/Device.php`
  Eloquent model pre tabuľku `devices`.
- `database/migrations/2024_10_08_183122_create_devices_table.php`
  Schéma tabuľky zariadení a všetkých podporovaných kódov.

## Spustenie lokálne

1. Nainštaluj závislosti:

```bash
composer install
npm install
```

2. Priprav `.env`:

```bash
cp .env.example .env
php artisan key:generate
```

3. Priprav SQLite databázu:

```bash
touch database/database.sqlite
```

4. Spusť migrácie:

```bash
php artisan migrate
```

5. Spusť backend a frontend:

```bash
php artisan serve
npm run dev
```

## Použitie

### 1. Prihlásenie

Dashboard so sériovou komunikáciou je dostupný na `/dashboard` a je chránený `auth` + `verified` middleware. Rovnakú ochranu majú aj importné stránky.

### 2. Import zariadení

Projekt dnes podporuje dva zdroje dát:

- `/import-devices`
  Import CSV súboru. Backend očakáva stĺpce v poradí:
  `device_number, code_a, code_b, code_c, code_d, code_e, code_f, code_ruka`
- `/import-external-db`
  Upload externej SQLite databázy. Ide o pomocný import z externej C# aplikácie na custom hlasovanie, kde sú uložené hex kódy všetkých zariadení. Import sa číta z tabuľky `SKDP_ParentZariadenie` a z polí:
  `UniqueId, A_Code, B_Code, C_Code, D_Code, E_Code, F_Code, Ruka_Code`

Oba importy používajú `updateOrCreate`, takže záznam sa identifikuje podľa `device_number` a pri opakovanom importe sa prepíšu kódy existujúceho zariadenia.

### 3. Sériová komunikácia

Na dashboarde Livewire komponent:

1. vyžiada od používateľa výber sériového portu,
2. otvorí port s nastavením:
   `baudRate=28800`, `dataBits=8`, `parity=none`, `stopBits=1`, `flowControl=none`,
3. po pripojení odošle inicializačné príkazy:
   `f400c00236`, `f500000101f5`, `f54b4e050200000601f0`,
4. po kliknutí na "Začať komunikáciu" odošle:
   `5b80db`, `5a80da`,
5. priebežne číta prijaté bajty, prevádza ich na hex a posiela ich na serverové `checkCode()`,
6. ak kód nájde v databáze, zobrazí názov zariadenia a názov tlačidla.

Komunikácia vznikla reverzným odpozorovaním originálneho softvéru a pripojeného transmittera. Význam jednotlivých inicializačných hex príkazov dnes nie je zdokumentovaný, ale aktuálna sekvencia je potrebná na funkčnú komunikáciu.

## Požiadavky na prehliadač

Web Serial API musí byť dostupné v prehliadači. Prakticky to znamená moderný Chromium-based prehliadač a vhodný kontext, v ktorom `navigator.serial` existuje. Ak API nie je dostupné, komponent zobrazí chybu už pri inicializácii.

## Dátový model

Tabuľka `devices` obsahuje:

- `device_number` - unikátny identifikátor zariadenia,
- `code_a` až `code_f` - kódy tlačidiel,
- `code_ruka` - samostatný kód tlačidla alebo funkcie "Ruka".

Každé zariadenie má fixne 7 tlačidiel: `A`, `B`, `C`, `D`, `E`, `F` a `Ruka`.

## Testovanie

Spustenie testov:

```bash
php artisan test
```

Aktuálne sú v repozitári hlavne základné testy pre auth/profile scaffold. Špecifické testy pre import zariadení a `SerialCommunication` flow zatiaľ chýbajú.

## Známe obmedzenia

- Verejná landing page `/` je stále pôvodná Laravel welcome stránka.
- Navigácia má dve položky s rovnakým názvom `Import`, ale vedú na rozdielne formuláre.
- Význam jednotlivých inicializačných hex príkazov nie je známy; boli odvodené sniffovaním komunikácie originálneho softvéru.
- Logika čítania sériových dát predpokladá, že zariadenie posiela identifikáciu stlačeného tlačidla. Pri opakovanom stlačení toho istého tlačidla je relevantné iba posledné stlačenie.
- Hex príkazy a parametre portu sú natvrdo zapísané vo view komponente.
- V projekte sú prítomné necommitnuté artefakty a lokálne súbory ako `.DS_Store`, `_ide_helper.php` a `public/hot 2`.

## Doménové poznámky

- Cieľové zariadenie je hlasovacie zariadenie od firmy Qomo.
- Význam protokolových hex príkazov nie je známy na úrovni špecifikácie; funkčná sekvencia vznikla reverznou analýzou komunikácie originálneho softvéru.
- SQLite import vznikol ako praktický most k existujúcej C# aplikácii na custom hlasovanie.

## Súvisiaca dokumentácia

- [Technický prehľad](docs/technical-overview.md)
