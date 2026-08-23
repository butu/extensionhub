# Conventions

Binding docs first: `DESIGN.md` for any UI/markup/color/layout change,
`UBIQUITOUS_LANGUAGE.md` for any domain naming (entities, fields,
terms). Read the relevant one before touching that area.

## Architecture

- Controllers stay thin: render the shell, avoid business logic.
- Transformation/export logic lives in `src/Service/`
  (`ExtensionSnapshotBuilder` is the reference implementation).
- Repositories use Doctrine query builders (`createQueryBuilder()`),
  return entities or explicit scalar values.
- Public typed entity properties are intentional; do not refactor to
  getters/setters without a broader, explicitly agreed change.
- No isolated `declare(strict_types=1);` — the repo does not use it;
  don't add it file-by-file.
- Browser code is split into small modules under `assets/app/`
  (kebab-case filenames).
- Twig is shell/composition only; the list/detail UI is driven
  client-side after the initial response.

## Naming

- PHP classes: `PascalCase`; methods/properties: `camelCase`.
- Console commands: kebab-case, e.g. `app:build-extension-snapshot`.
- Routes: snake_case names, e.g. `extension_list`, `extension_show`.
- Twig templates: lowercase snake_case; partials get a leading
  underscore.
- JS modules in `assets/app/`: kebab-case filenames.
- Project CSS helper classes: `eh-` prefix.

## PHP style

- Constructor injection; `config/services.yaml` enables autowire and
  autoconfigure.
- Explicit return types; typed properties, nullable where needed.
- `final class` for controllers/services when inheritance isn't needed.
- Early returns over deep nesting; strict comparisons (`===`, `!==`).
- Use `JSON_THROW_ON_ERROR` for JSON encode/decode.
- Cast scalar query results deliberately (e.g. `(int)` for counts).
- Document non-obvious associative array shapes with PHPDoc.

## Data snapshot invariants

- `extensions.json` and `extensions.v2.json` must always be
  byte-identical (`extensions.v2.json` is a stable-URL alias). The
  Cloudflare workflow enforces this with `cmp`; keep local generation
  consistent with it — see `docs/agents/pitfalls.md`.

## Frontend/CSS

- Prefer Tailwind utilities and DaisyUI primitives first; add shared
  `eh-` classes in `assets/app.css` only when repetition appears.
- Visual language (fonts, colors, dark theme) is defined in `DESIGN.md`;
  defer to it rather than restating specifics here.
