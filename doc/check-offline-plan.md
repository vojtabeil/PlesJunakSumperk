# Plán: kontrola vstupenek jako PWA s offline režimem

Stav k 14. 10. 2026: **naprogramováno** včetně zpětné synchronizace. Rozhodnutí: synchronizace jako bonus (je
hotová), přepínání ručně, dvojí vstup z více telefonů offline se odhalí až po nahrání, data se z telefonu
smažou 3 dny po stažení. Zbývá ruční zkouška na telefonu (instalace, režim letadlo).

## Cíl

Aplikaci pro kontrolu vstupenek (`/admin/kontrola`) půjde nainstalovat do telefonu jako aplikaci (PWA).
Před plesem si stáhne data o všech vstupenkách a u vchodu pak může pracovat jen z nich, bez signálu.
Vstupy zapsané offline se po návratu signálu nahrají zpět na server.

## Výchozí stav

- Aplikace už má manifest (`src/www/kontrola.webmanifest`), ale:
  - chybí **service worker**, bez něj Chrome nenabídne instalaci a aplikace se bez sítě vůbec neotevře,
  - chybí **ikony PNG** 192 a 512 px, které Android k instalaci vyžaduje,
  - barvy jsou ještě stará zelená místo tmavě modré skautského stylu.
- Vyhledání kódu i zápis vstupu jdou živě na server (`Admin:Check`, `TicketCheck`).
- Skener QR je knihovna `qr-scanner` (nativní BarcodeDetector, jinak vlastní worker).

## 1. Instalace jako PWA

- **Manifest:** barvy loga, ikony PNG 192/512 včetně varianty „maskable“ pro Android, `id` aplikace.
- **Service worker** `kontrola-sw.js` s rozsahem jen `/admin/kontrola` (zbytek webu neovlivní):
  - při instalaci uloží „kostru“ aplikace: stránku kontroly, skript, styly, fonty, ikony a pomocný soubor
    skeneru QR (aby kamera skenovala i bez sítě),
  - statické soubory bere z mezipaměti, stránku zkusí ze sítě a bez sítě použije uloženou,
  - dotazy na API **neukládá nikdy**, ty řídí aplikace sama (bod 3).
- **Výsledek:** Chrome / Android nabídne „Nainstalovat aplikaci“, iPhone „Přidat na plochu“; nainstalovaná
  aplikace se otevře i bez signálu.

## 2. Stažení dat do telefonu

- **Nové API `GET /admin/kontrola/api/snapshot`**, jen pro přihlášeného administrátora. Vrátí všechny vstupenky:
  - kód, druh (místo / stání / Z-lístek), místo, jméno, číslo rezervace, stav platby, částka k doplacení,
  - u Z-lístku jméno a funkci,
  - zda a kdy už vstupenka prošla vstupem a kdo ji pustil,
  - neplatné vstupenky (zrušená rezervace, testovací mimo fázi testování) s důvodem, aby i offline zazněla
    přesná hláška,
  - čas vytvoření snímku.
  Na stovky vstupenek je to pár desítek kB.
- **Uložení v telefonu:** IndexedDB, s časem stažení.
- **Log:** stažení se zapíše do logu (kdo, kdy, kolik vstupenek).
- **Osobní údaje:** v datech jsou jména hostů, proto:
  - tlačítko „Smazat offline data“,
  - automatické smazání 3 dny po stažení.

## 3. Práce jen z offline dat

- **Přepínač v hlavičce: Online / Offline.** V režimu offline je trvale vidět proužek
  „Offline – data ze dne 7. 2. 18:30“, aby bylo jasné, z čeho se kontroluje.
- **Offline vyhledání** (QR kód, ruční kód i číslo rezervace / VS) vrací stejné výsledky jako server:
  v pořádku, už použito, nezaplaceno (s částkou k doplacení), neplatné, neznámý kód.
  - Logika podle `TicketCheck` se přepíše do samostatného čistého TypeScriptu (`check/offline.ts`), aby šla
    testovat.
- **Potvrzení vstupu, „Pustit celou rezervaci“ i „Vrátit vstup“** se zapisují jen v telefonu do seznamu
  offline vstupů s časem.
- **Počitadlo „Na plese x z y“** se počítá z dat v telefonu.

## 4. Zpětná synchronizace

Jde to: telefon si pamatuje, které vstupenky a kdy pustil (případně vrátil).

- Po návratu signálu tlačítko **„Nahrát vstupy na server (N)“** pošle seznam na nové API `POST api/sync`.
- **Server** (`TicketCheck::syncOffline`):
  - zapíše vstupy se skutečným časem z telefonu a v logu je označí „offline“,
  - opakované nahrání téhož seznamu nic nezdvojí,
  - vrátí **konflikty**, např. vstupenku, kterou mezitím pustil jiný telefon nebo kontrola online.
- **Aplikace** konflikty ukáže jako seznam „použito dvakrát“, aby šlo dohledat, kdo prošel vícekrát.
  Po úspěšném nahrání nabídne stáhnout čerstvá data.
- **Omezení, které obejít nejde:** dva telefony offline zároveň o sobě nevědí. Jedna vstupenka tak může projít
  oběma vchody; přijde se na to až při nahrání.

## 5. Testy a dokumentace

- **PHPUnit:** snapshot (obsah, neplatné a testovací vstupenky, jen pro přihlášené), synchronizace (zápis,
  konflikty, opakované nahrání), `PresenterAccessTest`.
- **bun test:** offline vyhledání, fronta vstupů, vrácení vstupu, automatické smazání, sloučení s výsledkem
  synchronizace.
- **Ruční zkouška na telefonu:** instalace, režim letadlo, skenování kamerou, návrat online, nahrání vstupů.
- **Dokumentace:** `AGENTS.md`, `doc/architecture.md`; návod pro organizátory na stránce kontroly
  (jak aplikaci nainstalovat a stáhnout data před plesem).

## Postup

1. PWA: manifest, ikony, service worker, registrace, instalace na telefonu.
2. Snapshot: API, model, log, uložení v telefonu, mazání.
3. Offline režim: přepínač, offline vyhledání a zápis vstupů, počitadlo.
4. Synchronizace: API, konflikty, zobrazení výsledku.
5. Testy, ruční zkouška, dokumentace.

## Otevřené otázky (v závorce navržená výchozí volba)

1. **Zpětná synchronizace** – chceme ji, nebo stačí offline režim bez nahrání? (Ano, udělat.)
2. **Přepínání** – ručně přepínačem, nebo automaticky při výpadku sítě s upozorněním?
   (Ručně: vždy je jasné, z jakých dat se kontroluje.)
3. **Počet telefonů u vstupu** – pokud jich je víc a všechny offline, dvojí vstup se odhalí až po nahrání.
   Stačí to? (Ano, s doporučením mít offline jen jeden telefon na vchod.)
4. **Automatické smazání dat z telefonu** po 3 dnech od stažení? (Ano.)
