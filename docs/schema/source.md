# Quelle (`Source`)

**Beschreibung:** Eine Quelle beschreibt die Veröffentlichung oder den Datensatz einer Extension bei einem externen Anbieter. Mehrere Quellen desselben Anbieters können zu derselben Extension gehören; ihre Metadaten, Links und Aktivitätsdaten bleiben getrennt.

## Schema

| id | name | english | typ | beschreibung | validierung | status |
| --- | --- | --- | --- | --- | --- | --- |
| P1 | 🔵 Kennung `✱` | `id` | UUID | Eindeutige interne Identität der Quelle. | Eindeutig | bestätigt |
| P2 | 🔵 Quelltyp `✱` | `sourceType` | Enum | Anbieter, aus dem die Quelldaten stammen, etwa EGO oder GitHub. | Zulässiger Quelltyp | bestätigt |
| P3 | 🔵 Quellkennung `✱` | `externalIdentifier` | String | Beim jeweiligen Anbieter eindeutige Kennung der Extension. | Eindeutig je Quelltyp; nicht leer | bestätigt |
| P4 | 🔵 Quell-URL | `sourceUrl` | URL | URL zum externen Datensatz oder Repository. | Gültige URL | bestätigt |
| P5 | 🔵 Installations-URL | `installUrl` | URL | Direkt nutzbare Installations- oder Release-URL, falls die Quelle sie anbietet. | Gültige URL | bestätigt |
| P6 | 🔵 Titel | `title` | String | Von dieser Quelle gelieferter Titel der Extension. | – | bestätigt |
| P7 | 🔵 Beschreibung | `description` | String | Von dieser Quelle gelieferte Beschreibung der Extension. | – | bestätigt |
| P8 | 🔵 Logo-URL | `logoUrl` | URL | Von dieser Quelle gelieferte Logo-URL. | Gültige URL | bestätigt |
| P9 | 🔵 Screenshot-URL | `screenshotUrl` | URL | Von dieser Quelle gelieferte Screenshot-URL. | Gültige URL | bestätigt |
| P10 | 🔵 Unterstützte Shell-Versionen | `supportedShellVersions` | String[] | Von dieser Quelle deklarierte kompatible GNOME-Shell-Versionen. | Mindestens eine Version; keine Duplikate | bestätigt |
| P11 | 🔵 Letzter Commit | `lastCommitAt` | DateTime | Letzter von der Quelle gemeldeter Commit-Zeitpunkt. | Nicht in der Zukunft | bestätigt |
| P12 | 🔵 Letztes Release | `lastReleaseAt` | DateTime | Letzter von der Quelle gemeldeter Release-Zeitpunkt. | Nicht in der Zukunft | bestätigt |
| P13 | 🔵 Erfasst am `✱` | `createdAt` | DateTime | Zeitpunkt, zu dem die Quelle im Verzeichnis angelegt wurde. | Nicht in der Zukunft | bestätigt |
| P14 | 🔵 Aktualisiert am `✱` | `updatedAt` | DateTime | Zeitpunkt der letzten erfolgreichen Übernahme dieser Quelle. | Nicht vor Erfasst am | bestätigt |
| R1 | 🟣 **Extension** | `extension` | n:1 Relation | Jede Quelle gehört genau einer Extension. Eine Extension hat mindestens eine Quelle, auch mehrere desselben Quelltyps. | – | bestätigt |
| R2 | 🟣 **Quellen-Snapshot** | `snapshots` | 1:n Relation | Eine Quelle kann mehrere Quellen-Snapshots über die Zeit besitzen. Jeder Quellen-Snapshot gehört genau einer Quelle. | – | bestätigt |
| R3 | 🟣 **Kommentar** | `comments` | 1:n Relation | Eine Quelle kann mehrere importierte Kommentare besitzen. Ein importierter Kommentar stammt aus genau einer Quelle; ein eigener Kommentar besitzt keine Quelle. | – | bestätigt |
