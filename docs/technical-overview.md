# Technický prehľad

Tento dokument sumarizuje aktuálnu architektúru projektu podľa existujúceho kódu.

## Architektúra

Projekt je klasická server-side Laravel aplikácia s Blade šablónami a Livewire komponentmi. Samotná komunikácia s hlasovacím zariadením Qomo prebieha na klientovi cez Web Serial API. Backend sa používa na:

- autentifikáciu používateľa,
- správu databázy zariadení,
- import zariadení z externých zdrojov,
- mapovanie prijatého hex kódu na konkrétne zariadenie a tlačidlo.

## Hlavný používateľský flow

1. Používateľ sa prihlási a otvorí `/dashboard`.
2. V dashboarde sa vyrenderuje `<livewire:serial-communication />`.
3. Alpine komponent vyžiada sériový port a otvorí ho.
4. Frontend odošle inicializačné príkazy.
5. Po štarte komunikácie frontend číta binárne dáta zo zariadenia.
6. Dáta sa konvertujú na hex reťazec.
7. Každý hex reťazec ide do `SerialCommunication::checkCode($code)`.
8. Backend vyhľadá zhodu v tabuľke `devices`.
9. Výsledok sa zobrazí používateľovi v UI.

## Routy

### Web

- `GET /`
  Pôvodná Laravel welcome page.
- `GET /dashboard`
  Dashboard s Livewire komponentom pre sériovú komunikáciu.
  Middleware: `auth`, `verified`
- `GET /profile`
  Laravel Breeze profil.
  Middleware: `auth`
- `GET /import-devices`
  Formulár na CSV import.
  Middleware: `auth`, `verified`
- `POST /import-devices`
  Spracovanie CSV importu.
  Middleware: `auth`, `verified`
- `GET /import-external-db`
  Formulár na upload externej SQLite databázy.
  Middleware: `auth`, `verified`
- `POST /load-external-db`
  Spracovanie importu z externej SQLite databázy.
  Middleware: `auth`, `verified`

## Komponenty a zodpovednosti

### `app/Livewire/SerialCommunication.php`

Zodpovednosť:

- pri `mount()` načíta všetky zariadenia,
- drží stav `result`, `devices`, `activeCode`,
- mapuje hex kód na stlačené tlačidlo,
- skladá textový výsledok pre UI.

Poznámka:
Premenná `devices` sa načíta, ale v súčasnom view sa explicitne nepoužíva. Lookup prebieha priamo query na modeli `Device`.

### `resources/views/livewire/serial-communication.blade.php`

Zodpovednosť:

- UI pre stav pripojenia,
- výber a otvorenie sériového portu,
- inicializácia zariadenia,
- štart/stop čítania,
- konverzia `ArrayBuffer -> hex`,
- filtrovanie duplicitných po sebe idúcich kódov,
- priebežné renderovanie prijatých dát.

Natvrdo zadané parametre:

- `baudRate: 28800`
- `dataBits: 8`
- `parity: none`
- `stopBits: 1`
- `flowControl: none`

Inicializačné príkazy:

- `f400c00236`
- `f500000101f5`
- `f54b4e050200000601f0`

Pôvod týchto príkazov:

- neboli prevzaté z oficiálnej špecifikácie,
- boli odvodené sniffovaním komunikácie medzi originálnym softvérom a transmitterom Qomo,
- význam jednotlivých príkazov dnes nie je zdokumentovaný.

Príkazy pre štart komunikácie:

- `5b80db`
- `5a80da`

Príkaz pri zastavení:

- `5b80db`

### `app/Http/Controllers/DeviceController.php`

`index()`
- vracia CSV import formulár.

`import(Request $request)`
- validuje upload typu `csv` alebo `txt`,
- načíta súbor cez `file()` + `str_getcsv`,
- preskočí prvý riadok ako hlavičku,
- importuje dáta do `devices` pomocou `updateOrCreate`.

`showImportForm()`
- vracia formulár pre SQLite import.

