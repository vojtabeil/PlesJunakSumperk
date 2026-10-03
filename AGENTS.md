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
| `app/Core/RouterFactory.php` | Routes: `/` reservation, `/hotovo/<id>` confirmation, `/api/<op>` JSON, `/admin/<presenter>/<action>[/<id>]`, `/dev/status`. |
| `app/Model/Admin/` | Organizer accounts (`AdminUsers`), login with lockout (`Authenticator`), `AuditLog`. |
| `app/Presentation/Admin/` | Administration: Sign, Dashboard, Reservation (list, detail, actions), Settings, Export (CSV), Account (password). |
| `bin/create-admin.php` | Creates an organizer account with a random password; `--sql` prints an INSERT for phpMyAdmin (production). |
| `app/Model/` | Business logic: `Reservation/` (ReservationService = all reservation rules, Settings, ReservationSession), `Mail/` (MailSender interface, SMTP implementation, ReservationMailer + Latte e-mail templates), `Database/` (PDO factory). |
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
| `init-db.cmd` | Recreates `ples` (schema + seed). `-Test` recreates `ples_test`, `-NoSeed`, `-Import dump.sql`, `-Clean` (wipe data dir). |
| `php.cmd`, `composer.cmd`, `bun.cmd`, `tsc.cmd`, `sass.cmd` | Run the portable tools with the project environment. |

URLs while running: site `/`, administration `/admin` (local seed account `admin` / `admin`),
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
- User-facing errors are thrown as `ReservationError` with a Czech message.
- Admin: every presenter extends `Admin\BasePresenter` (login required unless `isPublic()`).
  State changes go only through POST forms from `FormFactory` (CSRF token) - never GET links -
  and each one is written to `AuditLog`. Presenter helper methods must not start with
  `action`/`render`/`handle`/`createComponent` (Nette treats them as lifecycle methods).
- `Form::URL` accepts "foo" (prepends http://); require an explicit scheme with a Pattern rule.
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
