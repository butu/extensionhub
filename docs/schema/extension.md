# Extension (`Extension`)

**Beschreibung:** Die Extension ist der UUID-eindeutige Eintrag im Verzeichnis. Sie bündelt die fachlich gemeinsamen Angaben ihrer Quellen, ohne eine Quelle zu bevorzugen, und bestimmt die gemeinsamen Präsentationsbilder.

## Schema

| id | name | english | typ | beschreibung | validierung | status |
| --- | --- | --- | --- | --- | --- | --- |
| P1 | 🔵 UUID `✱` | `uuid` | String | GNOME-Shell-UUID als fachliche Identität der Extension. | Eindeutig; nicht leer | bestätigt |
| P2 | 🔵 Titel `✱` | `title` | String | Für das Verzeichnis angezeigter Titel der Extension. | Nicht leer | bestätigt |
| P3 | 🔵 Beschreibung | `description` | String | Für das Verzeichnis angezeigte Beschreibung der Extension. | – | bestätigt |
| P4 | 🔵 Ersteller | `creator` | String | Angezeigter Ersteller der Extension. | – | bestätigt |
| P5 | 🔵 Ersteller-URL | `creatorUrl` | URL | Verweis auf den Ersteller, falls eine Quelle ihn liefert. | Gültige URL | bestätigt |
| P6 | 🔵 Erstveröffentlicht am | `publishedAt` | DateTime | Frühester verlässlicher Veröffentlichungszeitpunkt der Extension aus ihren Quellen. | Nicht in der Zukunft | bestätigt |
| P7 | 🔵 Zuletzt aktualisiert am | `lastUpdatedAt` | DateTime | Jüngster verlässlicher Aktivitätszeitpunkt der Extension aus ihren Quellen. Importzeitpunkte zählen nicht als Aktualisierung. | Nicht in der Zukunft | bestätigt |
| P8 | 🔵 Logo-URL | `logoUrl` | URL | Für das Verzeichnis ausgewähltes Logo der Extension. Die ursprüngliche URL bleibt bei der liefernden Quelle nachvollziehbar. | Gültige URL | bestätigt |
| P9 | 🔵 Screenshot-URL | `screenshotUrl` | URL | Für das Verzeichnis ausgewählter Screenshot der Extension. Die ursprüngliche URL bleibt bei der liefernden Quelle nachvollziehbar. | Gültige URL | bestätigt |
| P10 | 🔵 Unterstützte Shell-Versionen | `supportedShellVersions` | String[] | Zusammengeführte Menge aller von den Quellen deklarierten kompatiblen GNOME-Shell-Versionen. | Keine Duplikate | bestätigt |
| P11 | 🔵 Suchbegriffe | `searchTerms` | String[] | Zusätzliche Begriffe, unter denen die Extension im Verzeichnis auffindbar sein soll. | Keine Duplikate; nicht leer je Begriff | bestätigt |
| R1 | 🟣 **Quelle** | `sources` | 1:n Relation | Eine Extension hat mindestens eine Quelle und kann mehrere Quellen desselben Quelltyps besitzen. Jede Quelle gehört genau einer Extension. | Mindestens eine Quelle | bestätigt |
| R2 | 🟣 **Kommentar** | `comments` | 1:n Relation | Eine Extension kann mehrere Kommentare besitzen. Jeder Kommentar gehört genau einer Extension. | – | bestätigt |
