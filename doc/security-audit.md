# Bezpečnostní audit

Stav k 16. 10. 2026 (commit `2af71e2` a odebrání grafu z přehledu). Audit kódu ve čtyřech oblastech:
přihlašování a přístupy, veřejné API a rezervace, XSS a nahrávání plánku, konfigurace a nasazení.
Nález 1 je ověřený pokusem (parser i prohlížeč), ostatní vycházejí z rozboru kódu. **Zatím nic není opravené.**

Celkově: žádný kritický problém, jeden vážnější (XSS přes plánek sálu) a dva středně závažné.

## Opravit před nasazením

### 1. XSS v administraci přes nahraný plánek sálu (střední až vysoká, ověřeno)

- **Kde:** `src/app/Model/Hall/HallPlanParser.php` (`sanitizeNode` nechává uzly CDATA), vložení plánku bez escapování
  v `src/app/Presentation/Admin/Tables/default.latte` (`{$planSvg|noescape}`).
- **Jak:** SVG s `<desc><g><![CDATA[><img src=x onerror=…>]]></g></desc>` projde parserem beze změny. Při vložení
  do HTML se z `<![CDATA[` stane komentář končící prvním `>` a za ním vznikne skutečný HTML prvek `<img>` se spuštěným
  `onerror` (ověřeno v Chromu: vznikne prvek v jmenném prostoru XHTML).
- **Dopad:** kdo podstrčí upravený výkres někomu, kdo ho nahraje, spustí skript v prohlížeči každého organizátora, který
  otevře „Stoly v prodeji“: formuláře administrace, export hostů, platby. Veřejný výběr míst (XML DOMParser +
  `importNode`) ani `/plan-salu.svg` (CSP sandbox) ohrožené nejsou.
- **Oprava:** CDATA převést na text (`LIBXML_NOCDATA` nebo náhrada uzlu za `DOMText`), z `title`/`desc` odstranit vnořené
  prvky, plánek v administraci vkládat stejně jako výběr míst (DOMParser) nebo přes `<img>`; regresní test; CSP (bod 7).

### 2. Výchozí heslo průvodce prvním spuštěním (střední)

- **Kde:** `src/config/common.neon` a `src/config/local.neon.example` (`setup.password: skaut-sumperk`), heslo je
  i v `AGENTS.md`, `doc/deploy.md` a `dev/portable/local.neon`; `SetupConfig::isValidPassword` odmítá jen prázdné.
- **Dopad:** dokud na ostrém serveru není administrátor, web všechny přesměrovává na `/admin/setup`. Když heslo nikdo
  nezmění, kdokoli, kdo web otevře dřív než organizátor, si založí prvního administrátora a má plnou kontrolu.
- **Oprava:** výchozí heslo z konfigurace odstranit (prázdné = průvodce zamčený, zobrazí „nastavte setup.password“),
  v ostrém režimu odmítnout `skaut-sumperk` a hesla kratší než ~16 znaků.

### 3. Nic nebrání robotovi blokovat sál a rozesílat e-maily (střední)

- **Kde:** `ReservationService::extend()` (držení míst bez celkového limitu), `confirm()` (bez ověření e-mailu),
  `Front/Api/ApiPresenter` (bez omezení četnosti).
- **Dopad:** skript otevře mnoho relací (token CSRF dostane s každou), v každé drží 10 míst a prodlužuje je – místa zůstanou
  obsazená po celou dobu útoku. Potvrzením falešných rezervací rozešle náš potvrzovací e-mail na libovolné adresy s
  vlastním textem ve jménu; rezervace drží místa, dokud je organizátor ručně nezruší.
- **Oprava:** omezit celkovou dobu držení míst jedné rozpracované rezervace (30–45 minut), omezit počet rozpracovaných
  rezervací a potvrzení z jedné IP, volitelně skryté pole proti robotům a automatické rušení rezervací dlouho po splatnosti.

## Menší nálezy

4. **Klíč pro cron v lozích** – `Cron/CronPresenter` bere klíč z adresy (`?key=`), takže končí v přístupových lozích
   serveru i ve výpisech Tracy. Posílat ho v hlavičce `X-Cron-Key` (už podporované), dokumentovat ji jako hlavní cestu
   a vyžadovat klíč aspoň 32 znaků.
