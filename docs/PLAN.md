# Voľby – implementačný plán

## Architektúra

- Rozšíriť `Voting` o `voting_type = standard|election`. Existujúce hlasovania zostanú `standard` bez funkčných zmien.
- Zachovať jeden sériový pipeline; `VotingTypeHandler` deleguje vstup, výsledky a prezentačné dáta na `StandardVotingHandler` alebo `ElectionVotingHandler`.
- Zaviesť perzistentný globálny stav aktívnej prezentácie (`presentation_runtime`) s aktívnym hlasovaním/voľbami a kontextom otázky, doplnenia kandidáta alebo volebného kola.
  - Existujúce jedno prezentačné okno dostane stabilnú spoločnú route.
  - Konzola pri aktivovaní, výbere alebo spustení kontextu aktualizuje aktívnu prezentáciu.
  - Už otvorené okno sa pollingom prepne medzi štandardným hlasovaním a voľbami bez otvorenia ďalšieho okna.
- `VotingPresentation` bude podľa typu vykresľovať existujúcu štandardnú alebo novú volebnú prezentačnú podšablónu.

## Databáza a modely

- `votings`: pridať `voting_type`; názov, nadpis, hlavička, logo, časový limit a automatické výsledky sa použijú aj pre voľby.
- `device_groups` a `device_group_ranges`:
  - skupina má názov, poradie a aktívnosť;
  - rozsah má číselný začiatok a koniec;
  - rozsahy musia byť platné a neprekrývajúce sa;
  - rozsah určí, ktoré zariadenia sa zobrazia a vyplnia v zozname váh danej skupiny.
- `elections`: 1:1 nadstavba k `Voting`, stav prípravy a priebehu volieb.
- `election_contests`: Predseda (1), Predstavenstvo Hliny (2), Solinky (3), Vlčince (3), Rozptyl/Staré Mesto (2) a Kontrolná komisia (7).
- `election_candidates`: iba `first_name`, `last_name`, stav a väzba na súťaž; radenie priezvisko, potom meno, zobrazovanie meno, potom priezvisko.
- `election_candidate_admissions` a hlasy:
  - samostatné ZA/PROTI/ZDRŽAL SA hlasovanie o návrhu kandidáta;
  - jeden aktuálny platný hlas na zariadenie a návrh; ďalší platný hlas prepíše predchádzajúci.
- `election_rounds`, kandidáti kola a `election_round_votes`: snapshot kandidátov, prijatých hlasov, váh a rozhodnutia kola.
- `vote_events`: rozšíriť o nullable volebný kontext; audit zostane spoločný pre všetky typy hlasovania.

## Správa a workflow

- Do navigácie pridať **Voľby**; pôvodné **Hlasovania** bude zobrazovať iba štandardné hlasovania.
- Editor volieb bude obsahovať všeobecné údaje, správu skupín a rozsahov, kandidátky a pevne pripravené súťaže.
- Každá súťaž je samostatná jednotka: môže sa pripraviť, doplniť kandidátmi a spustiť nezávisle od ostatných. Nie je podmienkou, aby bolo všetkých šesť súťaží vyplnených alebo spustených.
- Kandidátov bude možné zadať priamo pri vytvorení alebo úprave volieb; každá súťaž môže mať vlastnú kandidátku bez závislosti od iných súťaží.
- Doplnenie kandidáta je samostatná prípravná fáza:
  - návrh obsahuje meno, priezvisko a voliteľnú lokalitu/skupinu zariadení; cieľová súťaž sa určí pravidlom nižšie;
  - je to voliteľná doplnková funkcia, nie povinný krok pred spustením súťaže;
  - pri návrhu s priradenou lokalitou sa po schválení kandidát automaticky zaradí do kandidátky príslušného predstavenstva danej lokality;
  - pri návrhu bez priradenej lokality sa po schválení kandidát automaticky zaradí do kandidátky **Kontrolnej komisie**, ktorá nemá lokalitné pravidlo;
  - kandidáta na predsedu týmto doplnkovým procesom pridať nemožno; predsedu možno zadať iba priamo pri vytvorení alebo úprave volieb;
  - priebeh je rovnaký ako dnešné hlasovanie ZA/PROTI/ZDRŽAL SA;
  - v rámci časového intervalu môže zariadenie hlas opraviť; rozhoduje jeho posledný platný prijatý hlas;
  - ak má kandidát priradenú lokalitu/skupinu zariadení, prijaté sú len hlasy z tejto skupiny; bez priradenej lokality hlasujú všetky zariadenia;
  - návrh sa automaticky schváli pri `ZA > polovica všetkých platne odovzdaných vážených hlasov`.
