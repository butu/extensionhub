# Quellen-Snapshot (`SourceSnapshot`)

**Beschreibung:** Ein Quellen-Snapshot ist ein zeitlich erfasster Kennzahlenwert einer Quelle. Er bleibt quellspezifisch, damit Werte verschiedener Anbieter nicht als Rohwerte vermischt werden.

## Schema

| id | name | english | typ | beschreibung | validierung | status |
| --- | --- | --- | --- | --- | --- | --- |
| P1 | 🔵 Kennung `✱` | `id` | UUID | Eindeutige interne Identität des Quellen-Snapshots. | Eindeutig | bestätigt |
| P2 | 🔵 Typ `✱` | `type` | Enum | Art der erfassten Kennzahl, etwa Downloads, Bewertung, Bewertungsanzahl, Stars oder Forks. | Zulässiger Typ | bestätigt |
| P3 | 🔵 Wert `✱` | `value` | Decimal | Von der Quelle gemeldeter Kennzahlenwert. | Nicht negativ | bestätigt |
| P4 | 🔵 Erfasst am `✱` | `capturedAt` | DateTime | Zeitpunkt der Erfassung oder Übernahme des Kennzahlenwerts. | Nicht in der Zukunft | bestätigt |
| R1 | 🟣 **Quelle** | `source` | n:1 Relation | Jeder Quellen-Snapshot gehört genau einer Quelle. Eine Quelle kann mehrere Quellen-Snapshots über die Zeit besitzen. | – | bestätigt |
