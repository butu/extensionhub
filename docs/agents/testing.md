# Testing

## Essentials

The suite is almost entirely green. Only the four tests in
`BuildExtensionSnapshotCommandTest` fail, always for the same
environment reason (PostgreSQL `DATABASE_URL`, see Pitfalls). Frontend
has no automated test/E2E runner; use build success as the smoke gate.

## Verified results (2026-08-18)

```bash
ddev exec ./vendor/bin/phpunit                  # 366 tests, 1063 assertions, 4 errors
ddev exec ./vendor/bin/phpunit tests/Service    # 320 tests,  960 assertions, OK
ddev exec ./vendor/bin/phpunit tests/Repository #  17 tests,   32 assertions, OK
ddev exec ./vendor/bin/phpunit tests/Controller #  10 tests,   23 assertions, OK
ddev exec ./vendor/bin/phpunit tests/Command    #  19 tests,   48 assertions, 4 errors
```

PHPUnit takes only **one** path argument — passing several directories
silently runs just the first one.

## The 4 known errors

All four live in `tests/Command/BuildExtensionSnapshotCommandTest.php`
and fail with `SQLSTATE[08006] … port 5432 … Connection refused`:
`.env` ships the PostgreSQL skeleton `DATABASE_URL` while DDEV provides
MariaDB 10.11, and `.env.local` (which has the correct MariaDB URL) is
not loaded in the `test` environment. Everything else — including every
repository test — runs without a database.

Giving them a database is **not** enough and not safe on its own: they
assert against the real `public/data/` snapshot and would overwrite it.
Read `docs/agents/pitfalls.md` before touching them.

## Gates by change type

- **PHP/backend logic** (`src/Service/`, `src/Repository/`,
  `src/Command/`): run the full suite and expect exactly the 4 known
  errors above, plus the PHP syntax check described in
  `docs/agents/commands.md`.
- **Twig templates**: `ddev exec php bin/console lint:twig templates`.
- **Routing / DI / services.yaml**: `ddev exec php bin/console lint:container`.
- **Frontend/static build** (`assets/`, Vite config, static-site
  templates): `npm run build`, then
  `ddev exec php bin/console app:build-static-site` if the static
  export is affected. Both are verified working; both write generated,
  committed output (`public/build/`, `dist/`) — check the diff before
  committing.
- **Snapshot generation** (`ExtensionSnapshotBuilder`, snapshot
  commands): `ExtensionSnapshotBuilderTest` runs fine (it mocks the
  repository); only `BuildExtensionSnapshotCommandTest` is blocked by the
  DB mismatch above. Additionally verify `extensions.json` and
  `extensions.v2.json` stay byte-identical (see
  `docs/agents/pitfalls.md`).

## External integration validation boundary

Do not validate against live external systems (EGO, planned GitHub API,
production server, Cloudflare) from an agent session. Use fixtures/mocks
in existing tests. Any check against a real external system needs
explicit confirmation first — see `docs/agents/integrations.md`.
