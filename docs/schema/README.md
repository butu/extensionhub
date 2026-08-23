# Domain Schema

**Beschreibung:** Dieses Schema beschreibt das fachliche Zielmodell des quellneutralen GNOME-Shell-Extension-Verzeichnisses. Es trennt den UUID-eindeutigen Verzeichniseintrag von den gelieferten Quelldaten und hält damit Ubiquitous Language und aktuellen Code vergleichbar. Es ist eine Entscheidungsgrundlage, keine Beschreibung der Persistenzstruktur.

## Legende

`✱` Pflichtfeld · 🔵 Eigenschaft · 🟣 Relation · `Vorschlag` unbestätigt · `bestätigt` vom User bestätigt

## Inventar

| deutsch | english | datei | klassifikation | evidenz | status |
| --- | --- | --- | --- | --- | --- |
| Extension | `Extension` | [extension.md](extension.md) | Entity | Glossar, `src/Entity/Extension.php`, GitHub-PRD | bestätigt |
| Quelle | `Source` | [source.md](source.md) | Entity | Glossar, `src/Entity/ExtensionSource.php`, GitHub-PRD | bestätigt |
| Quellen-Snapshot | `SourceSnapshot` | [source-snapshot.md](source-snapshot.md) | Entity | Glossar, `src/Entity/SourceMetricMeasurement.php`, GitHub-PRD | bestätigt |
| Kommentar | `Comment` | [comment.md](comment.md) | Entity | `src/Entity/ExtensionComment.php`, `ExtensionSnapshotBuilder.php` | bestätigt |
| Kompatibilität | `supportedShellVersions` | – | Rolle/Attribut | Glossar, `ExtensionSource.php` | ausgeschlossen |
| Score | `Score` | – | Value Object | Glossar, `ExtensionScoreCalculator.php` | ausgeschlossen |
| Score-Komponente | `ScoreComponents` | – | Value Object | Glossar, `ScoreComponents.php` | ausgeschlossen |
| Release-Asset | `ReleaseAsset` | – | Value Object | Glossar, `ReleaseAsset.php` | ausgeschlossen |
| öffentlicher Snapshot | `ExtensionSnapshot` | – | Ereignis/Prozess | Glossar, `ExtensionSnapshotBuilder.php` | ausgeschlossen |
| Quellen-Zusammenführung | `SourceMerge` | – | Ereignis/Prozess | Glossar, GitHub-PRD | ausgeschlossen |
| Download-Messung | `ExtensionDownloadMeasurement` | – | Rolle/Attribut | `ExtensionDownloadMeasurement.php`, Migration `Version20260817114339.php` | ausgeschlossen |
