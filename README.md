# PlesJunakSumperk

Registration and ticket payment site for the Šumperk scout ball.

## Quick start (Windows)

1. `setup.cmd` - one-time download of portable PHP, MariaDB and Adminer (about 600 MB, nothing is installed system-wide).
2. `start.cmd` - starts everything and opens http://127.0.0.1:8000/
3. `stop.cmd` - stops everything.

Reset the database with `init-db.cmd` (or `init-db.cmd -Import dump.sql`).
To remove the environment completely, delete `.tools/` and `.devdata/`.

See [AGENTS.md](AGENTS.md) for details and conventions, and
[original/OBNOVA.md](original/OBNOVA.md) for notes on the recovered old site.
