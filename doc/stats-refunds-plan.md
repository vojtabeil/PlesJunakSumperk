# Plán: vrácení peněz, detail rezervace a statistiky

Stav k 16. 10. 2026: **naprogramováno** podle schváleného plánu (rozhodnutí viz konec). Rozsah podle revize administrace: tři menší
vylepšení (vrácení peněz, telefon / e-mail a splatnost v detailu rezervace, malý graf prodeje na přehledu)
a nová stránka se statistikami, u každého prvku se staženými daty v CSV.

## Výchozí stav (co jsem zjistil v kódu)

- **Zrušení rezervace** (`ReservationAdmin::cancel`) jen změní stav na `cancelled` a uvolní místa;
  `paid_amount` zůstává. Nikde se ale neeviduje, že peníze je potřeba vrátit, ani že už vráceny byly.
- **Platba, která přijde na už zrušenou rezervaci**, se nespáruje: `PaymentMatcher::lockReservation` bere jen
  potvrzené rezervace, takže platba skončí jako „neznámý VS“ v Platbách k vyřízení a z ní se nepozná, ke které
  rezervaci patří.
- **Přeplatek** (`overpaid`) jde dnes jen „vyřídit“ (`settle`): platba přestane být problém, ale nezůstane
  záznam, kolik a kdy se vrátilo.
- **Odchozí pohyby z banky** (vrácené platby) se záměrně neukládají (sdílený účet střediska), takže vrácení se
  musí zapsat ručně.
- **Hotovost:** „Označit jako zaplacené (hotově)“ nastaví `paid_amount = total_price`; částka v hotovosti je
  jen v logu (`reservation.paid_manually`, `details.amount`).
- **Detail rezervace:** e-mail už je odkaz `mailto:`, telefon jen text. Splatnost je jen ve větě v záhlaví
  („Čeká na platbu … splatnost 18. 10. 2026 – po splatnosti“); kdy odešla připomínka (`reminded_at`), se
  nezobrazuje.
- **Data pro statistiky** jsou k dispozici: `reservations` (`confirmed_at`, `paid_at`, `total_price`,
  `paid_amount`, `channel`, `standing_tickets`), `seat_bookings` / `seats` / `hall_tables` (obsazenost,
  `available`), `tickets` (`used_at` = vstup), `bank_transactions` (`booked_on`, `amount`, `match_status`),
  `event_log` (zrušení a platby hotově s datem). Grafická knihovna v projektu není; přehled kreslí sloupce
  v CSS (`Accessory/StackedBar`), CSV umí jen seznam hostů (`GuestListCsv`).

## 1. Vrácení peněz

**Cíl:** pokladník na jednom místě vidí, komu a kolik ještě dluží, a zapíše, že peníze vrátil.

- **Nová tabulka `refunds`:** rezervace, částka, datum vrácení, způsob (převodem / hotově), poznámka (např.
  číslo účtu nebo „vráceno na plese“), kdo zapsal, kdy. Částky jako ostatní platby (`DECIMAL(12,2)`).
- **Kolik je potřeba vrátit** (počítá se, neukládá):
  - u zrušené rezervace vše zaplacené (banka + hotovost) minus už vrácené,
  - u platné rezervace přeplatek: zaplaceno − cena − vrácené.
- **Platba na zrušenou rezervaci:** spáruje se s ní (stav platby `overpaid`, rezervace zůstane zrušená)
  a částka se přičte k „k vrácení“. Na stránce Platby bude vidět u rezervace, ne jako neznámý VS.
- **Detail rezervace** dostane kartu „Vrácení peněz“ (jen když je co vracet, nebo už se něco vrátilo):
  - „K vrácení 700 Kč“ výrazně, u převodu číslo účtu plátce z banky, pokud je známé,
  - formulář: částka (předvyplněná zbytkem), datum (dnes), převodem / hotově, poznámka, „Zapsat vrácení“,
  - seznam zapsaných vrácení se zrušením zápisu (omyl); obojí do logu (`refund.recorded`, `refund.deleted`).
- **Zrušení zaplacené rezervace:** potvrzovací text doplní „zaplaceno 700 Kč – po zrušení se objeví
  v K vrácení“.
- **Přehled → Co je potřeba udělat:** „K vrácení: 2 rezervace, 1 400 Kč“ s odkazem na seznam rezervací
  s filtrem (nový problém `refund` v `ReservationAdmin::Problems`).
- **Vyřízení přeplatku** (`settle`) zůstane pro případ, kdy se rozdíl nevrací (dar); text tlačítka to řekne.
- **Statistiky** počítají tržby po odečtení vrácených částek (bod 4).

## 2. Detail rezervace: kontakt a splatnost

- **Kontakt:** tlačítka „Zavolat“ (`tel:`) a „Napsat e-mail“ (`mailto:` s předvyplněným předmětem „Rezervace
  č. 12 – Šumperský skautský ples“) vedle údajů; na telefonu jako velká tlačítka nahoře u akcí.
- **Splatnost** jako samostatný řádek v údajích: „Splatnost 18. 10. 2026“ a u nezaplacené po splatnosti
  červený štítek „po splatnosti 3 dny“ i v záhlaví. Řádek „Připomínka odeslána 19. 10. 2026“ (`reminded_at`).