5. **Přihlášení** (`Model/Admin/Authenticator.php`):
   - hláška „příliš mnoho pokusů“ prozradí, že účet existuje, a umožní skutečného administrátora na 15 minut zablokovat;
   - limit 5 pokusů jde obejít paralelními požadavky (čtení a zápis počtu nejsou atomické);
   - na PHP 8.3 jde podle doby odpovědi poznat existující účet (falešný hash má jinou náročnost než skutečné; na 8.4 ne).
   Oprava: stejná hláška pro zamčený i neznámý účet, atomické navýšení počtu pokusů, falešný hash generovat za běhu.
6. **Odhlášení odkazem (GET)** – `Admin/Sign/SignPresenter::actionOut`; cizí stránka může organizátora odhlásit.
   Převést na POST formulář.
7. **Chybí Content-Security-Policy, HTTPS přesměrování a HSTS** (`www/.htaccess`, `config/common.neon`). CSP by
   zastavila nález 1. Ověřit, zda hosting Lebeda vynucuje HTTPS; jinak přidat přesměrování a HSTS (cookie relace jsou
   `secure` jen přes HTTPS).
8. **Styly v plánku jdou obejít** – `HallPlanParser::safeCss` nezná escapování v CSS (`@\69mport`, `u\72l(`) a
   atributy jako `fill="url(http://…)"`, `filter`, `mask` se nekontrolují. Plánek tak může načíst cizí styly na veřejnou
   stránku i do administrace (vzhled, sledování; skript ne). Odstranit `<style>` nebo dekódovat escapy a kontrolovat
   `url()` ve všech atributech.
9. **Ostrý server se zapomenutou testovací bankou** (`bank.driver: mock`) tiše nenačítá platby. Na přehledu a
   v Platbách zobrazit varování, cron odpovědět chybou.
10. **Data v telefonu u vchodu** – kontrola vstupenek drží seznam hostů a kódy (IndexedDB, mezipaměť service workeru)
    3 dny i po odhlášení. Při odhlášení poslat `Clear-Site-Data` nebo data smazat.
11. **Neviditelné znaky ve jménu** (`ReservationService::confirm`) – obrácení textu, nulová šířka; jde jimi jméno
    zamaskovat. Odstranit znaky `\p{Cc}` a `\p{Cf}`.
12. **Portable verze** – pravidlo firewallu (`dev/portable/run.ps1`) otevírá port i ve firemních sítích (`Domain`)
    a správce má známé heslo `admin`/`admin`. Pro interní testování přijatelné; omezit na `Private` a `LocalSubnet`.

## Co je v pořádku

- Všechny formuláře, které něco mění, mají ochranu proti podvržení (`FormFactory`); API vyžaduje `X-CSRF-Token`
  porovnávaný přes `hash_equals`. Administrace je ve výchozím stavu zakázaná (test `PresenterAccessTest`).
- Hesla bcrypt s přepočtem, relace se při přihlášení obnovuje, smazání účtu nebo změna hesla odhlásí všude;
  průvodce prvním spuštěním po založení administrátora vrací 404 a je chráněný zámkem.
- Odkazy pro testery a VIP, „Moje rezervace“ a vstupenky: náhodné 128bitové tokeny, `hash_equals`, bez zjištění
  cizích rezervací; neplacenou vstupenku odhalí jen přihlášený organizátor.
- Rezervace: zámky řádků a atomické držení míst brání přeprodeji míst i stání; cenu počítá server.
- SQL: vše přes parametry, řazení a filtry jen z povolených hodnot, `ATTR_EMULATE_PREPARES` vypnuté.
- Bez tajných údajů v repozitáři; token Fio se nikam nezapisuje; tokeny se nelogují (`Settings::NotLogged`).
- Release neobsahuje vývojové stránky, ukázková data, `bin/`, testy ani Adminer; `app`, `config`, `var`, `vendor`
  jsou z webu nedostupné; ostrý režim vynucuje soubor `VERSION`.
- E-maily a QR platba escapované, CSV chráněné proti vzorcům, `/plan-salu.svg` s CSP sandbox a `nosniff`,
  JSON ve stránkách přes `Json::encode(..., htmlSafe: true)`, `X-Frame-Options: SAMEORIGIN` proti clickjackingu.

## Doporučené pořadí

1. Body 1–3 před nasazením (bod 1 je rychlá oprava, bod 3 pár hodin).
2. Body 4–7 (zhruba hodina).
3. Ostatní podle potřeby.
