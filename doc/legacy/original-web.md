# Legacy site: FTP backup

Complete copy of the old site from the hosting, kept as-is in `resource/original-web/`.
Unlike `resource/original/` (only what the server returned over HTTP, see
[original.md](original.md)), this includes the PHP sources.

| Path | Contents |
|---|---|
| `ftp/www/` | Document root: `index.php`, `ajax.php`, `admin/`, `ticket/`, `check/` (entry check), `QRPlatba/`, `PHPMailer/`, images. |
| `ftp/include/app/` | Nette application (`FrontModule`, `AdminModule`, `model`, `email` templates). |
| `ftp/include/libs/` | Nette Framework used by the old site (third-party, kept for completeness). |
| `mysql/` | Database dump (local only). |

Kept local only (`.gitignore`): the database dump, logs, temp files, `.env`, `include/app/config/`
and the `db.php` / `mailSet.php` files - they contain credentials or personal data.

Features of the old site that the new one does not have yet are listed in the backlog of
[`doc/plan.md`](../plan.md); a feature-by-feature comparison of both legacy layers (2023 Nette app,
2025/2026 PHP scripts) with the new site is in [feature-comparison.md](feature-comparison.md).
