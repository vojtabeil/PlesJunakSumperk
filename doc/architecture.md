# Architecture and layout

## Top level

| Path | Purpose |
|---|---|
| `src/` | The application: a self-contained Nette project with its own `composer.json`, `package.json`, `vendor/`, `node_modules/` and `var/`. The release package is built from it. |
| `doc/` | `plan.md` (decisions, open questions, backlog), `deploy.md` (deployment on Lebeda), this file, `legacy/` (notes on the old site). |
| `resource/original/` | Old site as served over HTTP (frontend only), local URL `/original/`. Notes: `doc/legacy/original.md`, `doc/legacy/original-backend.md`. |
| `resource/original-web/` | Complete FTP backup of the old site incl. PHP sources; credentials and dumps are git-ignored. Notes: `doc/legacy/original-web.md`. |
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
| `app/Core/RouterFactory.php` | Routes: `/` reservation, `/rezervace/<id>/<token>` "Moje rezervace" (secret link from the e-mails), `/api/<op>` JSON, `/cron/payments`, `/tester/<token>` and `/vip/<token>` secret links, `/admin/<presenter>/<action>[/<id>]`, `/dev/status`, `/dev/bank`. |
| `app/Core/SetupGuard.php` | No administrator = site not configured: every presenter except the setup wizard is disabled (pages redirect, API 503). |
| `app/Core/Clock/` | `Clock` interface (`SystemClock`; `tests/php/Support/FrozenClock` in tests). |
| `app/Core/Http/` | `HttpClient` interface + `CurlHttpClient` (tests use `tests/php/Support/FakeHttpClient`; no test calls a real service). |
| `app/Core/Database/` | PDO factory. |
| `app/Model/Reservation/` | `ReservationService` (all reservation rules), `ReservationAdmin`, `SeatOverview`, `ReservationSession`, `ReservationError`. |
| `app/Model/Settings/` | `Settings`: event info, prices and limits stored in the `settings` table. |
| `app/Model/Site/` | `SiteMode` (stages of the site), `SiteAccess` (tester and VIP tokens). |
| `app/Model/Payment/` | `PaymentImporter` (fetch -> store -> match -> e-mail; manual button or cron), `PaymentMatcher` (partial payments add up), `VariableSymbol` (VS = prefix + id, e.g. 20260003), `QrPayment` (IBAN + SPAYD + PNG), `PaymentRepository`. |
| `app/Model/Payment/Bank/` | `BankTransactionSource` (interface; `Mock/MockBankSource` locally, `Fio/FioApiSource` in production, chosen by `bank.driver` in `BankSourceFactory`; both `RewindableSource`), `BankTransaction`. |
| `app/Model/Mail/` | `MailSender` interface, SMTP implementation, `ReservationMailer` + Latte e-mail templates. |
| `app/Model/Log/` | Event log of everything that happens (`EventLog::record`, actor from `Actor`: customer/admin/cron/system), `EventTypes` (Czech labels, categories), `EventLogRepository` (filters by date, seat, type, actor, reservation), `EventFormatter`. |
| `app/Model/Admin/` | Organizer accounts (`AdminUsers`), login with lockout + per-request DB check (`Authenticator` as `IdentityHandler`), `SetupConfig`. |
| `app/Model/Export/` | `GuestListCsv`. |
| `app/Presentation/` | Presenters + Latte templates, typed template classes (`*Template.php`), `@layout.latte`, `Accessory/` (`FormFactory`, `TemplateExtension`: filters `price`, `seatLabel`, function `asset`). |
| `app/Presentation/Front/` | Pages for visitors: Home (reservation), Reservation ("Moje rezervace"), Api (JSON for the seat picker), Access (tester and VIP links), `VisitorGate`. |
| `app/Presentation/Admin/` | Administration: Setup (first-run wizard), Sign, Dashboard (seat pie chart, to-do list, table of all seats), Reservation, Customer (reservations grouped by e-mail), Payment (filters, resolving in the row), Site (stage of the site, secret links, pages for visitors who cannot buy), Log, Search (header search box), Settings (event, prices, payments), User (administrators), Export (CSV), Account (own password). Shared parts in `Accessory/`. |
| `app/Presentation/Cron/` | `GET /cron/payments?key=` for a scheduler; 404 while `cron.key` is empty. |
| `app/Presentation/Dev/` | `/dev/status`, `/dev/bank` (debug mode only, left out of the release). |
| `app/Presentation/Error/` | Error pages. |
| `assets/ts/` | TypeScript: `front.tsx` entry, `seat-picker/` (types, pure logic, API client, signals store, components). |
| `assets/scss/` | SCSS partials; `front.scss` and `admin.scss` are the entries. |
| `bin/create-admin.php` | Creates an organizer account with a random password; `--sql` prints an INSERT for phpMyAdmin (production). |
| `config/` | `common.neon`, `services.neon` (interfaces -> implementations), `local.neon` (git-ignored, from `local.neon.example`), `test.neon` (PHPUnit database). |
| `database/` | `schema.sql` (hall layout in `hall_tables` / `seats`, the sale in `seat_bookings`) + `defaults.sql` + `hall.sql` (also the production installation), `seed.sql` (local test data only). |
| `tests/php/` | PHPUnit against `ples_test`; mirrors `app/`; helpers in `Support/`, recorded responses in `fixtures/`. |
| `tests/ts/` | `bun test` of the seat picker. |
| `www/` | Document root: `index.php`, `.htaccess`, `img/`, `build/` (generated, git-ignored). |
| `var/` | Nette cache (`var/temp`) and logs (`var/log`) (git-ignored). |
