# AGENTS.md

Extension Hub: Symfony 7.4 LTS / PHP 8.2+ app that
imports GNOME Shell extension metadata (EGO is the current production
cron source; GitHub indexing exists as an opt-in
`app:update-github-extensions` command, not yet in the daily cron) and
publishes it as a public JSON snapshot plus a Twig/JS extension browser.
Eigenprojekt von Benjamin; communicate in German, du, casual/pragmatic.
Vision: everything open source, Extension Hub a source-neutral directory —
the public snapshot is meant to be reused by third-party tools.

## Essentials

- Tooling: DDEV-based Symfony app, docroot `public/`, PHP 8.3, MariaDB
  10.11; application code lives under `src/`.
- Build: `npm run build` (Vite → `public/build/`, committed);
  `ddev exec php bin/console app:build-static-site` (→ `dist/`, committed).
- PHP tests: `ddev exec ./vendor/bin/phpunit` → 366 tests, 4 known
  errors, all in `BuildExtensionSnapshotCommandTest` (stale `.env`
  PostgreSQL URL). Read `docs/agents/testing.md` before judging results.
- JS/E2E tests: none exist; use `npm run build` as the frontend gate.
- QA/lint: no ESLint/Prettier/PHPStan/PHP CS Fixer/webprofil-qa. Use
  `php -l`, `lint:twig`, `lint:container` — see `docs/agents/commands.md`.
- Logs/debug: `ddev logs -s web`; dev log at `var/log/dev.log`; prod
  logs JSON to stderr.
- Deploy: never run through agents by default (`composer deploy`,
  `db:pull` — overwrites local DB). Read `docs/agents/integrations.md` first.
- Tickets: local Markdown under `docs/todos/`, no external tracker.
  `webprofil/todos` **is** installed (dev); drive it through the Bash CLI
  — see `docs/agents/commands.md`.
- Generated outputs: `public/build/`, `dist/` are committed but
  generated — never hand-edit; `vendor/`, `node_modules/`, `var/`,
  `public/data/` are ignored/generated.
- External systems: EGO (production cron import), GitHub API (opt-in
  `app:update-github-extensions`, requires `GITHUB_TOKEN`, not in daily
  cron), Cloudflare Pages, shared-host production. Read
  `docs/agents/integrations.md` before touching any of them.

## Read before you touch

- `docs/agents/project-structure.md` — read before adding files or
  touching generated output; has placement and boundary rules.
- `docs/agents/commands.md` — read before running any command that
  isn't already listed above.
- `docs/agents/testing.md` — read before running tests or claiming any
  suite passes.
- `docs/agents/conventions.md` — read before writing or refactoring code.
- `docs/agents/integrations.md` — read before touching EGO, GitHub,
  Cloudflare Pages, or production.
- `docs/agents/pitfalls.md` — read when something fails unexpectedly.
- `DESIGN.md` — binding before any UI/markup/color/layout change.
- `UBIQUITOUS_LANGUAGE.md` — binding before any domain naming change.
