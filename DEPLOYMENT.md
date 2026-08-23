# Deployment Notes

This project uses Deployer with `deploy.php`.

Host-specific Deployer settings live in the local, untracked `.hosts.yaml` file.
Create it before using Deployer:

```yaml
hosts:
  production:
    hostname: <server-hostname>
    remote_user: <ssh-user>
    deploy_path: <remote-path>
    shell_path: /bin/bash
```

## Shared hosting defaults

- writable mode uses `chmod` (no webserver user detection)
- Composer install runs with `--no-dev --no-scripts`
- Symfony cache is cleared explicitly with `APP_ENV=prod`
- snapshot files are generated on server and stored in shared `public/data`

## Required server setup

Create this file once on the server:

- `{{deploy_path}}/shared/.env.local`

Minimum required values:

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=change-me
DATABASE_URL=...
CRON_EGO_TOKEN=change-me-long-random-token
CRON_GITHUB_TOKEN=change-me-another-long-random-token
CRON_HTTP_USER=cron-user
CRON_HTTP_PASSWORD=change-me-strong-password
CRON_DEBUG_EMAIL=ichbingenial@gmail.com
GITHUB_TOKEN=change-me-github-pat
```

`GITHUB_TOKEN` is only required for the GitHub endpoints; `app:update-github-extensions` aborts before any API call if it is missing or empty.

The repository also contains `public/.htaccess` which forces `APP_ENV=prod` and `APP_DEBUG=0` for Apache-based shared hosting.
This prevents accidental `dev` boot when dependencies are installed with `--no-dev`.
Additionally, a root `.htaccess` is included to support shared hosting setups where the document root cannot be pointed directly to `public/`.
Both `.htaccess` files include `FallbackResource` as a backup for hosts where rewrite rules are restricted.

## Frontend build strategy

Frontend assets are expected to be built before deployment and committed to git (`public/build`).
Snapshot files in `public/data` are not committed; they are generated on the target host.

Build locally:

```bash
npm ci
npm run build
```

## Deploy commands

```bash
composer deploy
composer deploy:unlock
```

## Pull the live database

```bash
ddev auth ssh          # once per session, Deployer needs the SSH key
ddev composer db:pull
```

`dep db:pull production` does:

1. read `DATABASE_URL` from `{{deploy_path}}/shared/.env.local` on the live host
2. `mysqldump | gzip` into `/tmp` on the live host
3. download the dump to `var/db/extensionhub-db-<timestamp>.sql.gz` (gitignored) and delete the remote file
4. ask for confirmation, then drop/recreate the local DDEV database and import the dump

Options:

```bash
# dump only, no local import
ddev exec ./vendor/bin/dep db:pull production -o db_import=false

# no confirmation prompt (CI / scripted use)
ddev exec ./vendor/bin/dep db:pull production --no-interaction
```

Config overrides: `db_dump_options`, `db_dump_dir`, `db_import`.
The task supports MySQL/MariaDB only and aborts on any other `DATABASE_URL` scheme.

## Daily cron imports

EGO and GitHub import through two separate, independently scheduled
endpoints. Each accepts only its own token; the EGO token does not work
on the GitHub endpoint and vice versa.

`import-ego.php` runs, in order:

1. `app:update-extensions`
2. `app:parse-comments`
3. `app:build-extension-snapshot`

`import-github-refresh.php` runs, in order:

1. `app:update-github-extensions --refresh` (requires `GITHUB_TOKEN`; aborts before any GitHub API call if it is missing)
2. `app:build-extension-snapshot`

`import-github-discover.php` runs, in order:

1. `app:update-github-extensions --discover` (requires `GITHUB_TOKEN`; aborts before any GitHub API call if it is missing)
2. `app:build-extension-snapshot`

All endpoints share one non-blocking lock: an overlapping second call gets
HTTP 409 and executes no command, so the public snapshot is never built by
two runs at once. A failed command stops that endpoint's run, returns HTTP
500, and triggers the existing debug e-mail.

Schedule EGO import, GitHub refresh and GitHub discovery separately on the
server so their runs cannot overlap; GitHub refresh should run before
discovery, and both GitHub runs must finish before the Cloudflare Pages workflow fetches the snapshot at 03:17 UTC (see
`docs/agents/integrations.md`). Exact cron times are host configuration,
not hardcoded in the repository.

If `CRON_HTTP_USER` and `CRON_HTTP_PASSWORD` are set, both endpoints require HTTP Basic Auth in addition to their token.