- **Seznam rezervací:** u nezaplacených po splatnosti stejný štítek ve sloupci Stav.

## 3. Malý graf na přehledu

- V kartě Lístky pod sloupci: drobný sloupcový graf prodaných lístků za posledních 14 dní (dnešek vpravo),
  číslo „za 7 dní prodáno 42 lístků“ a odkaz „Statistiky →“.
- Kreslený na serveru jako SVG (bez knihovny a bez JavaScriptu), s popisem pro čtečky obrazovky.

## 4. Stránka Statistiky (`/admin/statistiky`)

Odkaz „Statistiky“ je mezi dlaždicemi dole na přehledu (Další části administrace) a u malého grafu na
přehledu; do hlavního menu se nepřidává. Každý blok má vpravo nahoře
„Stáhnout CSV“ se zdrojovými daty bloku (`/admin/statistiky/csv/<blok>`; CSV jako seznam hostů: UTF-8 s BOM,
středníky, otevře se rovnou v českém Excelu). Testovací rezervace se nikde nepočítají; VIP ano, s rozlišením.

1. **Lístky** (to, co je na přehledu): místa u stolů, na stání a Z-lístky podle stavu (zaplaceno, částečně,
   nezaplaceno, právě se vybírá, volné, mimo prodej) a kolik prošlo vstupem.
   CSV: druh lístku × stav → počet.
2. **Prodej po dnech:** od první rezervace do dneška (po plese do dne plesu):
   - sloupcový graf prodaných lístků za den (místa a stání barevně odlišené) a čára „celkem prodáno“,
   - pod ním tabulka po dnech: nové rezervace, lístky na místo, na stání, hodnota objednávek, zrušené rezervace,
     přijaté platby (banka podle data připsání + hotovost podle logu).
   - Den = datum rezervace (potvrzení). Počítají se platné rezervace; zrušená rezervace se z prodeje svého dne
     odečte (minulé dny se tak zpětně mění) a ve sloupci „zrušeno“ je vidět u téhož dne, kdy vznikla.
   CSV: totéž po dnech.
3. **Tržby:**
   - objednáno (cena platných rezervací), z toho zaplaceno (převodem / hotově zvlášť), čeká na platbu,
     z toho po splatnosti,
   - vráceno a ještě k vrácení (bod 1), přeplatky,
   - čistá tržba = přijato − vráceno; průměr na rezervaci a na lístek,
   - rozdělení podle prodeje (veřejný / VIP).
   CSV: po rezervacích (číslo, jméno, stav, cena, zaplaceno převodem, hotově, vráceno, k vrácení, splatnost).
4. **Obsazenost:**
   - na sezení: celkem / v prodeji / prodáno / zaplaceno / volné / mimo prodej, v procentech,
   - po stolech: tabulka stůl × (míst, prodáno, zaplaceno, volné, mimo prodej) seřazená podle sálu,
     s malým pruhem u každého stolu; sál, salónek, balkon zvlášť podle písmene stolu,
   - na stání: kapacita / prodáno / zaplaceno / zbývá.
   CSV: po stolech (a druhé CSV po místech: místo, stůl, stav, číslo rezervace).

**Technicky:** nový model `App\Model\Stats\SalesStats` (dotazy, vše v haléřích jako jinde), presenter
`Admin:Statistics` s akcí `csv`, obecný zapisovač CSV (z `GuestListCsv` se vytáhne společná část), grafy jako
SVG ze šablony (`Accessory/barChart.latte`). Testy: součty po dnech proti stavu rezervací, vrácení a přeplatky,
CSV hlavičky a kódování.

## 5. Postup a dokumentace

1. Vrácení peněz (schéma, model, detail, přehled, platby na zrušenou rezervaci) + testy.
2. Detail rezervace: kontakt a splatnost.
3. Statistiky: model + CSV + stránka + dlaždice na přehledu; pak malý graf na přehledu.
4. `doc/architecture.md`, `AGENTS.md` (vrácení peněz, statistiky), `check.cmd`, ruční kontrola v prohlížeči
   (desktop i telefon), znovu `dev\init-db.cmd` kvůli nové tabulce.

## Rozhodnutí (16. 10. 2026)

1. **Platba na zrušenou rezervaci** se automaticky spáruje se zrušenou rezervací a přičte se k „k vrácení“
   (místo dnešního „neznámý VS“).
2. **Zápis vrácení jde smazat** (oprava omylu); smazání se zapíše do logu.
3. **Prodej po dnech podle data rezervace:** počítají se platné rezervace v den, kdy vznikly; zrušené se od
   svého dne odečtou (ve sloupci „zrušeno“ u téhož dne).
4. **Statistiky nejsou v hlavním menu,** jen v dlaždicích dole na přehledu a u malého grafu.
5. **Vstup na plese po čtvrthodinách se nedělá;** počty prošlých vstupem zůstávají v bloku Lístky.
6. **Malý graf na přehledu byl po zavedení odebrán** (16. 10. 2026); prodej po dnech je jen na stránce Statistiky.
