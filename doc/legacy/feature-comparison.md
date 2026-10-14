# Porovnání funkcí: staré verze a nový web

Stav k 14. 10. 2026. Zdroje jsou v `resource/original-web/` (záloha z FTP a databázový dump).

- **Aplikace 2023** – `ftp/include/app/`: starší web na frameworku Nette. Rok je vidět v textech
  (kartička Z-lístku „ples 2023“, e-mailové šablony s datem 4. 2. 2023). Žádný soubor ve `www/`
  ji nespouští, na hostingu zůstala jen jako pozůstatek.
- **Aplikace 2025/2026** – `ftp/www/`: samostatné PHP skripty, které skutečně běžely. Patří k nim
  databázový dump v `mysql/` (ples 7. 2. 2026).
- **Naše verze** – nový web v `src/`.

„Jinak“ znamená, že verze řeší stejnou potřebu jiným způsobem.

| Funkce | Aplikace 2023 | Aplikace 2025/2026 | Naše verze |
|---|---|---|---|
| Z-lístky (vstup zdarma pro tým) | ano: jméno, vyzvednutí, tisk s rozpisem služeb | ano: jméno a funkce, tisk kartiček | ano: jméno a funkce, QR kód a kontrola u vstupu, vyzvednutí, tisk i e-mail vybraných, rozpis služeb |
| Pásky na alkohol (počet lidí 18–30) | ano | ne | **ne** |
| Zlevněná místa | ano | ne | **ne** |
| Dvě ceny podle data | ano (natvrdo, nepoužité) | ne | ne |
| Platba hotově / převodem u rezervace | ano: přepínač, součty zvlášť, vlastní e-mail | ne | **částečně**: jen „zaplaceno hotově“ |
| Vrácení omylem potvrzené platby + omluvný e-mail | ano | ne | **ne** |
| E-mail zákazníkovi o zrušení rezervace | ano | ano | **ne** |
| E-mail organizátorovi o nové rezervaci | ano | ne | **ne** |
| Texty e-mailů upravitelné v administraci | ne (šablony v kódu) | ano, se zástupnými znaky | **ne** |
| Úprava kontaktu rezervace v administraci | ne | jen formulář, který neukládá | **ne** (jen poznámka) |
| Automatické otevření prodeje v daný čas | ne | ano | **ne** (ruční přepnutí stavu) |
| Tištěný seznam hostů pro vstup | ano | ne | **částečně**: jen CSV |
| Role administrátorů | ne | jen natvrdo: vrátit vstup smí jen jeden konkrétní účet | ne, všichni jsou si rovni |
| Stránka po plesu (odkaz na fotky) | ne | ano, natvrdo | ano (upravitelná stránka stavu „po plese“) |
| Přístup před veřejným prodejem | ne | povolené IP adresy | jinak: tajné odkazy pro testery a VIP |
| Limit lístků | součet na e-mail | jedna rezervace na e-mail | na rezervaci (více rezervací na e-mail záměrně) |
| Úprava sálu | přidat / posunout / smazat židli | souřadnice židlí | jinak: nahrání SVG plánku |
| Lístky bez místenky | ano | ano | ano |
| Platba QR kódem | ano | ano | ano |
| Párování plateb z banky | ne (ručně) | ne (ručně) | ano (Fio, ruční import) |
| Lhůta splatnosti a upomínky | ne | 7 dní, rušení ručně | ano (lhůta, „po splatnosti“, upomínky) |
| Vstupenky pro zákazníka | stránka chráněná e-mailem | stránka chráněná e-mailem | tajný odkaz, QR kód na každé vstupence |
| Kontrola u vstupu | ano (číslo místa + e-mail) | ano (skener QR, číslo místa) | ano (skener i ruční kód, vrácení vstupu) |
| Správa účtů administrátorů | ano | ne (jen přihlášení) | ano |
| Záznam všech událostí | ne | ne | ano |
| Postupné spouštění prodeje (testování → VIP → veřejný → konec) | ne | ne | ano |

## Z-lístky

Volné vstupy pro tým, který ples připravuje a obsluhuje. Nemají cenu, e-mail ani rezervaci, nesedí
na žádném místě a nepočítají se do kapacity sálu. V dumpu 2026 jich je 66. U každého je jméno
a funkce: bar, kuchyně, šatna, vstup, víno, tombola, výzdoba, program, polonéza, moderování, DJ,
fotograf, hasiči a podobně.

- **Aplikace 2023** (tabulka `z_tickets`, `AdminModule/presenters/TicketsPresenter.php`,
  `AdminModule/templates/Tickets/default.latte` a `z.latte`):
  - Jméno se upravovalo přímo v tabulce. Kliknutí na číslo označilo, že si člověk lístek vyzvedl.
  - Čísla začínala od 401 (`400 + id`), aby se nepletla s čísly židlí.
  - Tisk dával jednu kartičku na člověka s rozpisem služeb: pátek 16:00–18:30 přípravy, sobota
    8:00–13:00 přípravy, ples od 18:00, neděle 8:00 úklid.
  - Tlačítka „tisk bez informací k úklidu“ a „tisk jednoho lístku“ nefungovala, vždy se tiskly
    všechny s plným rozpisem.
  - Přidávání a mazání v kódu není, řádky se zřejmě vkládaly přímo do databáze.
- **Aplikace 2025/2026** (tabulka `zTicket`, `admin/index.php`, `admin/function.php`):
  - Formulář jméno + funkce a tisk kartiček „14. šumperský SKAUTSKÝ PLES“ s datem a místem
    napsanými natvrdo.
  - Kartičky neměly QR kód a u vstupu se nekontrolovaly. Neevidovalo se vyzvednutí, nešlo je
    upravit ani smazat.
  - Formulář pro přidání byl zranitelný vůči SQL injection.

## Poznámky

- **Ztracené funkce:** verze 2025/2026 vypustila několik věcí, které uměla verze 2023: pásky na
  alkohol, zlevněná místa, platbu hotově nebo převodem, vrácení platby, upozornění organizátorovi
  a tištěný seznam hostů. V posledním ročníku je tedy organizátoři neměli. Je potřeba se jich
  zeptat, které opravdu chybí a které vypustili záměrně.
- **Z-lístky** mají obě staré verze, takže se používají každý rok. Nový web je má od 14. 10. 2026
  (administrace → Z-lístky).
- **E-mail o zrušení rezervace** také měly obě staré verze. U nás ho administrace výslovně
  neposílá.
- **Upravitelné texty e-mailů** jsou novinka verze 2025/2026. Organizátoři si zřejmě zvykli texty
  měnit sami, bez programátora.
- **Kvalita staré verze:** verze 2025/2026 má funkce často jen natvrdo (datum, místo, jeden
  konkrétní účet v kódu) nebo nedokončené (formulář úpravy kontaktu neukládá). Formulář pro
  přidání Z-lístku je zranitelný vůči SQL injection. Povolení přístupu podle IP adresy si mohl
  zapnout kdokoli. Tyto věci nepřenášet doslova, jen převzít, k čemu sloužily.
- **Co máme navíc:** párování plateb z banky, upomínky, záznam událostí, postupné spouštění
  prodeje a QR kód na každé vstupence. Nic z toho žádná stará verze neuměla.

## Doporučené pořadí

1. ~~**Z-lístky**~~ hotovo.
2. **E-maily:** zrušení rezervace, upozornění organizátorovi, aktualizace textů pro rok 2027.
3. **Pásky na alkohol** a **platba hotově nebo převodem**.
4. Ostatní podle potřeby.
