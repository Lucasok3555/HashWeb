# AGENTS.md

## App overview
HashWeb: a static-site hosting tool. Single-page vanilla-JS frontend (`index.html`) + PHP API (`servidor.php`) using SQLite (`hashweb.db`) and a `sites/` directory for hosted files. Single origin — Apache serves both the frontend and the API on port 3000.

## Running
```
docker compose -f docker-compose.base44.yml up -d
```
No build step, no external services, no credentials required.

## Setup quirks
- The bind mount arrives with restrictive permissions; the compose `command` runs `chmod -R a+rX` (read) and `chmod -R a+w` (write) so Apache (`www-data`) can read source and write the SQLite DB / `sites/` directory.
- `.htaccess` lookups are disabled via a generated apache conf (`AllowOverride None`) to avoid permission-denied errors on the bind mount.
- `pdo_sqlite` is installed at container startup (`docker-php-ext-install`).
- No live-reload dev server — Apache serves files directly from the bind mount. Edits appear on browser refresh; call `reload_preview` after changes.

## Verifying
- Frontend: `curl -s http://localhost:3000/` returns the HashWeb HTML.
- API: `curl -s -X POST "http://localhost:3000/servidor.php?action=create_site" -d "name=Test"` returns JSON with a hash and private key.
