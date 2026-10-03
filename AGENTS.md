# AGENTS.md

Guidance for AI coding agents (and humans) working in this repository.

## Project

New registration and ticket payment site for the Šumperk scout ball
(replacing https://ples.junak-sumperk.cz/). Production runs on the Junák
**Lebeda** hosting (server `lebeda.skaut.cz`): PHP + MySQL, managed via
lebedahosting.cz (skautIS login), support at podpora@skaut.cz.

## Language rules

- **All source code is in English**: identifiers, comments, log and console
  messages, SQL comments, script help text, config keys.
- Text shown to end users of the site (visitors buying tickets) is in Czech.
- Keep `.ps1` files ASCII-only. Windows PowerShell 5.1 reads BOM-less files as
  ANSI, so any non-ASCII character would be garbled.
- `original/` is the recovered legacy site and is kept as-is (Czech, untouched).

## Layout

| Path | Purpose |
|---|---|
| `public/` | Web root (document root on the server): `index.php` (reservation page), `api.php` (JSON API), `done.php` (confirmation), `status.php` (diagnostics, debug only), `assets/`. |
| `src/` | PHP code outside the web root: `bootstrap.php` (config, DB, session, helpers), `ReservationService.php` (all reservation rules), `Mailer.php` + `emails.php` (SMTP sending, e-mail templates), `layout.php` (page frame). |
| `src/vendor/phpmailer/` | Vendored PHPMailer 7.1.1 (committed, no Composer needed on the hosting). |
| `docs/legacy-backend.md` | Reconstruction of the original PHP backend and how the new code maps to it. |
| `docs/plan.md` | Approved implementation plan (Nette, admin, Fio payments, Bun/Sass/TS 7 build). Follow it phase by phase. |
| `config.example.php` | Config template; copied to `config.local.php` (git-ignored). |
| `dev/` | Local development environment scripts. |
| `dev/db/schema.sql`, `dev/db/seed.sql` | Database schema and local test data. |
| `original/` | Recovered files of the old site + `OBNOVA.md` (reverse-engineered API notes). |
| `.tools/` | Portable PHP, MariaDB, Mailpit, Adminer (git-ignored, created by setup). |
| `.devdata/` | Local DB data, captured e-mails, logs, PID files, sessions, temp files (git-ignored). |

## Local environment (Windows, nothing installed system-wide)

| Command | What it does |
|---|---|
| `setup.cmd` | Downloads PHP 8.4, MariaDB 11.8, Mailpit 1.31 and Adminer (SHA256-verified), generates `php.ini`, initializes the DB. Idempotent; never deletes existing data. `-Force` re-extracts tools. |
| `start.cmd` | Starts MariaDB (127.0.0.1:3307), Mailpit (SMTP 127.0.0.1:1025, web 127.0.0.1:8025) and the PHP built-in server (127.0.0.1:8000) in the background. `-NoBrowser` skips opening the browser. |
| `stop.cmd` | Stops PHP and Mailpit and shuts MariaDB down cleanly. |
| `init-db.cmd` | Drops and recreates database `ples` from `dev/db/*.sql`. `-Import dump.sql` loads a dump instead, `-NoSeed` skips test data, `-Clean` wipes the whole data directory. |

URLs while running: site `/`, Adminer `/adminer` (user `ples`, password `ples`,
server `127.0.0.1:3307`), recovered old site `/original/`, diagnostics `/status.php`,
captured e-mails http://127.0.0.1:8025/.

The environment is fully portable: everything lives in `.tools/` and `.devdata/`,
including temp files (`.devdata/tmp` via `TEMP`/`TMP`, `sys_temp_dir`, MariaDB `tmpdir`).
All listeners bind to 127.0.0.1 only. Local PHP `mail()` is also routed to Mailpit.

All scripts are safe to run repeatedly. Tool versions and checksums live in
`dev/_common.ps1`; to upgrade, change the version and SHA256 together.

## Conventions and gotchas

- Code must work on both local MariaDB 11.8 and production MySQL 8; avoid
  vendor-specific SQL.
- Database access via PDO with prepared statements; charset `utf8mb4`.
- Reservation rules (limits, hold expiry, capacity) live only in `ReservationService`;
  the browser just renders server state. Mutations go through a transaction that
  locks the draft reservation row; seat holds use atomic `UPDATE ... WHERE state = 'free'`.
- User-facing errors are thrown as `ReservationError` with a Czech message.
- E-mails are sent after the reservation is committed; a failed send is logged and
  stored in `reservations.email_error`, it never rolls back the reservation.
- POST requests to `api.php` require the `X-CSRF-Token` header.
- Event info, prices and limits are rows in the `settings` table, not code.
- Database schema changes go to `dev/db/schema.sql` and must stay re-runnable
  through `init-db.cmd`.
- In PowerShell 5.1, native program arguments lose embedded double quotes, and
  stderr output from native programs can abort a script; use `Invoke-Native`
  from `dev/_common.ps1`.
- Never commit `config.local.php`, `.tools/` or `.devdata/`.
