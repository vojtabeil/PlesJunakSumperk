# PlesJunakSumperk

Registration and ticket payment site for the Šumperk scout ball.

## Repository

| Folder | Contents |
|---|---|
| `src/` | The application (Nette project: `app/`, `assets/`, `config/`, `database/`, `tests/`, `www/`, Composer and Bun manifests). |
| `doc/` | Plan, deployment guide, architecture, notes on the old site (`doc/legacy/`). |
| `resource/` | Materials that are not code: the old site (`original/` = HTTP snapshot, `original-web/` = FTP backup), drawings (`design/`). |
| `dev/` | Scripts of the local environment and wrappers of the portable tools. |

## Quick start (Windows)

1. `setup.cmd` - one-time download of portable PHP, MariaDB, Mailpit, Adminer, Composer, Bun, TypeScript and Sass (about 750 MB, nothing is installed system-wide).
2. `start.cmd` - starts everything (incl. asset watchers) and opens http://127.0.0.1:8000/
3. `stop.cmd` - stops everything.
4. `check.cmd` - type check, frontend tests, PHPUnit, PHPStan.

Outgoing e-mails are never delivered locally; they are captured by Mailpit at http://127.0.0.1:8025/.

Less frequent commands are in `dev/`:

- `dev\release.cmd` - package for the hosting; see [doc/deploy.md](doc/deploy.md).
- `dev\portable.cmd` - ZIP with the whole site, database and sample data for internal testing on any Windows (`Start.cmd` inside).
- `dev\init-db.cmd` - reset the database (`-Test` for the PHPUnit database, `-Import dump.sql` for a dump).
- `dev\build.cmd` - one-off build of the assets (`start.cmd` rebuilds them on change).
- `dev\php.cmd`, `dev\composer.cmd`, `dev\bun.cmd`, `dev\tsc.cmd`, `dev\sass.cmd` - portable tools; run them from `src/`.

To remove the environment completely, delete `.tools/`, `.devdata/` and in `src/` the folders
`vendor/`, `node_modules/`, `var/` and `www/build/`.

See [AGENTS.md](AGENTS.md) for conventions, [doc/architecture.md](doc/architecture.md) for the structure
and [doc/legacy/](doc/legacy/) for notes on the old site.
