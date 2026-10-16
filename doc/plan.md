# Implementation plan: Nette, admin, payments, frontend build

Status: all phases (1-5, 3b) implemented. Not verified against the real Fio API (no token yet) nor on the Lebeda hosting; see doc/deploy.md. Decisions were made in discussion with the
project owner on 2026-10-03; this file keeps the decisions, open questions and the backlog.

## 1. Decisions

| Area | Decision |
|---|---|
| PHP framework | **Nette 3.3** (DI container, Latte, Forms, Security, Tracy). Plain PDO stays for data access (no ORM). |
| IoC | Services depend on interfaces; the implementation is chosen in config (`src/config/local.neon`). |
| Bank | **Fio banka**, account of the scout group `2501895120/2010`; payments are made by **QR Platba (SPAYD)** with VS = reservation id. Read-only Fio API token ("Sledování účtu"). |
| Bank in development | `MockBankSource` + a dev page (`/dev/bank`) to create fake incoming payments. |
| Admin login | Own accounts (`admin_users`, `password_hash`). skautIS login is a possible later addition. |
| Frontend | **Preact + @preact/signals** only for the seat picker "island"; everything else is server-rendered Latte + Nette Forms, Naja where AJAX helps in admin. No SPA. |
| Build | **Bun** (bundler for TS/TSX, `bun test`), **Dart Sass** standalone (SCSS), **TypeScript 7** standalone (type checking only). No Node.js. |
| Portability | Every tool in `.tools/`, every cache/temp in `.devdata/`; nothing is installed system-wide. |
| Database changes | Greenfield (decided 2026-10-03): no migrations, `src/database/schema.sql` is edited directly and `dev\init-db.cmd` recreates the databases. |

### Pinned versions (as of 2026-10-03)

| Tool / package | Version | Notes |
|---|---|---|
| PHP | 8.4.26 | already in `.tools/php` |
| MariaDB | 11.8.9 | local stand-in for MySQL 8 on Lebeda |
| Composer | 2.10.3 | `composer.phar`, SHA256 from getcomposer.org |
| nette/application, bootstrap, forms | 3.3.0 | require PHP 8.3+ |
| nette/di, nette/security | 3.2.x | |
| latte/latte | 3.1.6 | |
| tracy/tracy | 2.12.1 | |
| chillerlan/php-qrcode | 6.0.1 | QR Platba image |
| phpunit/phpunit | 13.4.0 | dev only, needs PHP 8.4.1+ locally |
| phpstan/phpstan | 2.2.x | dev only |
| Bun | 1.4.2 | `bun-windows-x64.zip` |
| TypeScript | 7.0.2 | `typescript-win32-x64.tgz` (Go native, `tsc --noEmit`) |
| Dart Sass | 1.105.1 | `dart-sass-1.105.1-windows-x64.zip` |
| preact / @preact/signals | 11.0.0 / 2.11.3 | via `bun install` |
| Mailpit | 1.31.4 | already in `.tools/mailpit` |

**Risk:** the PHP version on Lebeda is unknown. If it is lower than 8.3, pin
`nette/application`, `nette/bootstrap` and `nette/forms` to 3.2.x (PHP 8.1+) instead.
Verify before phase 2.

## 2. Implemented phases

All phases are done; the detailed step lists are in the git history of this file (before 2026-10-14).
The current structure is described in `doc/architecture.md`.

| Phase | Result |
|---|---|
| 1 - Tooling | Portable Composer, Bun, TypeScript 7, Dart Sass; caches in `.devdata/`; tool wrappers. |
| 2 - Nette skeleton | Nette application, Preact seat picker, asset build and watchers. |
| 3 - Admin | Login, dashboard, reservations, settings, CSV export, event log. |
| 3b - First run | Setup wizard, equal administrators, login re-checked on every request, deny-by-default test. |
| 4 - Payments | QR Platba, mock bank (`/dev/bank`), payment import and matching, payment e-mails. |
| 5 - Fio and deployment | Fio API source, cron endpoint, release package (`dev\release.cmd`), `doc/deploy.md`. |

## 3. Cross-cutting rules

