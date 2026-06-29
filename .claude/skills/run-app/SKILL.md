---
name: run-app
description: Launch the php-claw dashboard — the TrueAsync API server plus the React/Vite UI. Use when asked to run, start, serve, or screenshot the app/dashboard/UI.
---

# Running the php-claw dashboard

Two processes: the PHP **API server** (`claw serve`) and the **Vite UI** dev
server. The UI proxies `/api` → the API, so the browser only ever talks to Vite.

## Key gotcha (why this took so long the first time)

`claw serve` needs the **TrueAsync server extension** (`true_async_server.so`),
which provides `TrueAsync\HttpServer`. The base `true_async` extension is loaded
by `/usr/local/lib/php.ini`, but the **server** extension is NOT:
`php --ini` shows the scan-dir is `(none)`, so `/etc/php.d/true_async_server.ini`
is never read. You must load it explicitly with `-d extension=...`.

The correct `.so` is the one already in PHP's `extension_dir`
(`/usr/local/lib/php/extensions/debug-zts-20250926/`), so the bare name works —
do NOT point at the build dirs under `~/php-http-server`, they are ABI-mismatched
and fail with "These options need to match".

## Launch

```bash
# 1. API server (port 8787) — run from the repo root, in the background
cd /home/edmond/php-claw
php -d extension=true_async_server.so bin/claw serve --port 8787 --host 127.0.0.1

# 2. UI (port 5173) — separate process
cd /home/edmond/php-claw-ui
npm run dev
```

Then open **http://127.0.0.1:5173/** (WSL2 → forwards to Windows browser).

## Smoke test

```bash
curl -s http://127.0.0.1:8787/api/projects          # API direct  → JSON list of projects
curl -s http://127.0.0.1:5173/api/projects          # through the Vite proxy → same JSON
```

Note the routes live under `/api` (e.g. `/api/projects`), not `/projects`.

## Notes

- UI repo: `/home/edmond/php-claw-ui` (separate git repo, React 19 + Vite 6).
- Override the API target for the proxy with `CLAW_API=http://host:port npm run dev`.
- Projects served come from `workspace/projects` (the demo/katas/sample projects).
