# Implementation plan: Nette, admin, payments, frontend build

Status: phases 1-4 (tooling, Nette skeleton, admin, payments with the mock bank) done; next is phase 5 (Fio API, deployment). Decisions were made in discussion with the
project owner on 2026-10-03; this file is the reference for the next steps.

## 1. Decisions

| Area | Decision |
|---|---|
| PHP framework | **Nette 3.3** (DI container, Latte, Forms, Security, Tracy). Plain PDO stays for data access (no ORM). |
| IoC | Services depend on interfaces; the implementation is chosen in config (`config/local.neon`). |
| Bank | **Fio banka**, account of the scout group `2501895120/2010`; payments are made by **QR Platba (SPAYD)** with VS = reservation id. Read-only Fio API token ("Sledování účtu"). |
| Bank in development | `MockBankSource` + a dev page (`/dev/bank`) to create fake incoming payments. |
| Admin login | Own accounts (`admin_users`, `password_hash`). skautIS login is a possible later addition. |
| Frontend | **Preact + @preact/signals** only for the seat picker "island"; everything else is server-rendered Latte + Nette Forms, Naja where AJAX helps in admin. No SPA. |
| Build | **Bun** (bundler for TS/TSX, `bun test`), **Dart Sass** standalone (SCSS), **TypeScript 7** standalone (type checking only). No Node.js. |
| Portability | Every tool in `.tools/`, every cache/temp in `.devdata/`; nothing is installed system-wide. |
| Database changes | Greenfield (decided 2026-10-03): no migrations, `dev/db/schema.sql` is edited directly and `init-db` recreates the databases. |

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

## 2. Target structure

```
app/
  Bootstrap.php
  Core/RouterFactory.php
  Model/
    Clock/            Clock (interface), SystemClock, FrozenClock (tests)
    Reservation/      ReservationService, ReservationError, HallLayout, Settings
    Payment/          BankTransactionSource (interface), BankTransaction (value object),
                      PaymentMatcher, PaymentImporter, QrPayment (SPAYD + QR image)
    Payment/Fio/      FioApiSource, FioResponseParser
    Payment/Mock/     MockBankSource, MockBankRepository
    Mail/             MailSender (interface), SmtpMailSender, MailTemplates (Latte)
    Admin/            AdminUserRepository, Authenticator, AuditLog
  Presentation/
    Front/            Home (reservation page), Done, Api (JSON for the seat picker)
    Admin/            Sign, Dashboard, Reservation, Payment, Settings, Export
    Dev/              Bank (mock bank page, only when mock is enabled)
    Error/            Error4xx, Error
  Mail/templates/     *.latte for e-mails
config/
  common.neon         parameters, extensions, session, Latte
  services.neon       service definitions (interfaces -> implementations)
  local.neon          git-ignored: DB, SMTP, bank driver (mock|fio), Fio token, debug
  local.neon.example
assets/
  ts/                 front.tsx (seat picker island), admin.ts
  ts/seat-picker/     components, state (signals), api client, types
  scss/               front.scss, admin.scss, _tokens.scss, components/
www/                  document root: index.php, .htaccess, build/ (generated), img/
tests/
  php/                PHPUnit: ReservationService, PaymentMatcher, QrPayment, FioResponseParser
  ts/                 bun test: seat picker state and rendering
dev/                  environment scripts (existing) + build/deploy scripts
```

`public/` and `src/` from the current prototype are migrated into this structure and removed.

## 3. Phases

Each phase ends with a working site, passing checks and a commit.

### Phase 1 - Tooling

1. Extend `dev/_common.ps1` versions table: Composer, Bun, TypeScript 7, Dart Sass (URLs + SHA256).
2. `setup.ps1`: install them into `.tools/composer`, `.tools/bun`, `.tools/typescript`, `.tools/sass`.
3. Environment for every script (in `_common.ps1`):
   - `COMPOSER_HOME`, `COMPOSER_CACHE_DIR` -> `.devdata/composer`
   - `BUN_INSTALL_CACHE_DIR` -> `.devdata/bun-cache`
   - `BUN_RUNTIME_TRANSPILER_CACHE_PATH` -> `.devdata/bun-transpiler`
   - `DO_NOT_TRACK=1`
   - `TEMP`/`TMP` -> `.devdata/tmp` (already done)
4. Wrapper commands so nothing needs to be on `PATH`: `composer.cmd`, `bun.cmd`, `tsc.cmd`, `sass.cmd`
   in the repo root (each sets the environment and calls the portable binary).
5. `setup.cmd` runs `composer install` and `bun install` when lock files exist.

Done when: `setup.cmd` on a clean checkout installs everything, nothing is written outside
the repository (check `%APPDATA%`, `%LOCALAPPDATA%`, `%USERPROFILE%\.bun`), all wrappers work.

### Phase 2 - Nette skeleton and migration of the current site