### 3.1 Security
- CSRF: Nette Forms tokens for forms, `X-CSRF-Token` header for the JSON API.
- Output escaping by Latte only; no raw HTML from user input.
- Prepared statements everywhere.
- Admin session: `httponly`, `samesite=Lax`, `secure` on HTTPS, regenerate on login, idle timeout 2 h.
- Secrets (DB password, SMTP, Fio token, cron key) only in `src/config/local.neon`.
- `/dev/*` presenters are not registered unless `bank.driver: mock` **and** debug mode.

### 3.2 Database schema
- Greenfield project: `src/database/schema.sql` is the single source of the schema and is edited directly.
  A migration runner (implemented in phase 2) was removed again on 2026-10-03; reconsider only
  once production holds data that must survive schema changes.

### 3.3 Testing and checks (`check.cmd`)
- `tsc --noEmit`, `bun test`, `vendor/bin/phpunit`, `vendor/bin/phpstan analyse`.
- PHP tests use `FrozenClock` and a dedicated test database `ples_test` (created by `dev\init-db.cmd -Test`).
- Manual browser check of the reservation page, admin and `/dev/bank` at the end of each phase.

### 3.4 Language
- Source code, comments, logs, docs in `doc/`: English (see `AGENTS.md`).
- UI texts and e-mails for visitors and organizers: Czech.

## 4. Open questions (for the organizers / hosting)

1. PHP and MySQL version on Lebeda (affects Nette 3.3 vs 3.2, section 1).
2. Can the Lebeda document root point to `www/`? Is cron available? Is outbound HTTPS to `fioapi.fio.cz` allowed?
3. SMTP for production: Lebeda SMTP or Seznam (`ples@junak-sumperk.cz`)?
4. Who creates the Fio API token (treasurer) and who receives admin accounts?
5. Real prices / capacities (the hall layout comes from the drawing, section 6).
6. Should unpaid reservations expire automatically after N days (releasing seats)? If yes, how many days
   and should a reminder e-mail be sent first? (Not in the plan yet.)

## 5. Backlog: legacy features not yet in the new site

Features of the old site (`resource/original-web/`, see `doc/legacy/original-web.md`) that the new site does not have yet. Data import from the
legacy database is out of scope. Each item names the legacy code it replaces.

1. ~~**Entrance check.**~~ Done (section 7).
   Legacy: `resource/original-web/ftp/www/check/tets.php` (jsQR camera scanner), `place.usedTime`.
2. **Alcohol wristbands.** Count issued and remaining wristbands per reservation.
   Legacy: `ticket.alcohol_strip_count`.
3. **Discounted tickets.** A product type with its own price.
   Legacy: `loweredCount` / `loweredPrice` in `Model\Tickets::orderTickets`.
4. ~~**Z-tickets**~~ Done: Z-lístky of the crew (`Admin:Crew`, `CrewMembers`), one per person with a free-text role,
   checked at the entrance, printed or e-mailed. Comparison of the legacy versions: `doc/legacy/feature-comparison.md`.
   Legacy: table `zTicket`, `Admin\TicketsPresenter::handleToggleZTaken`.
5. **E-mail templates for the 2027 ball.** Legacy templates in `resource/original-web/ftp/include/app/email/`
   still carry the 2023 date, the old account and the old signatory.
6. **Closing a year.** Archive the reservations of the past ball and clear `seat_bookings`, so the next
   year starts empty (needed before a new hall plan can remove seats that were sold last year).
7. ~~**Offline entrance check.**~~ Done: the check app installable as a PWA, working from downloaded data
   without a signal and uploading the entries afterwards. Plan and decisions: `doc/check-offline-plan.md`.

## 6. Hall plan from SVG (decided 2026-10-14)

Goal: one source of truth for the hall. The drawing (`resource/design/hall-plan-2026.cdr`, CorelDRAW; how it is made: `doc/hall-plan.md`)
is the source; the database only references its shapes. The drawing is edited and exported through
the CorelDRAW MCP server (`c:\Git\coreldraw-mcp`).

### 6.1 Principles

- The database stores no coordinates or sizes, only the identity of a seat or table and the `id` of its
  shape in the SVG (`svg_id`).
- The page inlines the SVG; the seat picker finds shapes by `svg_id`, changes their classes and
  attributes (state, `role="button"`, `tabindex`, `aria-*`) and handles clicks on them.
  Neighbours for arrow keys come from the shapes' positions in the browser (`getBBox()`).
