# PlesJunakSumperk

Registration and ticket payment site for the Šumperk scout ball.

## Quick start (Windows)

1. `setup.cmd` - one-time download of portable PHP, MariaDB, Mailpit, Adminer, Composer, Bun, TypeScript and Sass (about 750 MB, nothing is installed system-wide).
2. `start.cmd` - starts everything and opens http://127.0.0.1:8000/
3. `stop.cmd` - stops everything.

Outgoing e-mails are never delivered locally; they are captured by Mailpit at http://127.0.0.1:8025/.

Run tools through the wrappers in the repo root: `php.cmd`, `composer.cmd`, `bun.cmd`, `tsc.cmd`, `sass.cmd`.

Reset the database with `init-db.cmd` (or `init-db.cmd -Import dump.sql`).
To remove the environment completely, delete `.tools/` and `.devdata/`.

See [AGENTS.md](AGENTS.md) for details and conventions, and
[original/OBNOVA.md](original/OBNOVA.md) for notes on the recovered old site.
