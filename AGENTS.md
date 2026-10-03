# AGENTS.md

Guidance for AI coding agents (and humans) working in this repository.

## Project

New registration and ticket payment site for the Šumperk scout ball
(replacing https://ples.junak-sumperk.cz/). Production runs on the Junák
**Lebeda** hosting (server `lebeda.skaut.cz`): PHP + MySQL, managed via
lebedahosting.cz (skautIS login), support at podpora@skaut.cz.

Stack: **Nette 3.3** (PHP 8.3+), Latte, plain PDO, MariaDB locally / MySQL 8 in production,
**Preact** island for the seat picker, TypeScript (checked by TypeScript 7), SCSS (Dart Sass),
bundled by **Bun**. The implementation plan is `docs/plan.md` - follow it phase by phase.

## Language rules

- **All source code is in English**: identifiers, comments, log and console
  messages, SQL comments, script help text, config keys.
- Text shown to end users of the site (visitors, organizers) and e-mails are in Czech.
- Keep `.ps1` files ASCII-only. Windows PowerShell 5.1 reads BOM-less files as
  ANSI, so any non-ASCII character would be garbled.
- `original/` is the recovered legacy site and is kept as-is (Czech, untouched).

## Layout

| Path | Purpose |
|---|---|
| `app/Bootstrap.php` | Nette configurator (debug mode only on localhost, Tracy logs in `var/log`). |
| `app/Core/RouterFactory.php` | Routes: `/` reservation, `/hotovo/<id>` confirmation, `/api/<op>` JSON, `/tester/<token>` and `/vip/<token>` secret links, `/admin/<presenter>/<action>[/<id>]`, `/dev/status`. |
| `app/Model/Admin/` | Organizer accounts (`AdminUsers`), login with lockout + per-request DB check (`Authenticator` as `IdentityHandler`), `SetupConfig`. |
| `app/Core/SetupGuard.php` | No administrator = site not configured: every presenter except the setup wizard is disabled (pages redirect, API 503). |
| `app/Model/Payment/` | `BankTransactionSource` (interface; `Mock/MockBankSource` locally, `Fio/FioApiSource` in production, chosen by `bank.driver`; both `RewindableSource`), `PaymentImporter` (fetch -> store -> match -> e-mail; manual button or cron), `PaymentMatcher` (partial payments add up), `VariableSymbol` (VS = prefix + id, e.g. 20260003), `QrPayment` (IBAN + SPAYD + PNG), `PaymentRepository`. |
| `app/Model/Http/` | `HttpClient` interface + `CurlHttpClient` (tests use `tests/php/FakeHttpClient`; no test calls a real service). |
| `app/Presentation/Front/Cron/` | `GET /cron/payments?key=` for a scheduler; 404 while `cron.key` is empty. |
| `app/Model/Log/` | Event log of everything that happens (`EventLog::record`, actor from `Actor`: customer/admin/cron/system), `EventTypes` (Czech labels, categories), `EventLogRepository` (filters by date, seat, type, actor, reservation), `EventFormatter`. |
| `app/Model/Clock/` | `Clock` interface (`SystemClock`; `tests/php/FrozenClock` in tests). |
| `app/Presentation/Admin/` | Administration: Setup (first-run wizard), Sign, Dashboard (seat pie chart, to-do list, table of all seats), Reservation, Customer (reservations grouped by e-mail), Payment (filters, resolving in the row), Site (stage of the site, secret links, pages for visitors who cannot buy), Log, Search (header search box), Settings (event, prices, payments), User (administrators), Export (CSV), Account (own password). |
| `bin/create-admin.php` | Creates an organizer account with a random password; `--sql` prints an INSERT for phpMyAdmin (production). |
| `app/Model/` | Business logic: `Reservation/` (ReservationService = all reservation rules, ReservationAdmin, SeatOverview, Settings, SiteMode, SiteAccess, ReservationSession), `Mail/` (MailSender interface, SMTP implementation, ReservationMailer + Latte e-mail templates), `Database/` (PDO factory). |
| `app/Presentation/` | Presenters + Latte templates (`Front/`, `Error/`, `Dev/`), typed template classes (`*Template.php`), `@layout.latte`, `Accessory/TemplateExtension.php` (filters `price`, `seatLabel`, function `asset`). |
| `config/` | `common.neon`, `services.neon` (interfaces -> implementations), `local.neon` (git-ignored, from `local.neon.example`), `test.neon` (PHPUnit database). |
| `assets/ts/` | TypeScript: `front.tsx` entry, `seat-picker/` (types, pure logic, API client, signals store, components). |
| `assets/scss/` | SCSS partials; `front.scss` is the entry. |
| `www/` | Document root: `index.php`, `.htaccess`, `img/`, `build/` (generated, git-ignored). |
| `tests/php/`, `tests/ts/` | PHPUnit (against `ples_test`) and `bun test`. |
| `docs/` | `plan.md` (implementation plan), `legacy-backend.md` (reconstruction of the old site). |
| `dev/` | Local environment scripts; `dev/db/schema.sql` = database schema, `dev/db/seed.sql` = local test data; `dev/env.cmd` = tool environment. |
| `original/` | Recovered files of the old site + `OBNOVA.md`. |
| `.tools/` | Portable PHP, MariaDB, Mailpit, Adminer, Composer, Bun, TypeScript 7, Dart Sass (git-ignored). |
| `.devdata/` | Local DB data, captured e-mails, logs, PID files, sessions, temp files, package caches (git-ignored). |
| `var/` | Nette cache (`var/temp`) and logs (`var/log`) (git-ignored). |

## Local environment (Windows, nothing installed system-wide)

| Command | What it does |
|---|---|
| `setup.cmd` | Downloads all tools (SHA256-verified), generates `php.ini`, runs `composer install` and `bun install`, creates `config/local.neon`, creates `ples` (schema + seed data) and `ples_test` if missing. Idempotent; never deletes data. `-Force` re-extracts tools. |
| `start.cmd` | Starts MariaDB (127.0.0.1:3307), Mailpit (SMTP 1025, web 8025), `bun build --watch` and `sass --watch`, and the PHP server (127.0.0.1:8000). `-NoBrowser` skips opening the browser. |
| `stop.cmd` | Stops everything (MariaDB is shut down cleanly). |
| `build.cmd` | Type check + bundle + SCSS into `www/build`. |
| `check.cmd` | `tsc`, `bun test`, PHPUnit, PHPStan (level 6). Run before every commit. |
| `release.cmd` | Release package `dist/ples-<version>/` (+ ZIP): `web/` to upload (no dev tools, tests, sources, maps, dev dependencies or local config) and `install/` (SQL + `docs/deploy.md`). Verifies the package locally in production mode first. |
| `init-db.cmd` | Recreates `ples` (schema + seed). `-Test` recreates `ples_test`, `-NoSeed`, `-Import dump.sql`, `-Clean` (wipe data dir). |
| `php.cmd`, `composer.cmd`, `bun.cmd`, `tsc.cmd`, `sass.cmd` | Run the portable tools with the project environment. |

URLs while running: site `/`, administration `/admin` (local seed account `admin` / `admin`),
mock bank `/dev/bank` (create fake payments, then "Načíst platby z banky" in admin -> Platby),
diagnostics `/dev/status`, Adminer `/adminer`
(user `ples`, password `ples`, server `127.0.0.1:3307`), recovered old site `/original/`,
captured e-mails http://127.0.0.1:8025/.

The environment is fully portable: everything lives in `.tools/` and `.devdata/`,
including temp files (`.devdata/tmp` via `TEMP`/`TMP`, `sys_temp_dir`, MariaDB `tmpdir`)
and package caches (`COMPOSER_HOME`, `COMPOSER_CACHE_DIR`, `BUN_INSTALL*`; `DO_NOT_TRACK=1`).
New tools must follow the same rule: download + SHA256 in `dev/_common.ps1`, install in
`dev/setup.ps1`, caches redirected in `dev/env.cmd`, wrapper in the repo root.
All listeners bind to 127.0.0.1 only. Local PHP `mail()` is also routed to Mailpit.

## Conventions and gotchas

- PHP in `app/` and `tests/php/` follows the Nette coding style (tabs, two blank lines
  between methods). TypeScript/SCSS use 2 spaces.
- **IoC**: depend on interfaces (`MailSender`, Tracy `ILogger`, later `BankTransactionSource`),
  bind implementations in `config/services.neon`; no static calls like `Debugger::log()` in services.
- Templates are typed: every presenter has a `*Template` class and `{templateType}` in its Latte file.
  Standard variables (`$basePath`, ...) must be declared on the template class to be filled.
- Code must work on both MariaDB 11.8 and MySQL 8; avoid vendor-specific SQL.
- Database access via PDO with prepared statements; charset `utf8mb4`.
- Reservation rules live only in `ReservationService`; the browser renders server state.
  Mutations lock the draft reservation row in a transaction; seat holds use atomic
  `UPDATE ... WHERE state = 'free'`. Draft ownership is a random key from `ReservationSession`.
- One e-mail may have several reservations. The public API must never look up reservations by
  e-mail or answer differently depending on other people's data (no enumeration, no takeover);
  a draft belongs only to the browser that created it.
- User-facing errors are thrown as `ReservationError` with a Czech message.
- First run: with an empty `admin_users` the site is off and `/admin/setup` asks for the setup
  password (`setup.password`, default `skaut-sumperk`) and creates the first administrator.
  `init-db.cmd -NoAdmin` reproduces this locally. All administrators are equal; any of them can
  create, edit (incl. password) and delete any account except the last one.
- Every request re-checks the logged-in account in the DB (`Authenticator::wakeupIdentity`):
  a deleted account or a changed password (`session_version`) logs the user out everywhere.
  After changing one's own account, re-login with `Authenticator::identity()`.
- Deny by default: `tests/php/Presentation/PresenterAccessTest` fails for any presenter that is
  reachable without login and not on its allowlist. Public by design: Front (Home, Done, Api, Access),
  Error, Admin Sign/Setup and `Dev:*` (debug mode only; must be excluded from the release build).
- Admin: every presenter extends `Admin\BasePresenter` (login required unless `isPublic()`).
  State changes go only through POST forms from `FormFactory` (CSRF token) - never GET links -
  and each one is written to the event log. Presenter helper methods must not start with
  `action`/`render`/`handle`/`createComponent` (Nette treats them as lifecycle methods).
- `Form::URL` accepts "foo" (prepends http://); require an explicit scheme with a Pattern rule.
- Payments: amounts are hellers (`int`) in PHP and `DECIMAL(12,2)` CZK in the DB. Bank movements
  are delivered only once, so `PaymentImporter` stores them (unique per source + external id)
  before matching. Reservation statuses: draft -> confirmed -> partially_paid -> paid, or cancelled;
  "finished" = `ReservationService::FinishedStatuses`.
- Dev-only pages (`/dev/*`) check `Debugger::$productionMode`; `/dev/bank` also requires the mock bank.
  The release build leaves `app/Presentation/Dev` out and always runs in production mode (file `VERSION`).
- The bank account is the scout group's main account: payments whose VS lacks the ball prefix
  are `foreign` (not a problem, never matched). Keep the VS prefix stable during a sale.
- Payments may be imported only manually (no cron on the hosting): keep the dashboard button and
  the "last import" information working.
- Secrets (Fio token, cron key, setup password, tester and VIP tokens) never appear in exceptions, logs or the event log.
- Event log: every reservation, payment, e-mail and admin action is logged **in the model**
  (`EventLog::record`), never in presenters. Entry points set the actor (`Actor::asAdmin/asCustomer/asCron`).
  Seat clicks and holds are not logged (only reservation-level events). A new action needs an entry
  in `EventTypes::Types`.
- Stages of the site (`SiteMode`, setting `site_mode`, switched by hand in admin -> Stav webu):
  testing (default) -> vip -> public -> closed -> after. `Front\VisitorGate::canBuy()` decides who may
  buy: testing = tester cookie (`/tester/<token>`, token in the DB), vip = VIP cookie (`/vip/<token>`,
  token `site.vipToken` in the configuration, min. 16 characters), public = anybody, closed/after =
  nobody; logged-in administrators in every selling stage. Others see the stage's page
  (`page_<stage>` settings, HTML written by organizers and printed unescaped - trusted content).
  The API answers 503; `ReservationService` also refuses changes when the stage does not sell.
  Reservations store their `channel` (test/vip/public); test ones are left out of the guest list
  and can be deleted. The Done page (payment details) works in every stage.
- Database data files: `dev/db/schema.sql` + `defaults.sql` + `hall.sql` are also the production
  installation; `seed.sql` is local test data only.
- Do not depend on `bcmath` (may be missing on the hosting).
- E-mails are sent after the reservation is committed; a failure is logged and stored in
  `reservations.email_error`, it never rolls back the reservation.
- POST requests to `/api/*` require the `X-CSRF-Token` header (token printed into the page).
- JSON printed into a `<script>` must use `Json::encode(..., htmlSafe: true)`.
- Event info, prices and limits are rows in the `settings` table, not code.
- Greenfield project: the database schema is `dev/db/schema.sql`, edited directly (no migrations).
  After a change run `init-db.cmd` and `init-db.cmd -Test`.
- PHPUnit tests that touch the DB extend `App\Tests\DatabaseTestCase` (fresh fixture per test,
  fresh services so cached settings never leak).
- In PowerShell 5.1, native program arguments lose embedded double quotes, and stderr output
  from native programs can abort a script; use `Invoke-Native` from `dev/_common.ps1`.
- Never commit `config/local.neon`, `.tools/`, `.devdata/`, `var/`, `vendor/`, `node_modules/`, `www/build/`.