- The plan is uploaded in the administration (Nebezpečná zóna -> "Plánek sálu"), so next year only a new export is needed.
- The hall layout and the sale are two tables: the layout is changed only by a plan upload,
  the sale only by reservations.

### 6.2 Drawing conventions

Changed 2026-10-16 with the new hall (KKC Rapotín): tables are groups `table-A1`, seats `seat-A1-5` numbered from 1
at every table (label "A1/5", shown as "Stůl A1 · místo 5"); seats and tables are matched by label, their ids are
independent and `sort_order` keeps the hall order. Full conventions and how the drawing is made: `doc/hall-plan.md`.
(The 2025 drawing numbered seats 1-270 across the hall and used the number as the seat id.)
### 6.3 Database

```
hall_plan     (id = 1, svg, file_name, applied_by, applied_at)   -- at most one row: the only version
hall_tables   (id, label, name, svg_id, active)
seats         (id, label, table_id, svg_id, active)
seat_bookings (seat_id PK -> seats, reservation_id NOT NULL -> reservations ON DELETE CASCADE,
               state ENUM('held', 'reserved'), held_at)
```

- There is only one version of the plan (decided 2026-10-14): uploading a new one replaces it for good,
  so it is a dangerous action. An uploaded file waits for confirmation only as a temporary file in
  `var/temp` (random name, deleted after confirmation, cancellation or an hour), never as a second version.
- A booking row exists only for a held or reserved seat; a free seat has none. Holding a seat is an
  `INSERT` (the primary key makes it atomic), an expired hold is deleted.
- Removed: settings `map_width` / `map_height` (the `viewBox` has the size), `ReservationService::HallAreas`
  (stage and dance floor are in the drawing), seat and table coordinates, the hand-written `hall.sql`.

### 6.4 The plan as a file

- `/plan-salu.svg?v=<hash of the content>` (public, used by the seat picker) and `/admin/nebezpecne/plan.svg`
  (download, the only backup before a replacement).
- `Content-Type: image/svg+xml; charset=utf-8`, `X-Content-Type-Options: nosniff`, a CSP without scripts;
  the public URL is cached for long (the hash changes with the content); 404 while there is no plan.

### 6.5 Dangerous zone and the import (`App\Model\Hall`)

- `/admin/nebezpecne` (`Admin:Danger`): a separate section marked red in the menu with "Plánek sálu",
  "Smazat testovací rezervace" (moved from Stav webu) and later "Uzavřít ročník". Every action is a POST form
  with a confirmation checkbox and is logged.
- `HallPlanParser`: reads the SVG, finds `seat-*` / `table-*` and their nesting, reports errors
  (duplicate ids, seat outside a table, empty or invalid plan) and sanitizes the SVG (no scripts,
  `foreignObject`, `on*` attributes, external references, inline colours of seats).
- `HallSync::preview`: errors, changes (new / removed / moved seats and tables, renamed tables) and the
  **affected reservations**: reservations holding a seat that the new plan does not have, with contact,
  status, paid amount and the seats. The admin calls them before confirming and changes what is needed by hand.
  Seats being selected right now are only released (shown as a count).
- Confirmation: "the current plan is replaced for good" and, with affected reservations, "N reservations
  will be cancelled (paid X Kč)".
- `HallSync::apply` (transaction): computes the affected reservations again with locked rows and stops with a
  new preview when they changed; cancels the affected reservations (like a cancellation in the admin, reason
  "nový plánek sálu"); inserts / updates tables and seats; removed ones get `active = 0` (history stays readable,
  reactivated if they come back); replaces the plan; logs `hall.replaced`. Customers get no automatic e-mail.
- A new installation has no plan: the dashboard asks to upload one and seats cannot be sold until then
  (standing tickets can). `bin/import-hall.php` uses the same code; `init-db` imports a demo plan
  (`src/database/hall-plan.svg`).

### 6.6 Steps

