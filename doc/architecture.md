# Architecture and layout

## Top level

| Path | Purpose |
|---|---|
| `src/` | The application: a self-contained Nette project with its own `composer.json`, `package.json`, `vendor/`, `node_modules/` and `var/`. The release package is built from it. |
| `doc/` | `plan.md` (decisions, open questions, backlog), `deploy.md` (deployment on Lebeda), this file, `legacy/` (notes on the old site). |
| `resource/original/` | Old site as served over HTTP (frontend only), local URL `/original/`. Notes: `doc/legacy/original.md`, `doc/legacy/original-backend.md`. |
| `resource/original-web/` | Complete FTP backup of the old site incl. PHP sources; credentials and dumps are git-ignored. Notes: `doc/legacy/original-web.md`, comparison with the new site: `doc/legacy/feature-comparison.md`. |
| `resource/design/` | CorelDRAW drawings (hall plan); `Backup_*.cdr` are git-ignored. |
| `dev/` | Local environment: PowerShell scripts, `env.cmd` (tool environment), `router.php` (PHP built-in server), wrappers of the portable tools. |
| `setup.cmd`, `start.cmd`, `stop.cmd`, `check.cmd` | Everyday commands; the rest is in `dev/`. |
| `.tools/` | Portable PHP, MariaDB, Mailpit, Adminer, Composer, Bun, TypeScript 7, Dart Sass (git-ignored). |
| `.devdata/` | Local DB data, captured e-mails, logs, PID files, sessions, temp files, package caches (git-ignored). |
| `dist/` | Release packages (git-ignored). |

## Application (`src/`)

