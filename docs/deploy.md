# Deployment to Lebeda hosting

How to put the site on the Junák **Lebeda** hosting (lebedahosting.cz, server `lebeda.skaut.cz`)
and how to update it. Questions about the hosting: podpora@skaut.cz.

## 0. Requirements (ask the hosting support if unsure)

- PHP **8.3 or newer** with `pdo_mysql`, `mbstring`, `intl`, `gd`, `curl`, `openssl`.
  (If only 8.2 is available, the PHP packages must be downgraded first, see `docs/plan.md`.)
- MySQL 8 (or MariaDB) database and phpMyAdmin/Adminer access.
- FTP (or SFTP) access to the web space.
- Outgoing HTTPS from PHP to `fioapi.fio.cz` (Fio payments).
- Ideally: the document root can point to the `www/` folder; a cron job (otherwise see step 7).

## 1. Build the package (on the developer machine)

```
setup.cmd        (once)
check.cmd        all checks must pass
release.cmd
```

The result is `dist/ples-<version>/` (and the same as a ZIP):

- `web/` - **upload this**. It contains no dev tools, tests or local configuration.
- `install/` - SQL files and this guide, for phpMyAdmin only (do not upload).

`release.cmd` runs the package locally in production mode before zipping it and fails if, for
example, `/dev/*` pages or the Tracy debugger would be reachable.

## 2. Database

1. In the hosting administration create a database and a user (or use the existing one).
2. In phpMyAdmin select the database and import, in this order:
   `install/schema.sql`, `install/defaults.sql`, `install/hall.sql`.
   (`hall.sql` is a demo layout of 12 tables; replace it once the real layout of the venue is known.)
3. **Never import `dev/db/seed.sql`** - it contains test data and a test administrator.

## 3. Upload

Upload the **contents** of `web/` into the web space via FTP. Then:

- Document root set to `www/` (preferred): nothing else to do.
- Document root is the uploaded folder itself: `web/.htaccess` forwards everything to `www/`
  and blocks `app/`, `config/`, `var/` and `vendor/`. Check that
  `https://<site>/config/common.neon` returns **403**.

The folders `var/temp` and `var/log` must be writable by PHP.

## 4. Configuration `config/local.neon`

Copy `config/local.neon.example` to `config/local.neon` on the server and fill in:

| Key | Value |
|---|---|
| `database` | host, port, name, user, password from the hosting administration |
| `mail` | SMTP of the sending mailbox, e.g. Seznam: `host: smtp.seznam.cz`, `port: 465`, `secure: ssl`, `user`/`password` of `ples@junak-sumperk.cz`; `from` must be that address |
| `bank` | `driver: fio`, `token: <Fio token>` (step 6); until then `driver: mock` is safe |
| `setup.password` | **a new secret** for the first-run wizard (default `skaut-sumperk`) |
| `cron.key` | a long random string, e.g. 40 letters and digits (step 7) |

The release always runs in production mode (it contains the file `VERSION`): no debugger,
no `/dev/*` pages, errors only in `var/log/`.

## 5. First run

1. Open `https://<site>/` - it redirects to `/admin/setup` because no administrator exists.
   Until then the whole site (reservations, payments) is off.
2. Enter the setup password from `config/local.neon` and create the first administrator.
3. Add the other organizers in **Administrátoři**.
4. In **Nastavení** fill in the event (date, venue, organizer), prices and limits, check the
   bank account, and open the sale.
5. Make a test reservation and check that the confirmation e-mail arrives (incl. the QR code).

## 6. Fio API token (payments)

Done by the person with access to the Fio account (treasurer):

1. Fio internet banking -> **Nastavení** -> **API** -> **Přidat token**.
2. Type **Sledování účtu** (read-only, the site cannot send money), validity 180 days with
   **automatic extension** on every login to internet banking.
3. Confirm with SMS/push (all signatories, if the account requires more).
4. Put the token into `config/local.neon` (`bank.driver: fio`, `bank.token`), delete
   `var/temp/cache`, wait 5 minutes (Fio activates new tokens with a delay).
5. Administration -> **Platby** -> **Načíst platby z banky**. The token is a secret: never send
   it by e-mail and never commit it.

The token gives read access to **all** movements of the account. Fio allows one request per
30 seconds per token; the site respects it.

## 7. Automatic import of payments

Call `https://<site>/cron/payments?key=<cron.key>` every 10 minutes:

- hosting cron (if available): `curl -fsS "https://<site>/cron/payments?key=..."`, or
- an external scheduler (e.g. cron-job.org) with that URL.

The answer is a short text summary; errors return HTTP 503 with the reason.

**Without a cron job** (not available on the hosting, or not set up yet) everything works the
same, only manually: an organizer presses **Načíst platby z banky** on the dashboard or on the
Platby page (at most once per 30 s). The dashboard shows when payments were imported last and
highlights the button when reservations are waiting and nobody imported payments for a day.
Leave `cron.key` empty then; `/cron/payments` does not exist.

If an import was interrupted (payments missing), use **Chybí platby? -> Stáhnout pohyby znovu**
on the Platby page (up to 90 days back); already stored payments are not duplicated.

## 8. Updating the site

1. `release.cmd`, upload `web/` over the old files **except** `config/local.neon` and `var/`.
2. **Delete the contents of `var/temp/`.** In production mode Nette does not detect changed code
   or configuration and would keep using the old compiled container and templates.
3. If `schema.sql` changed: there are no migrations yet (greenfield project), so apply the
   change by hand in phpMyAdmin, or ask the developer for an SQL script.

## 9. Backups and logs

- Database: hosting backups, plus an export from phpMyAdmin before every update and after the sale.
- Errors are logged to `var/log/` (download via FTP). Visitors see only a generic error page.
- Guest list for the entrance: administration -> **Export hostů (CSV)**.