1. Split `seats` into `seats` (layout) and `seat_bookings` (sale) without changing behaviour. **Done.**
2. Parser + sanitization + tests (small fixture SVG). **Done.**
3. Schema, `HallSync` (preview, affected reservations, apply) + tests, `bin/import-hall.php`, demo plan in `init-db`. **Done.**
4. Dangerous zone: "Plánek sálu" (download, upload, preview, confirmation) + moved deletion of test reservations. **Done.**
5. URLs with the SVG file + headers + tests. **Done.**
6. Seat labels: kept as "<table>/<seat number>" (decided during the implementation). **Done.**
7. Seat picker on the SVG from the URL; remove `HallAreas`, `map_*`, coordinates, `hall.sql`. **Done.**
8. Real drawing: object ids and table groups set through the CorelDRAW MCP, SVG export, first upload
   (needs the MCP server connected).
9. Release checks, `doc/architecture.md`, `AGENTS.md`.
## 7. Tickets and the entrance check (decided 2026-10-14)

Replaces the legacy `check/` (QR with `/check/<seat>/<e-mail>`, guessable; undo allowed to one hard-coded admin).
UI inspired by the Pomuckar QR app (full-screen camera, result card over it, round buttons).

### 7.1 Tickets

```
tickets (id, reservation_id -> reservations ON DELETE CASCADE, seat_id NULL -> seats (NULL = standing),
         code CHAR(6) UNIQUE, print_token CHAR(32), created_at, used_at NULL, used_by NULL -> admin_users)
```

- One ticket per seat and per standing ticket, created when the reservation is confirmed.
- `code`: 6 random hex characters (`random_bytes`, regenerated on a collision), unrelated to the seat,
  reservation or e-mail. It is in the QR code and typed by hand at the entrance (case-insensitive, O = 0).
- QR content: `https://<site>/admin/kontrola/<code>`; the scanner extracts the code, a phone camera opens the
  check page after login.
- `print_token`: secret part of the public link `/vstupenka/<code>/<print_token>` (one printable ticket).
  The 6-character code alone could be enumerated, so it never opens anything public.
- A cancelled reservation keeps its tickets but they are invalid; deleting a test reservation deletes them.

### 7.2 Decisions

- Per-ticket codes (a group may arrive one by one); the check can confirm the remaining tickets of the reservation at once.
- An unpaid or partially paid reservation is let in with a clear warning and the amount to pay.
- Visitors get the tickets once the reservation is fully paid (bank import or marked paid by hand):
  e-mail with QR codes and print links, list on "Moje rezervace"; "Poslat vstupenky znovu" in the admin.
- Any administrator may undo an entry (all administrators are equal); it asks for confirmation and is logged.
- Online only; an offline mode (preloaded codes) only if the hall turns out to have no signal.

### 7.3 Check (`App\Model\Ticket`)

- `lookup(code)`: ticket, reservation, payment state, other tickets of the reservation; result
  ok (paid, unused) / used (when, by whom) / unpaid (amount left) / invalid (cancelled, unknown code, test ticket).
- `use(code)`: atomic `UPDATE ... WHERE code = ? AND used_at IS NULL`; `useReservation(id)`; `undo(code)`.
- Manual input also accepts the reservation number or variable symbol (shows all its tickets).
- Event log (in the model): `ticket.used`, `ticket.unused`, `ticket.sent`.

### 7.4 Check application

- `/admin/kontrola[/<code>]`, presenter `Admin:Check` (login required), own full-screen layout.
- Preact entry `assets/ts/check.tsx`, `assets/scss/check.scss`, scanner library `qr-scanner` (works on iOS).
- Scan pauses on a result until it is confirmed or closed; big confirm button, colour, vibration, sound.
- Round buttons: camera choice (remembered), torch, full screen, install (PWA manifest), back to the admin;
  Wake Lock keeps the screen on.
- JSON: `GET lookup`, `POST use`, `POST useReservation`, `POST undo`, header `X-CSRF-Token`.

### 7.5 Administration

- Reservation detail: tickets with code, state, print link; "Poslat vstupenky znovu".
- Dashboard: "Na plese: N / M" and a link to the check; guest list CSV: used tickets.

### 7.6 Steps

1. Table `tickets`, creation on confirmation, codes, tests, seed.
2. `TicketCheck` model + event types + tests.
3. Tickets for visitors: print page, e-mail after full payment, "Moje rezervace", resend.
4. Check application (presenter, layout, JSON, Preact, scanner, manual input, PWA).
5. Dashboard, reservation detail, CSV, documentation.

All steps done (2026-10-14). Not tried yet on a real phone over HTTPS (camera access needs HTTPS outside localhost).