| Path | Purpose |
|---|---|
| `app/Bootstrap.php` | Nette configurator (debug mode only on localhost, Tracy logs in `var/log`). |
| `app/Core/RouterFactory.php` | Routes: `/` reservation, `/rezervace/<id>/<token>` "Moje rezervace" (secret link from the e-mails), `/api/<op>` JSON, `/cron/payments`, `/vstupenka/<code>/<token>` printable ticket, `/plan-salu.svg` hall plan, `/admin/nebezpecne[/plan]` dangerous zone, `/admin/kontrola[/<code>]` entrance check (+ `/admin/kontrola/api/<op>`), `/tester/<token>` and `/vip/<token>` secret links, `/admin/<presenter>/<action>[/<id>]`, `/dev/status`, `/dev/bank`. |
| `app/Core/SetupGuard.php` | No administrator = site not configured: every presenter except the setup wizard is disabled (pages redirect, API 503). |
| `app/Core/Clock/` | `Clock` interface (`SystemClock`; `tests/php/Support/FrozenClock` in tests). |
| `app/Core/Http/` | `HttpClient` interface + `CurlHttpClient` (tests use `tests/php/Support/FakeHttpClient`; no test calls a real service). |
| `app/Core/Database/` | PDO factory. |
| `app/Model/Reservation/` | `ReservationService` (all reservation rules), `ReservationAdmin`, `SeatOverview`, `ReservationSession`, `ReservationError`. |
| `app/Model/Settings/` | `Settings`: event info, prices and limits stored in the `settings` table. |
| `app/Model/Site/` | `SiteMode` (stages of the site), `SiteAccess` (tester and VIP tokens). |
| `app/Model/Payment/` | `PaymentImporter` (fetch -> store incoming ones, outgoing are skipped -> match -> e-mail; manual button or cron), `PaymentMatcher` (partial payments add up; assign, ignore, settle and their undo), `PaymentCandidates` (suggested reservations of an unmatched payment by amount / payer / message, search by name, e-mail, number or VS), `VariableSymbol` (VS = prefix + id, e.g. 20260003), `QrPayment` (IBAN + SPAYD + PNG), `PaymentRepository`. |
| `app/Model/Payment/Bank/` | `BankTransactionSource` (interface; `Mock/MockBankSource` locally, `Fio/FioApiSource` in production, chosen by `bank.driver` in `BankSourceFactory`; both `RewindableSource`), `BankTransaction`. |
| `app/Model/Mail/` | `MailSender` interface, SMTP implementation, `ReservationMailer`, `CrewMailer` (Z-lístek by e-mail) + Latte e-mail templates. |
| `app/Model/Log/` | Event log of everything that happens (`EventLog::record`, actor from `Actor`: customer/admin/cron/system), `EventTypes` (Czech labels, categories), `EventLogRepository` (filters by date, seat, type, actor, reservation), `EventFormatter`. |
| `app/Model/Admin/` | Organizer accounts (`AdminUsers`), login with lockout + per-request DB check (`Authenticator` as `IdentityHandler`), `SetupConfig`. |
| `app/Model/Export/` | `GuestListCsv`. |
| `app/Model/Hall/` | Hall plan (SVG): `HallPlanParser` (tables / seats by id, sanitization), `HallSync` (the only version in `hall_plan`, preview of changes and affected reservations, apply), `HallUploads` (upload waiting for confirmation in `var/temp`). |
| `app/Model/Crew/` | Z-lístky (free passes of the crew): `CrewMembers` (one person = one ticket with a free-text role; add, bulk import, edit, delete, pick-up), `CrewSchedule` (crew timetable from the setting `crew_schedule`), `CrewError`. |
| `app/Model/Ticket/` | `Tickets` (one ticket per seat and standing ticket, issued on confirmation, and one per crew member; random 6-hex `code`, secret `print_token`), `TicketCheck` (entrance: lookup by code or reservation number, use, use the whole reservation, undo, statistics), `TicketLinks` (check / print URLs, QR PNG and SVG), `UnpaidTickets` (tickets of unpaid reservations stay hidden; on the print page a logged-in administrator reveals one with a reason printed on it and logged). |
| `app/Presentation/` | Presenters + Latte templates, typed template classes (`*Template.php`), `@layout.latte`, `Accessory/` (`FormFactory`, `TemplateExtension`: filters `price`, `seatLabel`, function `asset`). |
| `app/Presentation/Front/` | Pages for visitors: Home (reservation), Reservation ("Moje rezervace", tickets once paid), Ticket (printable ticket), Hall (`/plan-salu.svg`), Api (JSON for the seat picker), Access (tester and VIP links), `VisitorGate`. |
| `app/Presentation/Admin/` | Administration: Setup (first-run wizard), Sign, Dashboard (the three kinds of tickets as stacked bars in one green ramp with entrance counts - `Accessory/StackedBar`, to-do list, folded full lists of seat / standing / Z-lístky tickets and a folded table of all seats), Reservation, Customer (reservations grouped by e-mail), Payment (filters, resolving in the row), Site (stage of the site, secret links, pages for visitors who cannot buy), Log, Search (header search box), Settings (event, prices, payments), User (administrators), Export (CSV), Account (own password), Check (entrance check app for phones, own full-screen page + JSON), Crew (Z-lístky: list, add / bulk import, edit, pick-up, print and e-mail a selection; `/admin/z-listky`), Danger (Nebezpečná zóna: hall plan upload, deleting test reservations). Shared parts in `Accessory/`. |
| `app/Presentation/Cron/` | `GET /cron/payments?key=` for a scheduler; 404 while `cron.key` is empty. |
| `app/Presentation/Dev/` | `/dev/status`, `/dev/bank` (debug mode only, left out of the release). |
| `app/Presentation/Error/` | Error pages. |
| `assets/ts/` | TypeScript: `front.tsx` entry, `seat-picker/` (types, pure logic, API client, signals store, components; the map inlines the SVG plan: `HallMap` sleeps until used, zooms to a table on a tap, `viewbox.ts`; `OrderButton` fixed top right while the form is out of sight, `Tickets` (seat and standing ticket) above); `check.tsx` entry, `check/` (entrance check: QR scanner via `qr-scanner`, API client, result card); `admin.ts` entry, `admin/` (reservation search of unmatched payments); `front-demo.tsx` entry of the static demo with `seat-picker/demo-api.ts` (the API rules in the browser). |
| `assets/scss/` | SCSS partials; `front.scss`, `admin.scss` and `check.scss` are the entries. |
| `bin/export-demo.php` | Renders the static demo pages (`dev\demo.cmd`): reservation page with the demo API, sample "Moje rezervace", plan SVG; everything in a rolled-back transaction. |
| `bin/create-admin.php` | Creates an organizer account with a random password; `--sql` prints an INSERT for phpMyAdmin (production). |
| `config/` | `common.neon`, `services.neon` (interfaces -> implementations), `local.neon` (git-ignored, from `local.neon.example`), `test.neon` (PHPUnit database). |
| `database/` | `schema.sql` (hall plan in `hall_plan`, layout in `hall_tables` / `seats`, the sale in `seat_bookings`, tickets in `tickets`) + `defaults.sql` (also the production installation), `demo-hall.svg` (local demo plan), `seed.sql` (local test data only). |
| `tests/php/` | PHPUnit against `ples_test`; mirrors `app/`; helpers in `Support/`, recorded responses in `fixtures/`. |
| `tests/ts/` | `bun test` of the seat picker. |
| `www/` | Document root: `index.php`, `.htaccess`, `img/` (incl. `skaut-logo.svg` from skaut.cz), `fonts/skaut/` (scout fonts SKAUT Bold and TheMix from cdn.skauting.cz, free for scout websites; `assets/scss/_fonts.scss`), `kontrola.webmanifest` (installable check app), `build/` (generated, git-ignored). |
| `var/` | Nette cache (`var/temp`) and logs (`var/log`) (git-ignored). |
