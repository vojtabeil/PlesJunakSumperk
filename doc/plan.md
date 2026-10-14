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
5. Real hall layout of the venue (tables, seats) and real prices / capacities.
6. Should unpaid reservations expire automatically after N days (releasing seats)? If yes, how many days
   and should a reminder e-mail be sent first? (Not in the plan yet.)

## 5. Backlog: legacy features not yet in the new site

Features of the old site (`resource/original-web/`, see `doc/legacy/original-web.md`) that the new site does not have yet. Data import from the
legacy database is out of scope. Each item names the legacy code it replaces.

1. **Entrance check.** QR scanner at the door, record the use of a seat or ticket, detect a second entry.
   Legacy: `resource/original-web/ftp/www/check/tets.php` (jsQR camera scanner), `place.usedTime`.
2. **Alcohol wristbands.** Count issued and remaining wristbands per reservation.
   Legacy: `ticket.alcohol_strip_count`.
3. **Discounted tickets.** A product type with its own price.
   Legacy: `loweredCount` / `loweredPrice` in `Model\Tickets::orderTickets`.
4. **Z-tickets** (guests and sponsors) with a record of pick-up.
   Legacy: table `zTicket`, `Admin\TicketsPresenter::handleToggleZTaken`.
5. **E-mail templates for the 2027 ball.** Legacy templates in `resource/original-web/ftp/include/app/email/`
   still carry the 2023 date, the old account and the old signatory.
