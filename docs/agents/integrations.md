# Integrations

Vision: Extension Hub aims to become an open-source, source-neutral
extension directory. Its public JSON snapshot is intentionally reusable
by third parties, including native GNOME extension tools. EGO and the
GitHub source are meant to be merged by extension identity, not kept as
separate silos.

## EGO (extensions.gnome.org) — import source

- Purpose: primary current source of extension metadata, imported via
  `app:update-extensions`.
- Boundary: read-only import; do not write back to EGO. Treat imported
  data as an `ExtensionSource`/`ExternalIdentifier` per
  `UBIQUITOUS_LANGUAGE.md`, merged into a canonical extension.

## GitHub API — opt-in source

- Purpose: second source for extension indexing, merged with EGO by
  extension identity via `app:update-github-extensions` (`--discover`
  and/or `--refresh`; see `docs/prd/github-extension-indexing.md` and
  `docs/todos/github-extension-indexing/`).
- Status: implemented and wired into production via separate refresh and
  discovery endpoints (`public/cron/import-github-refresh.php`,
  `public/cron/import-github-discover.php`), separate from the EGO endpoint
  (`public/cron/import-ego.php`). Requires both `GITHUB_TOKEN`
  (the command aborts before any API call if it is missing or empty)
  and `CRON_GITHUB_TOKEN` (the endpoint's own cron auth token).
- Boundary: treat `GITHUB_TOKEN` as a real credential once set; never
  hardcode it. Running the command against production requires the
  same explicit confirmation as other production actions below.

## Cloudflare Pages — static hosting

- Purpose: serves the committed `dist/` static site; replaces only
  `dist/data/*.json` nightly from the origin server before deploying.
- Credentials/config: `CLOUDFLARE_API_TOKEN`, `CLOUDFLARE_ACCOUNT_ID`
  (GitHub Actions secrets), `CLOUDFLARE_PAGES_PROJECT`,
  `SNAPSHOT_BASE_URL` (GitHub Actions variables). Never hardcode these;
  see `docs/cloudflare-pages.md` for full setup.
- Trigger: `.github/workflows/deploy-cloudflare-pages.yml` — every push
  to `master`, daily schedule at 03:17 UTC (after the server cron), or
  manual `workflow_dispatch`.
- Boundary: do not trigger or edit this deploy without explicit
  confirmation; it publishes to production.

## Production server (shared hosting via Deployer)

- Purpose: runs the database, separate daily import crons —
  `public/cron/import-ego.php` (`app:update-extensions` →
  `app:parse-comments` → `app:build-extension-snapshot`) and
  `public/cron/import-github-refresh.php` (`app:update-github-extensions --refresh` →
  `app:build-extension-snapshot`) and `public/cron/import-github-discover.php`
  (`app:update-github-extensions --discover` → `app:build-extension-snapshot`),
  scheduled time-shifted — and serves
  the public snapshot files (`extensions.json`, `extensions.v2.json`,
  `comments.json`) that the Cloudflare workflow fetches.
- Credentials/config: server-side `.env.local` — see `DEPLOYMENT.md`
  for the full required variable list. Never hardcode secrets in
  repo-tracked files.
- Commands: `composer deploy` (Deployer, shared host) and
  `composer db:pull` (downloads and imports the production DB into
  local DDEV, overwriting it). Both require explicit confirmation
  before running; see `DEPLOYMENT.md`.
- Boundary: no destructive actions, deploys, or DB pulls without
  explicit confirmation from Benjamin.

## Further reading

- `DEPLOYMENT.md` — shared-host deploy, cron, `db:pull` details.
- `docs/cloudflare-pages.md` — Cloudflare Pages setup and troubleshooting.