- Volebná konzola:
  - Predseda: zariadenie môže kladne hlasovať iba za jedného kandidáta v kole. Ak sú v prvom kole aspoň dvaja kandidáti a nikto neuspeje, postúpia dvaja s najvyšším nenulovým výsledkom do konečného druhého kola. Pri jedinom neúspešnom kandidátovi sa druhé kolo nevytvorí.
  - Predstavenstvo a komisia: zariadenie môže podporiť najviac počet zostávajúcich mandátov.
  - Nadpolovičná väčšina sa v každom kole počíta zo súčtu všetkých prijatých vážených hlasov za celé kolo, nie samostatne pre kandidáta ani z účasti. Hranica je `floor(súčet / 2) + 1`; ak pri voľbe predsedu získajú dvaja kandidáti 60 a 100 vážených hlasov, súčet kola je 160 a nadpolovičná väčšina je 81.
  - Kandidát je zvolený pri dosiahnutí tejto hranice; rovnaké pravidlo platí pre každú súťaž aj každé ďalšie kolo.
  - Pri prebytku úspešných kandidátov sa vyberie iba potrebný počet podľa hlasov a potom abecedy; kolo končí.
  - Pri nedostatku úspešných kandidátov vznikne ďalšie kolo; kandidát s najnižším výsledkom vypadáva, pri zhode abecedne nižšie meno.

## Volebná prezentácia

- Počas otvoreného hlasovania zobrazí kandidátov v abecednej tabuľke; aktívne hlasovaný kandidát bude mať zelené pozadie riadku.
- Pri výsledku zobrazí rovnakú tabuľku zoradenú podľa počtu hlasov:
  - úspešní kandidáti aktuálneho kola: zelené pozadie;
  - pri druhom a ďalšom kole budú už zvolení kandidáti z predchádzajúcich kôl na začiatku tabuľky so žltým pozadím;
  - ostatní kandidáti budú bez farebného pozadia.
- Tabuľka bude obsahovať poradie, meno kandidáta, počet hlasov a zrozumiteľný stav kandidáta; pri otvorenom hlasovaní zobrazí aj aktuálne obsadzované a zostávajúce mandáty.
- Prezentačný layout bude responzívny a bez scrollovania:
  - hlavička, tabuľková plocha a päta budú v `min-h-screen` grid/flex rozložení s explicitne obmedzeným priestorom;
  - kandidátka bude mať dynamickú hustotu riadkov podľa počtu kandidátov a rozmeru viewportu;
  - pri väčšom počte kandidátov sa tabuľka automaticky rozdelí do viacerých rovnako štylizovaných stĺpcov, pričom v každom pokračuje abecedné alebo výsledkové poradie;
  - komponent na výpočet rozloženia zmeria dostupnú výšku a upraví počet stĺpcov, veľkosť písma, padding a výšku riadkov tak, aby obsah vždy ostal v hraniciach okna bez horizontálneho ani vertikálneho overflowu;
  - výsledkový stav použije totožné rozloženie, aby prepnutie z priebehu na výsledok nespôsobilo posun mimo obrazovku.

## Konzola, integrita a testy

- Pridať `ElectionIndex`, `ElectionEditor`, `ElectionConsole`, `DeviceGroupManager`, volebnú prezentačnú podšablónu a komponent zodpovedný za prispôsobenie kandidátskej tabuľky viewportu.
- Existujúca `VotingConsole` zostane štandardnou konzolou; runtime router odošle rámec len aktuálne aktívnemu kontextu.
- Prijatie hlasu bude transakčné, aby paralelné sériové rámce neprekročili limit hlasov zariadenia v kole.
- Audit zaznamená odmietnutia: mimo skupiny, nulová váha, neaktívne kolo, neplatné tlačidlo, duplicitný hlas a prekročený limit. Opakovaný platný hlas pri dopĺňaní kandidáta sa neodmieta, ale nahrádza predchádzajúci.
- Pest testy pokryjú migrácie, skupiny, dopĺňanie kandidáta a opravu hlasu, hranice väčšiny, priebeh kôl, limity hlasov, audit, prepínanie prezentácie a exporty.
- Browser testy overia prezentačnú tabuľku v nízkom aj širokom viewport-e, pri malom aj veľkom počte kandidátov, bez scrollovania a bez prekročenia hraníc okna.

## Predpoklady

- Váha zariadenia určuje hodnotu hlasu; menovateľ väčšiny je súčet váh všetkých platne prijatých hlasov v konkrétnom kole.
- Skupiny obmedzujú iba hlasovanie o voliteľnom doplnení kandidáta, ak je kandidátovi priradená lokalita; bez priradenej lokality sa toto obmedzenie nepoužije. Samotné voľby súťaže skupiny neobmedzujú.
- Kandidátka sa pred otvorením volebného kola uzamkne; zmeny prebehnú cez prípravnú fázu doplnenia kandidáta.
