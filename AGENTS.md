# AGENTS.md

Guidance for AI coding agents (and humans) working in this repository.

## Project

New registration and ticket payment site for the Šumperk scout ball
(replacing https://ples.junak-sumperk.cz/). Production runs on the Junák
**Lebeda** hosting (server `lebeda.skaut.cz`): PHP + MySQL, managed via
lebedahosting.cz (skautIS login), support at podpora@skaut.cz.

Stack: **Nette 3.3** (PHP 8.3+), Latte, plain PDO, MariaDB locally / MySQL 8 in production,
**Preact** island for the seat picker, TypeScript (checked by TypeScript 7), SCSS (Dart Sass),
bundled by **Bun**. Decisions, open questions and the backlog are in `doc/plan.md`.

## Language rules

- **All source code is in English**: identifiers, comments, log and console
  messages, SQL comments, script help text, config keys.
- Text shown to end users of the site (visitors, organizers) and e-mails are in Czech.
- Keep `.ps1` files ASCII-only. Windows PowerShell 5.1 reads BOM-less files as
  ANSI, so any non-ASCII character would be garbled.
- `resource/original/` and `resource/original-web/` are two different copies of the legacy site,
  kept as-is (Czech, untouched); notes on them are in `doc/legacy/`.

## Layout

| Path | Purpose |
|---|---|
| `src/` | The application: a self-contained Nette project (`app/`, `assets/`, `bin/`, `config/`, `database/`, `tests/`, `www/`) with its own `composer.json`, `package.json`, `vendor/`, `node_modules/` and `var/`. |
| `doc/` | `plan.md` (decisions, open questions, backlog), `deploy.md`, `architecture.md` (**map of all folders and classes**), `legacy/`. |
| `resource/` | Not code: `original/` and `original-web/` (two copies of the old site), `design/` (CorelDRAW drawings). |
| `dev/` | Local environment scripts and wrappers of the portable tools. |

Read `doc/architecture.md` before larger changes and keep it up to date when adding folders or main classes.

## Local environment (Windows, nothing installed system-wide)

| Command | What it does |
|---|---|
| `setup.cmd` | Downloads all tools (SHA256-verified), generates `php.ini`, runs `composer install` and `bun install`, creates `src/config/local.neon`, creates `ples` (schema + seed data) and `ples_test` if missing. Idempotent; never deletes data. `-Force` re-extracts tools. |
| `start.cmd` | Starts MariaDB (127.0.0.1:3307), Mailpit (SMTP 1025, web 8025), `bun build --watch` and `sass --watch`, and the PHP server (127.0.0.1:8000). `-NoBrowser` skips opening the browser. |
| `stop.cmd` | Stops everything (MariaDB is shut down cleanly). |
| `dev\build.cmd` | Type check + bundle + SCSS into `src/www/build`. |
| `check.cmd` | `tsc`, `bun test`, PHPUnit, PHPStan (level 6). Run before every commit. |
| `dev\release.cmd` | Release package `dist/ples-<version>/` (+ ZIP): `web/` to upload (no dev tools, tests, sources, maps, dev dependencies or local config) and `install/` (SQL + `doc/deploy.md`). Verifies the package first like a new installation: fresh database `ples_release` from `install/*.sql`, production mode, checks of the first run, the testing stage and the public sale (MariaDB must run). |
| `dev\init-db.cmd` | Recreates `ples` (schema + seed). `-Test` recreates `ples_test`, `-NoSeed`, `-Import dump.sql`, `-Clean` (wipe data dir). |
| `dev\php.cmd`, `dev\composer.cmd`, `dev\bun.cmd`, `dev\tsc.cmd`, `dev\sass.cmd` | Run the portable tools with the project environment (run them from `src/`). |

URLs while running: site `/`, administration `/admin` (local seed account `admin` / `admin`),
mock bank `/dev/bank` (create fake payments, then "Načíst platby z banky" in admin -> Platby),
diagnostics `/dev/status`, Adminer `/adminer`
(user `ples`, password `ples`, server `127.0.0.1:3307`), recovered old site `/original/`,
captured e-mails http://127.0.0.1:8025/.

The environment is fully portable: everything lives in `.tools/` and `.devdata/`,
including temp files (`.devdata/tmp` via `TEMP`/`TMP`, `sys_temp_dir`, MariaDB `tmpdir`)
and package caches (`COMPOSER_HOME`, `COMPOSER_CACHE_DIR`, `BUN_INSTALL*`; `DO_NOT_TRACK=1`).
New tools must follow the same rule: download + SHA256 in `dev/_common.ps1`, install in
`dev/setup.ps1`, caches redirected in `dev/env.cmd`, wrapper in `dev/`.
All listeners bind to 127.0.0.1 only. Local PHP `mail()` is also routed to Mailpit.

## Conventions and gotchas

- PHP in `src/app/` and `src/tests/php/` follows the Nette coding style (tabs, two blank lines
  between methods). TypeScript/SCSS use 2 spaces.
- **IoC**: depend on interfaces (`MailSender`, Tracy `ILogger`, later `BankTransactionSource`),
  bind implementations in `src/config/services.neon`; no static calls like `Debugger::log()` in services.
- Templates are typed: every presenter has a `*Template` class and `{templateType}` in its Latte file.
  Standard variables (`$basePath`, ...) must be declared on the template class to be filled.
- Code must work on both MariaDB 11.8 and MySQL 8; avoid vendor-specific SQL.
- Database access via PDO with prepared statements; charset `utf8mb4`.
- Reservation rules live only in `ReservationService`; the browser renders server state.
  Mutations lock the draft reservation row in a transaction. The hall layout (`seats`, `hall_tables`) and
  the sale (`seat_bookings`) are separate tables: a booking row exists only for a held or reserved seat,
  holding a seat is an `INSERT` (the primary key makes it atomic). Draft ownership is a random key from `ReservationSession`.
- One e-mail may have several reservations. The public API must never look up reservations by
  e-mail or answer differently depending on other people's data (no enumeration, no takeover);
  a draft belongs only to the browser that created it.
- User-facing errors are thrown as `ReservationError` with a Czech message.
- First run: with an empty `admin_users` the site is off and `/admin/setup` asks for the setup
  password (`setup.password`, default `skaut-sumperk`) and creates the first administrator.
  `dev\init-db.cmd -NoAdmin` reproduces this locally. All administrators are equal; any of them can
  create, edit (incl. password) and delete any account except the last one.
- Every request re-checks the logged-in account in the DB (`Authenticator::wakeupIdentity`):
  a deleted account or a changed password (`session_version`) logs the user out everywhere.
  After changing one's own account, re-login with `Authenticator::identity()`.
- Deny by default: `src/tests/php/Presentation/PresenterAccessTest` fails for any presenter that is
  reachable without login and not on its allowlist. Public by design: Front (Home, Reservation, Api, Access), Cron,
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
  The release build leaves `src/app/Presentation/Dev` out and always runs in production mode (file `VERSION`).
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
  buy: testing = tester cookie (`/tester/<token>`), vip = VIP cookie (`/vip/<token>`); both tokens are
  settings (`tester_token`, `vip_token`) replaceable in the administration; public = anybody, closed/after =
  nobody; logged-in administrators in every selling stage. Others see the stage's page
  (`page_<stage>` settings, HTML written by organizers and printed unescaped - trusted content).
  The API answers 503; `ReservationService` also refuses changes when the stage does not sell.
  Reservations store their `channel` (test/vip/public); test ones are left out of the guest list
  and can be deleted. "Moje rezervace" (payment details) works in every stage.
- Database data files: `src/database/schema.sql` + `defaults.sql` are also the production installation
  (it starts without a hall plan); `demo-hall.svg` is the local demo plan imported by `init-db`
  (`bin/import-hall.php`), `seed.sql` local test data only.
- Hall plan: one SVG in `hall_plan` (only one version), uploaded in Nebezpečná zóna (`Admin:Danger`).
  Seats are shapes `id="seat-<number>"` inside groups `id="table-<number>"`; the database keeps no
  coordinates, only `svg_id`, and seat ids are the numbers from the drawing (label `<table>/<seat>`).
  `HallPlanParser` sanitizes the upload (allowlist of SVG elements, no scripts or external references);
  `HallSync::apply` cancels reservations of removed seats only when they match what the admin confirmed in
  the preview; removed seats and tables become `active = 0`. The seat picker loads `/plan-salu.svg` and
  styles the shapes (`.seat--free/mine/taken`, numbers `label-seat-N`). The map fills the screen (with the order button
  in its corner and the standing tickets below) and works on the SVG `viewBox` (`seat-picker/viewbox.ts`). It sleeps
  until used (wheel and swipes scroll the page); a click/tap (which also acts), two fingers, double click or the middle
  button wake it, then the wheel zooms and dragging pans; Escape, a click outside, scrolling away or "Objednat" put it to sleep. The reservation page is one view: in-page moves scroll (`scroll.ts`), never `#fragments`.
- Do not depend on `bcmath` (may be missing on the hosting).
- Every finished reservation has `access_token`: the page "Moje rezervace" (status, payment, QR)
  is reachable only with it and every e-mail links to it (`ReservationMailer::reservationLink`,
  absolute URL from the current request; tests set `HTTP_HOST`).
- Tickets (`tickets`): one per seat and standing ticket, created when a reservation is confirmed. The 6-hex
  `code` is in the QR code (link `/admin/kontrola/<code>`) and typed at the entrance; it never opens anything
  public. The public print page needs the secret `print_token` (`/vstupenka/<code>/<token>`). Visitors get the
  tickets once the reservation is paid (payment e-mail, or `sendTickets` after marking paid by hand).
  The entrance check (`TicketCheck`) lets unpaid reservations in with a warning; test tickets are valid only in
  the testing stage; any administrator may undo an entry. Its API (`Admin:Check`) answers 401 JSON without login.
- Payment is due `payment_days` (setting, default 2) after the reservation day: shown to the
  customer, put into the QR code (DT), "overdue" in the admin; reminders are sent by hand from the
  dashboard or the reservation detail (no cron), at most once per payment period.
- E-mails are sent after the reservation is committed; a failure is logged and stored in
  `reservations.email_error`, it never rolls back the reservation.
- POST requests to `/api/*` require the `X-CSRF-Token` header (token printed into the page).
- JSON printed into a `<script>` must use `Json::encode(..., htmlSafe: true)`.
- Event info, prices and limits are rows in the `settings` table, not code.
- Greenfield project: the database schema is `src/database/schema.sql`, edited directly (no migrations).
  After a change run `dev\init-db.cmd` and `dev\init-db.cmd -Test`.
- PHPUnit tests that touch the DB extend `App\Tests\Support\DatabaseTestCase` (fresh fixture per test,
  fresh services so cached settings never leak).
- In PowerShell 5.1, native program arguments lose embedded double quotes, and stderr output
  from native programs can abort a script; use `Invoke-Native` from `dev/_common.ps1`.
- The Composer class map is optimized: after moving or renaming classes run `..\dev\composer.cmd dump-autoload`
  in `src/` and delete `src/var/temp/cache`.
- Never commit `.tools/`, `.devdata/`, `dist/` or in `src/`: `config/local.neon`, `var/`, `vendor/`, `node_modules/`, `www/build/`.
