# Pitfalls

## `BuildExtensionSnapshotCommandTest` fails on stale `.env` database URL

`.env` still ships a PostgreSQL skeleton `DATABASE_URL`, but DDEV
provisions MariaDB 10.11. The four tests in
`tests/Command/BuildExtensionSnapshotCommandTest.php` therefore fail
with:

```
Doctrine\DBAL\Exception\ConnectionException: An exception occurred in the driver: SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5432 failed: Connection refused
```

Full suite as of 2026-08-18: **366 tests, 1063 assertions, 4 errors** —
these four and nothing else. `.env.local` holds the correct MariaDB URL
but Symfony does not load it in the `test` environment, and `.env.test`
declares no `DATABASE_URL` at all.

**Do not "fix" this by just adding a `DATABASE_URL` to `.env.test`.**
These four are real integration tests: they boot the kernel, run
`app:build-extension-snapshot` against the database and assert on
`$kernel->getProjectDir() . '/public/data/extensions.json'` — the actual
local snapshot directory. Give them a working database and every full
suite run silently overwrites your real local snapshot (~5 MB of
imported data) with the content of an empty test database.

A correct fix needs two parts: a test `DATABASE_URL` (plus a `db_test`
database, since `doctrine.yaml` appends `dbname_suffix: _test`) **and**
redirecting the command's output away from `public/data/`.
`ExtensionSnapshotBuilderTest` already shows the pattern — it builds
into a temp dir and cleans up afterwards. Until both parts exist, leave
the four errors alone.

## `public/build/` and `dist/` are committed generated output

Both directories are generated (`npm run build` and
`app:build-static-site` respectively) but tracked in git. Never hand-edit
files inside them — changes will be silently overwritten and diverge
from source. After changing `assets/`, templates, or anything affecting
the static export, regenerate both and commit the results:

```bash
npm run build
ddev exec php bin/console app:build-static-site
```

## `config/reference.php` must stay gitignored

Symfony 7.4 auto-generates `config/reference.php` on cache warmup as an
IDE/static-analysis helper. Our `symfony/framework-bundle` recipe predates
it, so the ignore rule was added manually. Leave it ignored: it is not
only generated output, it also gets picked up by Tailwind's automatic
source detection and silently inflated the built CSS by ~4 KB with
utilities nothing renders.

## Never let generated output become a Tailwind source

Tailwind 4 auto-detects sources from the project tree, and because
`dist/` and `public/build/` are committed (not ignored), the generated
CSS used to be scanned as input. The result was a feedback loop: every
utility already present in the committed CSS kept itself alive, so dead
CSS could never be dropped — roughly 36 KB of it had frozen in that way.

`assets/app.css` therefore starts with:

```css
@source not "../dist";
@source not "../public/build";
```

Do not remove those lines, and add a matching exclusion for any new
committed build output.

## Snapshot aliases must stay byte-identical

`extensions.json` and `extensions.v2.json` are meant to be
byte-identical (`extensions.v2.json` is a stable-URL alias). The
Cloudflare Pages workflow refuses to deploy if `cmp` finds a difference
between the two downloaded files. If you touch snapshot generation,
verify both files still match before assuming the deploy will succeed.

## Server cron ordering matters for the nightly deploy

The production cron must finish `app:update-extensions` →
`app:parse-comments` → `app:build-extension-snapshot` (writing
`extensions.json`, `extensions.v2.json`, `comments.json`) before the
Cloudflare Pages workflow runs at 03:17 UTC. If the import is moved
later or takes longer, the schedule in
`.github/workflows/deploy-cloudflare-pages.yml` must be adjusted too —
otherwise Pages deploys stale or partially-written JSON.

## No JS/E2E test runner

There is no automated frontend test or E2E runner in this project. Use
`npm run build` success plus manual/Playwright checks as the frontend
gate.
