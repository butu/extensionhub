# Ubiquitous Language

## Extension-Verzeichnis

| Begriff | Definition | Englischer Alias (Code) | Zu vermeidende Aliase |
| --- | --- | --- | --- |
| **Extension** | Die UUID-eindeutige Repräsentation eines GNOME-Shell-Erweiterungsprodukts im Verzeichnis, unabhängig von seinen Veröffentlichungsquellen. | `Extension` | Eintrag, Repository-Extension, kanonische Extension |
| **Quelle** | Die Anbindung einer Extension an einen externen Anbieter samt Kennung, URLs und Metadaten. | `Source` | Source, Plattform, Herkunft, Provider |
| **Quellkennung** | Die bei einer Quelle eindeutige externe Kennung einer Extension. | `ExternalIdentifier` | ID, externe ID |
| **Quellen-Zusammenführung** | Das Verbinden von Quellen mit derselben GNOME-Shell-UUID zu einer Extension. | `SourceMerge` | Deduplizierung, Sync |

## Sichtbarkeit und Qualität

| Begriff | Definition | Englischer Alias (Code) | Zu vermeidende Aliase |
| --- | --- | --- | --- |
| **Score** | Ein quellenneutraler Sortierwert für die allgemeine Auffindbarkeit einer Extension. | `score` | Ranking, Beliebtheit |
| **Score-Komponente** | Ein nachvollziehbarer Teilwert des Scores für Popularität oder Aktualität. | `scoreComponents` | Metrik, Faktor |
| **Quellen-Snapshot** | Ein zeitlich erfasster Kennzahlenwert einer Quelle. | `SourceSnapshot` | Quellenmetrik, globale Metrik, Rating |
| **Kompatibilität** | Die deklarierte Unterstützung mindestens einer GNOME-Shell-Version durch eine Extension. | `ShellCompatibility` | Shell-Version |

## Veröffentlichung und Darstellung

| Begriff | Definition | Englischer Alias (Code) | Zu vermeidende Aliase |
| --- | --- | --- | --- |
| **Release-Asset** | Eine von GitHub veröffentlichte Datei eines Releases, etwa ein installierbares ZIP-Archiv. | `ReleaseAsset` | Download, Release-ZIP |
| **öffentlicher Snapshot** | Die versionierte Datenexportdatei, aus der die clientseitige Verzeichnisoberfläche Extensions rendert. | `ExtensionSnapshot` | API, Feed, JSON |
| **GitHub-only-Extension** | Eine kanonische Extension, die ausschließlich eine GitHub-Quelle und keine EGO-Quelle besitzt. | `GitHubOnlyExtension` | reine GitHub-Extension |

## Beziehungen

- Eine **Extension** hat mindestens eine **Quelle**.
- Eine **Quelle** gehört zu genau einer **Extension** und besitzt genau eine **Quellkennung**.
- Eine **Quelle** besitzt null oder mehrere **Quellen-Snapshots**.
- Eine **Extension** wird durch ihre GNOME-Shell-UUID bei der **Quellen-Zusammenführung** identifiziert.
- Der **öffentliche Snapshot** enthält eine Extension mit ihren sichtbaren Quelleninformationen und dem **Score**.

## Beispiel-Dialog

> **Entwickler:** „Hat Plaid als GitHub-only-Extension schon eine **kanonische Extension**?"
>
> **Fachbereich:** „Ja, sobald seine UUID validiert ist. Die GitHub-Anbindung ist dann seine erste **Quelle**."
>
> **Entwickler:** „Wenn Plaid später auf EGO erscheint, legen wir also keine zweite Extension an?"
>
> **Fachbereich:** „Genau. Wir führen beide **Quellen** über dieselbe UUID zusammen und zeigen ihre echten **Quellenmetriken** getrennt."
>
> **Entwickler:** „Sortiert wird trotzdem über den quellenneutralen **Score**?"
>
> **Fachbereich:** „Ja. Der **Score** ersetzt keine Quellenmetrik in der Oberfläche."

## Markierte Ambiguitäten

- **Extension** bezeichnet ausschließlich den UUID-eindeutigen Verzeichniseintrag. Ein GitHub-Repository oder ein EGO-Datensatz ist stets eine **Quelle**, keine zweite Extension.
- **Score** meint nur den quellenneutralen Sortierwert. GitHub-Stars, EGO-Downloads und EGO-Ratings stehen in **Quellen-Snapshots** und werden nicht quellenübergreifend als Rohwerte verglichen.
