# Extensions Feed

Public schema and contract for the Extension Hub extensions feed.

## Overview

The extensions feed is a static JSON snapshot that contains all available extensions, enabling client-side rendering and filtering without request-time server processing.

## Versioning

- **Public path:** `public/data/extensions.json`
- **Versioned alias pattern:** `public/data/extensions.v{schemaVersion}.json`
  - Example: `public/data/extensions.v2.json` for schema version 2

## Schema

The feed is formally defined by `docs/superpowers/schema/extensions-feed.schema.json` (JSON Schema Draft 2020-12).

### Top-Level Structure

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `schemaVersion` | integer | Yes | Must be `2` |
| `generatedAt` | ISO-8601 string | Yes | Timestamp when snapshot was generated |
| `count` | integer | Yes | Total number of items (must equal `items.length`) |
| `pageSize` | integer | Yes | Fixed to `20` for client-side pagination |
| `items` | array | Yes | Array of extension items |

### Item Fields

#### Required Fields

Every item **must** contain all of these fields:

| Field | Type | Notes |
|-------|------|-------|
| `uuid` | string | Extension's GNOME shell-extension uuid (e.g., `name@author`); the only public identity |
| `path` | string | URL path: `/extension/{urlencoded uuid}` |
| `name` | string | Display name of the extension |
| `description` | string | Short description; empty string if not provided |
| `creator` | string | Creator name; defaults to `'Unknown'` if not provided |
| `creatorUrl` | string or null | Optional URL to creator's profile |
| `supportedShellVersions` | array of strings | Canonical union of validated GNOME Shell versions across all sources |
| `createdAt` | ISO-8601 string | Creation timestamp |
| `updatedAt` | ISO-8601 string | Most recent activity timestamp across all sources |
| `recentSortValue` | integer | Unix timestamp mirroring `updatedAt`, for sorting by recency |
| `score` | integer (0–100) | Source-neutral ranking score combining popularity and freshness |
| `scoreComponents` | object | Contains `popularity` (0–100) and `freshness` (0–100); each is a best-percentile across sources |
| `sources` | array | One or more source objects (EGO and/or GitHub); source-specific links and metrics live here only |
| `hasScreenshot` | boolean | Whether any source has a screenshot available |

#### Removed in v2

These v1 fields **do not exist in v2**:

- `pk` (numeric primary key)
- `slug` (URL slug)
- `gnomeUrl` (extension on extensions.gnome.org)
- `installUrl` (top-level install URL; now source-specific in `sources[].links`)
- `downloads` (top-level metric; now in `sources[].metrics`)
- `rating`, `comments` (top-level metrics; now in `sources[].metrics`)
- `iconUrl`, `screenshotUrl` (top-level display assets; now in `sources[].display*` fields)
- `sourceUrl` (now in `sources[].links`)
- `estimatedCreatedAt`, `estimatedUpdatedAt`

### Sources Structure

Each item's `sources` array contains at least one entry per source:

| Field | Type | Notes |
|-------|------|-------|
| `sourceType` | string | `"ego"` or `"github"` |
| `externalIdentifier` | string | EGO pk (as string) or GitHub repository id (as string) |
| `displayName` | string or null | Source-specific display name |
| `displayDescription` | string or null | Source-specific description |
| `displayIcon` | string or null | URL to source's icon/image |
| `displayScreenshot` | string or null | URL to source's screenshot |
| `supportedShellVersions` | array of strings | This source's validated GNOME Shell versions |
| `lastCommitAt` | ISO-8601 or null | GitHub only: last commit timestamp |
| `lastReleaseAt` | ISO-8601 or null | Most recent release timestamp |
| `links` | object | Source-specific URLs (see below) |
| `metrics` | object | Source-specific metrics (see below) |

#### Source Links

| Field | Source | Value |
|-------|--------|-------|
| `pageUrl` | EGO | URL to EGO detail page |
| `installUrl` | EGO | `gnome-extensions://` install target |
| `repositoryUrl` | GitHub | Repository URL |
| `releaseUrl` | GitHub | Optional URL to release zip |

#### Source Metrics

Only metrics this source measured are present; missing values are omitted:

| Field | EGO | GitHub |
|-------|-----|--------|
| `downloads` | ✓ | – |
| `rating` | ✓ | – |
| `comments` | ✓ | – |
| `stars` | – | ✓ |
| `forks` | – | ✓ |

## Constraints

### Uniqueness

The feed guarantees that within a single snapshot:

- **`uuid`** is unique across all items
- **`path`** is unique across all items

### Timestamps

All timestamp fields use ISO-8601 format (RFC 3339):

```
2026-03-16T14:30:00Z
```

Exception: `recentSortValue` is numeric (not a string timestamp).

## Client-Side Behavior

### No JavaScript Disabled Path

Phase 1 of this API does not support graceful degradation for clients with JavaScript disabled.

### Snapshot Loading

Clients should:

1. Fetch the snapshot from the public path
2. Validate schema version matches `2`
3. Validate `count` matches `items.length`
4. Validate uniqueness of `uuid` and `path`
5. Cache the snapshot for the session
6. Use client-side routing and filtering for all list/detail views

### Error Handling

If the feed cannot be loaded or validated:

- Display a user-facing error message
- Offer a manual retry button
- After repeated failures, suggest contacting support

## Example Item

```json
{
  "uuid": "my-extension@example.com",
  "path": "/extension/my-extension%40example.com",
  "name": "My Extension",
  "description": "A useful extension for GNOME Shell",
  "creator": "John Doe",
  "creatorUrl": "https://example.com",
  "supportedShellVersions": ["45", "46", "47"],
  "createdAt": "2025-01-01T10:00:00Z",
  "updatedAt": "2026-03-16T14:30:00Z",
  "recentSortValue": 1742304600,
  "score": 78,
  "scoreComponents": {
    "popularity": 85,
    "freshness": 70
  },
  "sources": [
    {
      "sourceType": "ego",
      "externalIdentifier": "123",
      "displayName": "My Extension",
      "displayDescription": "A useful extension for GNOME Shell",
      "displayIcon": "https://example.com/icon.png",
      "displayScreenshot": "https://example.com/screenshot.png",
      "supportedShellVersions": ["45", "46", "47"],
      "lastCommitAt": null,
      "lastReleaseAt": "2026-03-16T14:30:00Z",
      "links": {
        "pageUrl": "https://extensions.gnome.org/extension/123/my-extension/",
        "installUrl": "gnome-extensions://install-extension/my-extension@example.com"
      },
      "metrics": {
        "downloads": 1500,
        "rating": 4.2,
        "comments": 42
      }
    },
    {
      "sourceType": "github",
      "externalIdentifier": "987654",
      "displayName": null,
      "displayDescription": null,
      "displayIcon": null,
      "displayScreenshot": null,
      "supportedShellVersions": ["45", "46", "47"],
      "lastCommitAt": "2026-03-15T09:20:00Z",
      "lastReleaseAt": "2026-03-16T14:30:00Z",
      "links": {
        "repositoryUrl": "https://github.com/example/my-extension",
        "releaseUrl": "https://github.com/example/my-extension/releases/download/v1.0.0/my-extension.zip"
      },
      "metrics": {
        "stars": 342,
        "forks": 28
      }
    }
  ],
  "hasScreenshot": true
}
```

## Future Versions

When schema changes are needed:

1. Increment `schemaVersion`
2. Update this document
3. Update `docs/superpowers/schema/extensions-feed.schema.json`
4. Maintain backward compatibility or provide migration guidance for clients
