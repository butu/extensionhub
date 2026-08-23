# Kommentar (`Comment`)

**Beschreibung:** Ein Kommentar ist eine Rückmeldung zu einer Extension. Er kann aus einer Quelle übernommen oder direkt in Extension Hub erstellt werden.

## Schema

| id | name | english | typ | beschreibung | validierung | status |
| --- | --- | --- | --- | --- | --- | --- |
| P1 | 🔵 Kennung `✱` | `id` | UUID | Eindeutige interne Identität des Kommentars. | Eindeutig | bestätigt |
| P2 | 🔵 Autorname `✱` | `authorUsername` | String | In der Quelle angezeigter Name des Autors. | Nicht leer | bestätigt |
| P3 | 🔵 Autor-URL | `authorUrl` | URL | Öffentliche URL des Autors, falls die Quelle sie liefert. | Gültige URL | bestätigt |
| P4 | 🔵 Avatar-URL | `avatarUrl` | URL | Öffentliche Avatar-URL des Autors, falls die Quelle sie liefert. | Gültige URL | bestätigt |
| P5 | 🔵 Text `✱` | `text` | String | Wortlaut der Rückmeldung. | Nicht leer | bestätigt |
| P6 | 🔵 Bewertung `✱` | `rating` | Integer | Von der Quelle vergebene numerische Bewertung. | Wertebereich je Quelle | bestätigt |
| P7 | 🔵 Ersteller-Kommentar `✱` | `isExtensionCreator` | Boolean | Kennzeichnet, ob der Autor als Ersteller der Extension ausgewiesen ist. | – | bestätigt |
| P8 | 🔵 Verfasst am `✱` | `commentedAt` | DateTime | Von der Quelle gemeldeter Zeitpunkt der Veröffentlichung. | Nicht in der Zukunft | bestätigt |
| R1 | 🟣 **Quelle** | `source` | n:1 Relation | Ein importierter Kommentar stammt aus genau einer Quelle. Ein direkt in Extension Hub erstellter Kommentar besitzt keine Quelle; eine Quelle kann mehrere Kommentare liefern. | – | bestätigt |
| R2 | 🟣 **Extension** | `extension` | n:1 Relation | Jeder Kommentar gehört genau einer Extension. Eine Extension kann mehrere Kommentare besitzen. | – | bestätigt |
