# Obnova původního webu ples.junak-sumperk.cz

Staženo 2026-10-03 z produkčního webu (Apache 2.4 / PHP, Debian).

## Co se podařilo zachránit

| Soubor | Popis |
|---|---|
| `index.rendered.html` | HTML vygenerované z PHP (stav „po plese“, odkaz na fotky 2026). Obsahuje zakomentovanou starou úvodní stránku (13. ročník, 3. 2. 2024, KD Nový Malín, Lucky Band). |
| `style.css` | Kompletní styly včetně rezervační mapy sálu (`.chair`, `.chair-selected`, `.chair-reserved`), počítadla lístků (`.quantity`) a mobilní verze (`max-width: 850px`). |
| `script.js` | Kompletní klientská logika rezervací (viz níže). Na konci je zakomentovaný zoom/posun mapy sálu (myš + pinch na mobilu). |
| `kluk.svg`, `holka.svg`, `bcgImg.svg`, `ikony/*.svg` | Grafika (pozadí `bcgImg.svg` má ~10 MB – v novém webu ho optimalizovat). |

## Co zachránit nejde

PHP zdrojáky (`index.php`, `ajax.php`, admin), databáze a šablona rezervačního formuláře/mapy sálu.
Server je vykonává, takže se přes HTTP nikdy nedostanou ven. Je potřeba přístup k hostingu (FTP/SSH)
nebo záloha od předchozího správce. Wayback Machine má snímky (např. 2025-03-08), z tohoto počítače
ale neodpovídal – stojí za to zkusit ručně v prohlížeči:
https://web.archive.org/web/2025*/ples.junak-sumperk.cz*
(tam může být HTML stránky s mapou sálu a formulářem v době prodeje).

## Rekonstruované API `ajax.php` (vše POST, form-encoded)

| Parametry | Význam | Odpověď |
|---|---|---|
| `email` | Ověření e-mailu, založení session rezervace | JSON `{"state": "new" \| "exist" \| "error", "id": <ticket_id>}`; `error` = na mail už rezervace existuje |
| `idCh`, `emailR`, `act=true` | Dočasně zablokovat místo `idCh` | `save` / `error` |
| `idCh`, `emailR`, `act=false` | Uvolnit místo | `save` / `error` |
| `ids[]` | Stav míst (polling každé 2 s) | JSON `{ "<id>": {state: "free"\|"book"\|"reserved", ticket_id, times, timesA} }` |
| `numeri=true` / `numeric=false` | +1 / −1 lístek bez místa (na stání) | – |
| `sesTime=odstran` | Zrušení dočasné rezervace v session | – |

Obchodní pravidla vyčtená z JS:
- Před výběrem míst je nutné zadat platný e-mail (1 rezervace na e-mail).
- `maxTicket` (limit lístků na rezervaci) vkládá PHP do stránky; místa s místenkou + lístky bez místa se sčítají.
- Stav `book` = dočasná blokace na **120 s** (`timesA - times <= 120`), poté místo propadne. Klient odpočítává a po vypršení reloaduje stránku.
- `reserved` = závazně rezervováno.
- Formulář má checkboxy `name="places[]"` s `data-id`, `id` = id místa, a `span#span<id>` pro vykreslení židle; pole `#emailInput`, `#numeric`, `#overeni`, `#odpocet`, tlačítko `#odeslat`.

## Poznámky k bezpečnosti pro nový web

- Stará verze nemá CSRF ochranu a klient posílá e-mail volně – v novém webu ověřovat na serveru.
- `var maxTicket = ;` v aktuálním HTML je syntaktická chyba (PHP vypsalo prázdnou hodnotu).
- Na stránce se načítá jQuery dvakrát (3.6.0 a 3.6.4).
