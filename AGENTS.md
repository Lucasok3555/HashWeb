# AGENTS.md — HashWeb (Base44 dev environment)

## What this is
HashWeb is a static-site hosting tool: a single-page vanilla-JS frontend (`index.html`) plus a PHP API
(`servidor.php`) that stores sites by SHA-256 hash, using SQLite (`hashweb.db`) for the registry and the
filesystem (`sites/<hash>/`) for each site's files. No build step, no external services, no credentials.

## Running
```
docker compose -f docker-compose.base44.yml up -d
```
- One service: Apache + PHP 8.2 serving both the frontend and the API from the same origin on port 3000.
- `php:8.2-apache` already ships `pdo_sqlite`/`sqlite3` and `curl`, so nothing is installed at startup
  (never add `apt-get`/`docker-php-ext-install` here — it makes startup depend on the network).

## Setup quirks
- The bind-mounted source arrives with restrictive permissions; the compose `command` runs
  `chmod -R a+rX` (read) and `chmod -R a+w` (write) so Apache (`www-data`) can read the source and write
  `hashweb.db` / `sites/` at runtime.
- `.htaccess` lookups are disabled (`AllowOverride None`) to avoid permission-denied noise on the bind mount.
- No live-reload dev server: Apache serves files straight from the mount. Refresh the browser (or call
  `reload_preview`) to see edits.
- `hashweb.db` and `sites/` are runtime data created by `servidor.php` in the repo root; they are
  `.gitignore`d and must not be committed.

## Non-obvious pitfall: raw end tags inside the inline <script>
The page loads under the preview proxy, which injects its instrumentation before the **first** `</body>`
in the HTML response. A JS string literal that contains a raw `</body>`, `</head>` or `</html>`
(the starter-page template does) makes the proxy inject inside the script: the `<script>` element is
terminated mid-string and the rest of the JS is rendered on the page as text, with
`Uncaught SyntaxError: Invalid or unexpected token` in the console.

Keep HTML end tags inside JS strings escaped (`'<\/body>'`, `'<\/head>'`, `'<\/html>'`) so no closing tag
appears as raw text in the document. The same rule applies to `</script>` in any inline string.

## Verifying
- Frontend: `curl -s http://localhost:3000/` returns the HashWeb HTML.
- API: `curl -s "http://localhost:3000/servidor.php?action=status"` → `{"ok":true,"status":"online",...}`.
- End-to-end (browser): Servidores → add `<origin>/servidor.php` → Hospedagem → create a site
  (returns a hash + private key) → Arquivos shows the uploaded `index.html` → Apagar removes it.
  Confirm persistence with `curl "http://localhost:3000/servidor.php?action=get_site&hash=<hash>"`.