Notes from the implementation: the `Clock` interface is postponed to phase 4 (no PHP-side time logic
exists yet; hold expiry uses DB `NOW()`); PHPMailer moved from a vendored copy to Composer.

1. `composer.json` with pinned packages (section 1), `composer.lock` committed.
2. `www/index.php` -> `App\Bootstrap::boot()`; Tracy in debug mode only.
3. DI: register `PDO`, `Clock`, `MailSender`, `BankTransactionSource` (mock for now), `ReservationService`.
4. Migrations:
   - `migrations/001_initial.sql` = current `dev/db/schema.sql`;
   - runner: `dev/migrate.ps1` locally and an admin-only "run migrations" action (or CLI
     `php bin/console migrations:continue`) for Lebeda;
   - `init-db` = drop DB + all migrations + `dev/db/seed.sql`.
5. Port the front end:
   - Latte layout from `src/layout.php`, reservation page, done page;
   - `Api` presenter replaces `public/api.php` with the same JSON contract and CSRF header;
   - `ReservationService` moves to `App\Model\Reservation` unchanged in behaviour,
     with `Clock` injected where PHP time is used (DB `NOW()` stays the source of truth for holds).
6. Frontend build:
   - `assets/scss/front.scss` from `public/assets/app.css` (tokens -> `_tokens.scss`);
   - seat picker rewritten as a Preact island (`assets/ts/seat-picker/`), mounted into
     `<div id="seat-picker">` with initial data (layout, prices, limits) in a JSON `<script>`;
   - the static hall map SVG stays server-rendered so the page is readable without JS.
7. Build scripts:
   - `build.cmd`: `tsc --noEmit` -> `bun build assets/ts/front.tsx assets/ts/admin.ts --outdir www/build --minify --sourcemap=linked`
     -> `sass assets/scss:www/build --style=compressed`;
   - `start.cmd` additionally runs `bun build --watch` and `sass --watch` in the background (PIDs in `.devdata/run`),
     `stop.cmd` stops them;
   - assets referenced with `?v=<filemtime>`.
8. Router: `/` reservation, `/hotovo/<id>` done, `/api/<action>` JSON, `/admin/...`, `/dev/...`.

Done when: the reservation flow behaves exactly as today (same API tests pass against the
new code), e-mail still lands in Mailpit, `tsc --noEmit` and PHPStan (level 6+) are clean.

### Phase 3 - Admin

1. Schema: `admin_users` (id, login, password_hash, name, created_at, last_login_at),
   `audit_log` (id, admin_user_id, action, entity, entity_id, details JSON, created_at),
   `reservations.note`.
2. `dev/create-admin.ps1` (and a CLI command) to create the first admin; seed creates `admin/admin` locally only.
3. Sign in: Nette Security, `password_verify`, `session_regenerate_id` on login,
   throttling (5 failed attempts per login / 15 min), logout, all admin presenters require login.
4. Pages:
   - **Dashboard**: seats sold / held / free, standing tickets, confirmed vs paid counts, revenue, unmatched payments.
   - **Reservations**: list with filters (status, search by e-mail/name/VS), detail with seats,
     actions: mark paid (cash), cancel (releases seats), resend confirmation e-mail, edit note.
   - **Settings**: event info, prices, limits, sale open/closed, bank account, hold seconds.
   - **Export**: CSV of guests (name, e-mail, seats, standing, status, paid at) for the entrance.
5. Every state-changing admin action writes to `audit_log`.
6. Admin styling in `assets/scss/admin.scss`; Naja for inline actions where useful.

Done when: an organizer can run the whole sale from the admin without Adminer.

### Phase 4 - Payments (mock bank first)

1. Schema changes:
   - `bank_transactions` (id, source `fio|mock`, external_id UNIQUE, booked_on, amount, currency,
     variable_symbol, counter_account, counter_name, message, raw JSON, imported_at,
     reservation_id NULL, match_status `matched|underpaid|overpaid|unknown_vs|no_vs|ignored`);
   - `mock_bank_transactions` (same shape, plus `fetched` flag);
   - `reservations`: `paid_at`, `paid_amount`, status `partially_paid` added to the enum;
   - setting `bank_account` (`2501895120/2010`), `bank_last_fetch_at`.
2. `QrPayment`: builds the SPAYD string (`SPD*1.0*ACC:<IBAN>*AM:<amount>*CC:CZK*X-VS:<id>*MSG:<event>`),
   IBAN computed from the Czech account number, PNG via chillerlan/php-qrcode.
   Shown on the done page and embedded (CID attachment) in the confirmation e-mail;
   the e-mail text gets real payment instructions (account, amount, VS).
3. `BankTransactionSource::fetchNew(): BankTransaction[]`
   - `MockBankSource` returns unfetched rows of `mock_bank_transactions` and marks them fetched;
   - `FioApiSource` comes in phase 5.
