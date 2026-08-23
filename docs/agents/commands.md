# Commands

All commands below run from the repo root and are verified working as
shown. Prefer DDEV for all PHP-based commands.

## Verified commands

Every command below has been executed successfully in this repo. `npm run
build` writes `public/build/` (committed); `app:build-static-site` writes
`dist/` (committed).

```bash
ddev describe
npm run build
ddev exec php bin/console app:build-static-site
ddev exec ./vendor/bin/phpunit tests/Controller/ExtensionControllerTest.php --filter testDetailRouteReturnsShell
ddev exec ./vendor/bin/phpunit tests/Service/StaticSiteBuilderTest.php
ddev exec ./vendor/bin/phpunit tests/Command/BuildStaticSiteCommandTest.php
ddev exec php -l src/Service/ExtensionSnapshotBuilder.php
ddev exec php bin/console lint:twig templates
ddev exec php bin/console lint:container
ddev logs -s web
```

`ddev exec php -l src/Service/ExtensionSnapshotBuilder.php` is the
verified sample invocation. For another changed PHP file, replace only
the final path; that substitution has not been separately verified per
file.

## Tests

See `docs/agents/testing.md` for what's actually green vs. currently
blocked. Do not run the full `./vendor/bin/phpunit` suite and assume it
passes.

## Tickets

Local tasks live under `docs/todos/` as Markdown files, managed by
`webprofil/todos` (dev dependency). Prefer the Bash CLI — the
`vendor/bin/wp-todos` wrapper needs PHP, which is not on the host:

```bash
export WP_TODOS_PROJECT_ROOT="$PWD"
bash vendor/webprofil/todos/resources/cli/wp-todos.sh list --tree --all   # verified
bash vendor/webprofil/todos/resources/cli/wp-todos.sh show <slug>
bash vendor/webprofil/todos/resources/cli/wp-todos.sh cleanup             # verified
```

Change status and hierarchy through the CLI (`done`, `decline`,
`reopen`, `move`, `rename`, `promote`) — never move todo directories by
hand. The read-only web UI lives at
`https://extensionhub.ddev.site/wp-todos/` and is generated into
the gitignored `public/wp-todos/`.

## Missing tooling (do not assume these exist)

- No ESLint, no Prettier, no PHPStan, no PHP CS Fixer, no webprofil-qa.
- No real JS/E2E test runner at all.
- Use the verified `php -l` sample above for PHP syntax checks and
  `npm run build` as the frontend smoke test.

## Logs / debug

```bash
ddev logs -s web         # verified
```

- Local dev app log: `var/log/dev.log`.
- Production logs are written as JSON to stderr (no local log file to
  tail on shared hosting).

## Deploy — do not run by default

Deploys are not run through agents unless explicitly confirmed by
Benjamin. See `docs/agents/integrations.md` and `DEPLOYMENT.md` for the
full picture, including `composer deploy` and `db:pull` (which can
overwrite the local DDEV database).
