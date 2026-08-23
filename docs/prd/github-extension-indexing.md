## Problem Statement

Extension Hub indexiert derzeit ausschließlich Extensions von extensions.gnome.org (EGO). Gute, nur auf GitHub veröffentlichte GNOME-Extensions fehlen deshalb. EGO-Downloads und -Bewertungen sind nicht mit GitHub-Signalen vergleichbar, dürfen GitHub-Einträge aber auch nicht im gemeinsamen Ranking benachteiligen.

## Solution

Extension Hub importiert qualifizierte GitHub-Extensions zusätzlich zu EGO. Eine Extension ist ein gemeinsamer Eintrag, der mehrere Quellen haben kann; die UUID verhindert Dubletten. Ein quellenneutrales `score` steuert die allgemeine Sortierung. Die Oberfläche zeigt weiterhin nur die echten, quellspezifischen Kennzahlen.

## User Stories

1. Als Besucher möchte ich GitHub-only-Extensions finden, damit das Verzeichnis vollständiger ist.
2. Als Besucher möchte ich sehen, ob eine Extension von EGO, GitHub oder beiden Quellen stammt, damit ich die Herkunft einschätzen kann.
3. Als Besucher möchte ich GitHub-Stars und den letzten Release sehen, damit ich Aktivität ohne erfundene Bewertungen beurteilen kann.
4. Als Besucher möchte ich keine fehlenden GitHub-Ratings oder Downloads als Nullwerte sehen, damit die Angaben nicht irreführend sind.
5. Als Besucher möchte ich Extensions aus beiden Quellen in einer sinnvollen gemeinsamen Reihenfolge sehen.
6. Als Besucher möchte ich bei GitHub-Extensions direkt zum Repository oder zum installierbaren Release gelangen.
7. Als Betreiber möchte ich nur öffentliche, nicht archivierte Repositories ab fünf Stars importieren, damit der Index nicht mit Test- und Karteileichen-Repositories gefüllt wird.
8. Als Betreiber möchte ich Icons und Screenshots direkt von GitHub referenzieren, damit kein eigener Bildspeicher nötig ist.
9. Als Besucher möchte ich den Jahresverlauf der EGO-Downloads oder GitHub-Stars sehen, damit ich die Entwicklung einer Extension innerhalb ihrer Quelle einschätzen kann.

## Implementation Decisions

- GitHub-Repositories werden über die GitHub-API gesucht und anschließend validiert.
- Aufnahmebedingungen: öffentlich, nicht archiviert, mindestens fünf Stars, gültige `metadata.json`, eindeutige UUID und mindestens eine deklarierte GNOME-Shell-Version.
- Ein Release-ZIP ist für einen installierbaren Eintrag bevorzugt. Ohne Release kann der Eintrag ausschließlich auf das Repository verweisen.
- Das Datenmodell trennt den kanonischen Extension-Eintrag von seinen Quellen. Eine Quelle enthält Typ (`ego` oder `github`), externe Kennung, URLs, Aktualitätsdaten und quellspezifische Metadaten.
- Kennzahlen werden je Quelle als Zeitreihe gespeichert. EGO liefert insbesondere Downloads, Ratings und Bewertungsanzahl; GitHub liefert mindestens Stars, Forks, letzten Commit und letzten Release.
- `score` wird beim Erstellen des öffentlichen Snapshots aus normalisierten, quelleninternen Signalen für Popularität und Aktualität berechnet. Rohwerte verschiedener Quellen werden nicht direkt verglichen.
- Der Snapshot enthält den Gesamtwert sowie seine Komponenten zur nachvollziehbaren Sortierung. Die UI zeigt dagegen die echten Quellenwerte, etwa EGO-Rating oder GitHub-Stars.
- Bilder werden direkt von GitHub referenziert. Der Import übernimmt nur erreichbare, begrenzte Bild-URLs aus bekannten Repository-Dateien oder README-Angaben; bei fehlenden Bildern greift die UI auf einen Platzhalter zurück.
- Externe SVGs werden nicht ungeprüft als Bildquelle übernommen; zulässige Bildformate und Größen sind beim Import festzulegen.
- GitHub-API-Antworten werden mit ETag gecacht. Ein serverseitiger Token ermöglicht ausreichende Rate Limits für regelmäßige Aktualisierungen.

## Testing Decisions

- Tests prüfen beobachtbares Importverhalten: Aufnahme und Ausschluss anhand der Kriterien, UUID-basierte Zusammenführung von EGO und GitHub sowie Snapshot-Felder.
- Score-Tests prüfen feste Eingabedaten und Quellen-normalisierte Rangfolgen, nicht interne Berechnungsschritte.
- Tests prüfen, dass fehlende GitHub-Ratings und -Downloads im Snapshot und in der Darstellung nicht als Werte ausgegeben werden.
- Tests prüfen die Auswahl von Release-URLs sowie die sichere Begrenzung von Bild-URLs.
- Tests prüfen den Jahresverlauf aus Quellenmetriken, ohne EGO-Downloads und GitHub-Stars in einer gemeinsamen Datenreihe zu vermischen.

## Out of Scope

- Eigene Nutzerbewertungen für GitHub-Extensions.
- Eigener Bild-Proxy oder lokaler Bildspeicher.
- Vollständiger Crawl aller GitHub-Repositories ohne Such- und Qualitätskriterien.
- Automatische Installation von Extensions außerhalb der durch GitHub bereitgestellten Release-Datei.

## Further Notes

- Beispielkandidat: `Plyply99/Plaid` mit UUID `plaid@plyply99`, GitHub-Release-ZIP, Logo und Screenshot-Dateien.
- Die Score-Gewichte sind zunächst konfigurierbar zu halten und nach realen Indexdaten zu kalibrieren.