4. `PaymentImporter`: fetch -> store every transaction first (idempotent by `external_id`)
   -> `PaymentMatcher` -> update reservations -> send "payment received" e-mails.
   Guarded against running more often than every 30 s (Fio limit).
5. `PaymentMatcher` rules (sum of all incoming payments with the same VS):
   - VS missing -> `no_vs`; VS not a confirmed reservation -> `unknown_vs`;
   - sum >= total -> reservation `paid` (`overpaid` flagged if greater);
   - 0 < sum < total -> `partially_paid` (`underpaid`);
   - outgoing payments are ignored.
6. Admin **Payments** page: list of imported transactions with match status, "Načíst platby" button,
   manual assignment of an unmatched transaction to a reservation, ignore action.
7. **Dev bank page** `/dev/bank` (registered only when `bank.driver: mock`):
   - list of confirmed reservations with buttons: pay exact amount, pay less, pay more, pay without VS, pay twice;
   - free form (amount, VS, counter account, message);
   - list of created mock transactions and their fetched state; "reset" button.
8. E-mail "Platba přijata" (and "Částečná platba" with the remaining amount).

Done when: a full cycle works locally: reserve -> QR in e-mail -> pay on `/dev/bank` ->
"Načíst platby" in admin -> reservation paid -> e-mail in Mailpit. PHPUnit covers all matcher rules.

### Phase 5 - Fio and deployment

1. `FioApiSource`: `GET https://fioapi.fio.cz/v1/rest/last/{token}/transactions.json`,
   `FioResponseParser` maps `column22` (ID pohybu) -> external_id, `column1` amount, `column5` VS,
   `column0` date, `column2`/`column3` counter account, `column10` name, `column16` message.
   Timeouts, HTTP 409 (rate limit) handling, token never logged.
   Tested against recorded sample responses (from the Fio manual), never the live API in tests.
2. Optional cron endpoint `/cron/payments?key=<secret>` (secret in `local.neon`) if Lebeda supports cron.
3. `build.cmd -Release` -> `dist/`: `composer install --no-dev --optimize-autoloader`, built assets,
   `app/`, `config/` (without `local.neon`), `www/`, `.htaccess` that denies everything
   outside `www/` (or maps the document root to `www/` if Lebeda allows it).
4. `docs/deploy.md`: first deployment on Lebeda (FTP upload, `local.neon`, import of `dev/db/schema.sql`,
   creating the admin account, Fio token, SMTP), and updates.
5. Production checklist: `debug: false`, HTTPS only (secure cookies), SMTP from a domain address,
   Fio token "Sledování účtu" with auto-renewal, backups.
   **Delete `var/temp/cache` after every upload**: in production mode Nette does not detect
   config/code changes and keeps using the old compiled DI container and templates.

## 4. Cross-cutting rules

### 4.1 Security
- CSRF: Nette Forms tokens for forms, `X-CSRF-Token` header for the JSON API.
- Output escaping by Latte only; no raw HTML from user input.
- Prepared statements everywhere.
- Admin session: `httponly`, `samesite=Lax`, `secure` on HTTPS, regenerate on login, idle timeout 2 h.
- Secrets (DB password, SMTP, Fio token, cron key) only in `config/local.neon`.
- `/dev/*` presenters are not registered unless `bank.driver: mock` **and** debug mode.

### 4.2 Database schema
- Greenfield project: `dev/db/schema.sql` is the single source of the schema and is edited directly.
  A migration runner (implemented in phase 2) was removed again on 2026-10-03; reconsider only
  once production holds data that must survive schema changes.

### 4.3 Testing and checks (`check.cmd`)
- `tsc --noEmit`, `bun test`, `vendor/bin/phpunit`, `vendor/bin/phpstan analyse`.
- PHP tests use `FrozenClock` and a dedicated test database `ples_test` (created by `init-db -Test`).
- Manual browser check of the reservation page, admin and `/dev/bank` at the end of each phase.

### 4.4 Language
- Source code, comments, logs, docs in `docs/`: English (see `AGENTS.md`).
- UI texts and e-mails for visitors and organizers: Czech.

## 5. Open questions (for the organizers / hosting)

1. PHP and MySQL version on Lebeda (affects Nette 3.3 vs 3.2, section 1).
2. Can the Lebeda document root point to `www/`? Is cron available? Is outbound HTTPS to `fioapi.fio.cz` allowed?
3. SMTP for production: Lebeda SMTP or Seznam (`ples@junak-sumperk.cz`)?
4. Who creates the Fio API token (treasurer) and who receives admin accounts?
5. Real hall layout of the venue (tables, seats) and real prices / capacities.
6. Should unpaid reservations expire automatically after N days (releasing seats)? If yes, how many days
   and should a reminder e-mail be sent first? (Not in the plan yet.)
