# HashWeb — Base44 Dev Environment

## What this is
HashWeb is a static-site hosting tool: a single-page frontend (`index.html`, vanilla JS) that talks to a PHP backend (`servidor.php`) using SQLite for storage and the filesystem for hosted site files. No external services or credentials are required.

## Architecture
- **Single service**: PHP 8.2 + Apache (`php:8.2-apache`), serving both the static frontend and the PHP API from the same origin on port 3000.
- **No build step**: Apache serves files directly from the bind-mounted source, so edits to `index.html` / `servidor.php` appear immediately on browser refresh (no live-reload dev server; use `reload_preview` after changes).
- **Data**: SQLite DB (`hashweb.db`) and `sites/` directory are created at runtime in the repo root by the PHP code.

## Running
```
docker compose -f docker-compose.base44.yml up -d
```
Health check: `curl -s -o /dev/null -w "%{http_code}" http://localhost:3000/` → 200

## Setup quirks
- The bind-mounted source arrives with restrictive permissions; the compose command runs `chmod -R a+rX` and `chmod -R a+w` so Apache (www-data) can read files and write the SQLite DB / sites directory.
- `.htaccess` lookups are disabled (`AllowOverride None` via a generated apache conf) to avoid permission-denied errors on the bind mount.
- The `pdo_sqlite` PHP extension is installed at container startup.

## How to verify
- Frontend loads: `curl -s http://localhost:3000/` returns the HashWeb HTML.
- API works: `curl -s -X POST "http://localhost:3000/servidor.php?action=create_site" -d "name=Test"` returns JSON with a hash and private key.
- New endpoints: `import_site` (replication), `upload_file` (base64 file upload), `get_file_b64` (binary-safe retrieval).

## Features
- **Network replication**: "Sincronizar rede" button copies all site files to all servers in the list. Files are stored with obfuscated (scrambled) paths keyed by the private key, so the server operator can't read the real folder structure.
- **Path obfuscation**: Client-side `scramblePath`/`descramblePath` (XOR + base64url, keyed by private key). The file manager always scrambles paths before sending to the server; the page viewer tries scrambled then falls back to plain paths.
- **File upload**: "Upload" button asks for file or folder; folder uploads preserve structure via `webkitdirectory`.
- **Full-screen editor**: The code editor modal covers the entire viewport.
- **Loading indicator**: Spinner shows when opening the file manager.
- **Script/URL fixes**: `<script src>` in rendered pages resolves relative to the hash-based folder path; folder URLs (`hash/folder/page.html`) are supported.
