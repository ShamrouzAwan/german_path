# Installation

## Requirements

- PHP 8.1 or newer
- PDO SQLite enabled
- Apache with `mod_rewrite` (or equivalent URL rewriting)
- a writable directory for the SQLite database and runtime logs

## First install

1. Copy the repository to the hosting account.
2. Point the web server document root at `public/`.
3. Copy `.env.example` to `.env`.
4. Set `SITE_URL` to the real HTTPS site URL.
5. Set a private `DB_PATH` and confirm its parent directory is writable.
6. Keep `WORKER_SECRET` empty until the Worker integration is implemented.
7. Open the site once. With `AUTO_MIGRATE=true`, the migration runner creates
   the SQLite schema automatically.
8. Set `AUTO_MIGRATE=false` after the first controlled production migration if
   migrations should no longer run during web requests.

Do not put `.env`, SQLite files, uploads, or private backups inside the
publicly served directory.