`loadExternalDb(Request $request)`
- validuje upload s príponou `sqlite`, `db`, `sqlite3`,
- uloží súbor do `storage/app/private/temp/external.sqlite`,
- za behu doplní DB connection `external`,
- číta tabuľku `SKDP_ParentZariadenie`,
- mapuje externé polia na interný model `Device`.

## Dátový model

### `devices`

- `id`
- `device_number` - unique
- `code_a`
- `code_b`
- `code_c`
- `code_d`
- `code_e`
- `code_f`
- `code_ruka`
- `created_at`
- `updated_at`

Lookup logika je momentálne denormalizovaná: každé tlačidlo je vlastný stĺpec. Výhodou je jednoduchý import. Nevýhodou je komplikovanejšie vyhľadávanie a slabšia rozšíriteľnosť pri zmene počtu tlačidiel.

Doménový predpoklad:

- každé zariadenie má fixne 7 tlačidiel: `A-F` a `Ruka`.

## Importné formáty

### CSV

Aktuálne sa predpokladá presné poradie stĺpcov:

1. `device_number`
2. `code_a`
3. `code_b`
4. `code_c`
5. `code_d`
6. `code_e`
7. `code_f`
8. `code_ruka`

Importer dnes nekontroluje počet stĺpcov ani formát jednotlivých hodnôt nad rámec MIME typu súboru.

### Externá SQLite databáza

Očakávaná tabuľka:

- `SKDP_ParentZariadenie`

Použité polia:

- `UniqueId`
- `A_Code`
- `B_Code`
- `C_Code`
- `D_Code`
- `E_Code`
- `F_Code`
- `Ruka_Code`

Kontext:

- import bol pridaný kvôli existujúcej C# aplikácii na custom hlasovanie,
- táto databáza slúži ako najjednoduchší zdroj pravdivých hex kódov pre všetky zariadenia.

## Autentifikácia a prístup

Autentifikácia je prevzatá z Laravel Breeze/Volt scaffoldu.

Aktuálny stav:

- dashboard je chránený,
- profil je chránený,
- importné endpointy sú chránené cez `auth` a `verified`.

## Testy

Repozitár obsahuje hlavne scaffold testy pre:

- registráciu,
- autentifikáciu,
- reset hesla,
- profil.

Chýbajú testy pre:

- CSV import s validnými a nevalidnými dátami,
- import z externej SQLite databázy,
- mapovanie kódov v `SerialCommunication::checkCode()`,
- behavior pri neznámom kóde,
- ochranu rout, ak sa zmení middleware.

## Riziká a technický dlh

- Business logika sériovej komunikácie je priamo vo Blade view v inline skripte.
- Protokol je známy empiricky, nie zo špecifikácie výrobcu.
- Hex príkazy a konfigurácia portu nie sú centralizované.
- Lookup na `devices` používa reťazec `orWhere(...)`, čo je pri rozšírení schémy ťažšie udržiavateľné.
- `mount()` načíta všetky zariadenia, ale lookup sa aj tak robí query na databázu.
- CSV import neobsahuje detailnejšiu validáciu obsahu riadkov.
- Pracuje sa s pevným názvom dočasného súboru `external.sqlite`, takže paralelné uploady môžu kolidovať.

## Odporúčané doplnenia do ďalšej iterácie

1. Zjednotiť názvy a dostupnosť importných stránok v navigácii.
2. Presunúť sériový protokol a parametre portu do konfiguračnej vrstvy.
3. Dopísať feature testy pre importy a unit/feature testy pre mapovanie kódov.
4. Ak sa podarí, spísať presnejšiu špecifikáciu sériového protokolu a význam každého príkazu.
5. Overiť, či UI má explicitne zobrazovať, že pri opakovanom stlačení tlačidla je relevantný len posledný stav.

## Otázky na doplnenie od zadávateľa

- Má aplikácia do budúcna iba zobrazovať výsledok, alebo aj ukladať históriu prijatých eventov?
