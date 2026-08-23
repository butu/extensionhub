<div align="center">
  <img src="docs/assets/logo-readme.svg" alt="Extension Hub" width="240" height="80">
  <p>A source-neutral directory and open data feed for GNOME Shell extensions.</p>
  <a href="https://extension-hub.pages.dev/"><img src="https://img.shields.io/badge/website-extension--hub.pages.dev-3584e4?logo=gnome&amp;logoColor=white" alt="Live site"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/butu/extensionhub" alt="License"></a>
  <a href="https://symfony.com/"><img src="https://img.shields.io/badge/Symfony-7.4-000000?logo=symfony" alt="Symfony"></a>
  <a href="https://extensions.gnome.org/"><img src="https://img.shields.io/badge/GNOME-Shell_extensions-4a86cf?logo=gnome&amp;logoColor=white" alt="GNOME Shell"></a>
</div>

## 📋 Features

Extension Hub provides:

- A fast, client-side browser for discovering GNOME Shell extensions
- Search, filters, extension details, screenshots, ratings, and comments
- A public JSON snapshot for desktop apps, websites, and community tools
- A source-neutral data model: extensions are identified by their GNOME Shell UUID, not their source
- An open-source import and static publishing pipeline

## 🌐 Browse extensions

Visit [extension-hub.pages.dev](https://extension-hub.pages.dev/) to browse the
directory.

Extension Hub imports metadata from
[extensions.gnome.org](https://extensions.gnome.org/) (EGO) and GitHub. The
production cron runs EGO import, GitHub refresh and GitHub discovery separately
so known repositories remain current even if a discovery run is delayed. GitHub
indexing requires a server-side `GITHUB_TOKEN`.

## 🔌 Use the data

The JSON snapshots are public and require no authentication:

- [`extensions.v2.json`](https://extension-hub.pages.dev/data/extensions.v2.json)
  — versioned feed; recommended for integrations
- [`extensions.json`](https://extension-hub.pages.dev/data/extensions.json) —
  unversioned alias of the current feed
- [`comments.json`](https://extension-hub.pages.dev/data/comments.json) —
  exported extension comments

The extension feed includes its schema version and generation timestamp.
Consumers should validate `schemaVersion`, ensure `count` matches the number of
items, and use the extension UUID as the stable identity.

The complete contract and JSON Schema are available in:

- [`docs/superpowers/schema/extensions-feed.md`](docs/superpowers/schema/extensions-feed.md)
- [`docs/superpowers/schema/extensions-feed.schema.json`](docs/superpowers/schema/extensions-feed.schema.json)

## ⚙️ How it works

```text
EGO metadata ──► Symfony importer ──► MariaDB ──► JSON snapshots
                                                     │
                           ┌─────────────────────────┴──────────────────────┐
                           ▼                                                ▼
                  Extension Hub browser                          Third-party clients

Opt-in: GitHub discovery ──► source merge by GNOME Shell UUID ──► same snapshot
```

The server imports and normalizes metadata, then publishes static JSON files.
The website renders search, filters, lists, and extension details from those
files in the browser. A committed static export in `dist/` is deployed to
Cloudflare Pages; the import pipeline remains on the application server.

## 💻 Developing Extension Hub

### Requirements

- [DDEV](https://ddev.com/)
- Node.js and npm

### Quick start

```bash
git clone git@github.com:butu/extensionhub.git
cd extensionhub
ddev start
ddev composer install
npm ci
npm run build
```

The local site is available at
<https://extensionhub.ddev.site>. To build the committed static export:

```bash
ddev exec php bin/console app:build-static-site
```

The data import needs a configured database and calls external services, so it
is intentionally not part of the quick start. Current test commands and a
known database-test limitation are documented in
[`docs/agents/testing.md`](docs/agents/testing.md).

## 🤝 Contributing

Contributions and experiments are welcome. Before changing an area, please
read the relevant project contract:

- [`DESIGN.md`](DESIGN.md) for UI and visual changes
- [`UBIQUITOUS_LANGUAGE.md`](UBIQUITOUS_LANGUAGE.md) for domain terminology
- [`docs/agents/project-structure.md`](docs/agents/project-structure.md) for
  code placement and generated-file boundaries
- [`DEPLOYMENT.md`](DEPLOYMENT.md) for the server-side pipeline
- [`docs/cloudflare-pages.md`](docs/cloudflare-pages.md) for static hosting

Local work items and planned features are tracked under
[`docs/todos/`](docs/todos/).

## 📄 License

Extension Hub is available under the [MIT License](LICENSE).
