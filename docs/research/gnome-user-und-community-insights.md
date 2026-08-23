# GNOME-Nutzer, Community und Extension-Entwickler

**Status:** draft

**Stand:** 2026-08-22
**Zweck:** Arbeitsgrundlage für Produkt-, Inhalts- und UX-Entscheidungen im
Extension Hub. Keine Marktstudie und keine vollständige Persona.

## Kurzfassung

Es gibt nicht den einen „Linux-GNOME-User“. Für den Hub sind drei Gruppen
relevant:

1. **Alltagsnutzer:innen** wollen einen ruhigen, verständlichen Desktop und
   vertrauenswürdige Erweiterungen, die ohne Spezialwissen funktionieren.
2. **Anpassende Nutzer:innen** ergänzen bewusst fehlende Arbeitsabläufe, etwa
   Tiling, Zwischenablage, AppIndicator oder Tastatur-Shortcuts. Für sie ist
   die Kompatibilität mit ihrer GNOME-Shell-Version wichtiger als ein langer
   Marketingtext.
3. **Extension-Autor:innen** brauchen auffindbare, korrekte Metadaten und
   faire Signale für Wartung und Kompatibilität. Sie arbeiten mit einem
   absichtlich mächtigen, aber nicht stabilen Extension-System.

Die belastbarste Produktkonsequenz lautet: **Kompatibilität, Wartungsstand und
Vertrauen zuerst sichtbar machen; Entdeckung und Detailtiefe danach.**

## Was GNOME wichtig ist

Die GNOME Human Interface Guidelines richten sich an GNOME-Anwendungen, nicht
direkt an diese Website. Ihre Grundhaltung ist dennoch ein sinnvoller Maßstab:

- **Menschen vor Fachwissen:** Software soll unterschiedliche Fähigkeiten,
  Kulturen und Geräte berücksichtigen und wenig Spezialwissen verlangen.
- **Einfachheit:** Eine Ansicht verfolgt eine klare Aufgabe; seltene Optionen
  werden schrittweise offengelegt.
- **Weniger Aufwand:** Erforderliche Schritte und zu merkende Informationen
  reduzieren; häufige Aktionen nah platzieren.
- **Rücksicht:** Fehler vorbeugen und Aufmerksamkeit nicht unnötig fordern.

Die GNOME Foundation ergänzt dies um Vertrauen, Vielfalt, Nachhaltigkeit und
freie Software als Projektwerte.

### Übertragung auf den Extension Hub

- Die aktuelle Shell-Version darf nicht geraten werden müssen: klar auswählen
  oder sinnvoll vorbelegen.
- Suchergebnis und Detailseite beantworten zuerst: „Läuft das bei mir? Wird es
  noch gepflegt? Woher kommt es?“
- Fachsignale in Alltagssprache erklären. Beispielsweise „für GNOME 48
  getestet“ statt nur `shell-version`.
- Warnungen konkret, aber nicht alarmistisch formulieren. Ein EGO-Review ist
  ein Sicherheits- und Stabilitätssignal, **keine Funktionsgarantie**.

## Was Extension-Nutzer:innen wiederkehrend brauchen

### Kompatibilität und Stabilität

GNOME-Erweiterungen ändern die laufende Shell. Ihre Metadaten enthalten die
vom Autor getesteten GNOME-Shell-Versionen. Nach Shell-Updates können
Erweiterungen deshalb nicht laden oder Teile des Desktops beeinträchtigen.
GNOME hat die Versionsprüfung ab GNOME 40 wieder aktiviert.

**Konsequenz:** Shell-Version, getestete Versionen und Zeitpunkt der letzten
Aktualisierung sind primäre Auswahlhilfen. Nicht unterstützte oder alte
Erweiterungen nicht still verstecken, sondern eindeutig einordnen.

### Zielgerichtete Lösung statt Stöbern

Community-Beiträge zeigen häufig konkrete Ausgangsprobleme: Tiling,
Zwischenablage, Panel-/AppIndicator-Verhalten, Shortcuts oder der Wechsel von
einem Tiling Window Manager. Das Verzeichnis ist damit vor allem ein Werkzeug
zum Finden einer passenden Lösung, nicht bloß ein Showcase.

**Konsequenz:** Aufgabenorientierte Kategorien, präzise Suche und echte
Kompatibilitätsfilter sind wertvoller als künstliche Popularitätslisten.

### Vertrauen, Privatsphäre und Nachvollziehbarkeit

Extensions laufen mit weitreichendem Zugriff auf die Shell. Die offizielle
Prüfung verlangt lesbaren Code, untersagt Telemetrie und setzt für
Zwischenablagenutzung eine Erklärung in der Beschreibung voraus. Sie prüft
jedoch nicht jede Funktion.

**Konsequenz:** Herkunft (EGO/GitHub), Review-Status, Lizenz, Repository- und
Issue-Link sowie besondere Berechtigungs-/Datensignale transparent darstellen.
Keine pauschalen Qualitäts- oder Sicherheitsversprechen daraus ableiten.

### Wartung als praktisches Qualitätsmerkmal

Insbesondere im Reddit-Ausschnitt ist der Wartungsstatus von Tiling-
Erweiterungen ein wiederkehrender Schmerzpunkt. Das ist kein quantitativer
Beweis für das gesamte Ökosystem, aber ein starkes Signal für den Hub:
„zuletzt aktualisiert“, aktuelle getestete Shell-Version und aktive Quelle
helfen bei der Entscheidung.

**Konsequenz:** Frische als transparentes Datensignal zeigen, nicht als
unbegründetes Urteil wie „beste“ oder „sicher“ ausgeben.

## Was GNOME-Entwickler:innen wichtig ist

- **Desktop-Sicherheit und -Stabilität:** Review priorisiert Schadcode- und
  Stabilitätsrisiken. Autor:innen müssen bei `enable()` sauber aufbauen und
  bei `disable()` Ressourcen, Signale und Objekte aufräumen.
- **Freiheit statt enger Plugin-API:** Extensions sind Patches. Eine allgemein
  stabile API würde nach GNOMEs Begründung nicht dieselbe Gestaltungsfreiheit
  ermöglichen; Brüche bei Releases bleiben daher ein strukturelles Risiko.
- **Wahrheitsgemäße Metadaten:** `shell-version` darf nur tatsächlich
  unterstützte stabile Releases (plus höchstens eine Entwicklungsversion)
  nennen. Ein Repository-Link ist für Fehlerberichte und Kontext vorgesehen.
- **Offenheit und überprüfbarer Code:** Kein obfuskierter Code, keine
  Telemetrie, GPL-2.0-kompatible Lizenz für abgeleitete Shell-Arbeit.
- **Erweiterung oder Anwendung:** Eine Extension soll die Desktop-Erfahrung
  verändern. Liegt die Hauptfunktion außerhalb der Shell, empfiehlt GNOME eher
  eine eigenständige Anwendung, etwa über Flathub.

### Konsequenz für den Hub

Der Hub sollte Autor:innen nicht durch erfundene Qualitätsrankings bestrafen.
Er sollte überprüfbare Herkunfts- und Kompatibilitätsdaten sauber abbilden,
veraltete Angaben kenntlich machen und die Grenzen seiner Signale erklären.

## Community- und Reddit-Signale

Reddit liefert qualitative Erfahrungsberichte, keine repräsentative
Nutzerforschung. r/gnome bildet vor allem engagierte, technisch versiertere
Nutzer:innen ab; einzelne Threads sind kein Produktentscheid allein.

| Wiederkehrendes Signal | Einordnung für den Hub |
| --- | --- |
| Tiling ist ein häufiges Thema bei Umsteiger:innen und Power-Usern. | Tiling als klare Aufgabe/Kategorie; Wartung und Shell-Kompatibilität prominent. |
| Nach GNOME-Releases suchen Menschen gezielt nach funktionierenden Erweiterungen. | Filter „kompatibel mit GNOME X“ und aktuelle Versionsabdeckung priorisieren. |
| Browser-basierte Verwaltung wurde historisch als Hürde empfunden; die Extensions-App und Extension Manager entschärfen dies heute. | Nicht von einer Browser-Installationshürde ausgehen; der Hub ergänzt bestehende Werkzeuge mit Suche und Entscheidungshilfe. |
| Nutzende kommen meist mit einem konkreten Bedarf zu einem Extension-Verzeichnis. | Direkte Suche, Filter und sachliche Detailinformationen vor Marketing/News priorisieren. |

## Arbeitsprinzipien für spätere Entscheidungen

1. **Zuerst den konkreten Nutzwert zeigen:** Aufgabe, unterstützte Shell-
   Version, Wartung und Quelle.
2. **Unsicherheit sichtbar machen:** „getestet für“, „zuletzt aktualisiert“
   und „EGO-reviewt“ sind Fakten mit Grenzen, keine Versprechen.
3. **Einfache Sprache mit fachlicher Tiefe auf Abruf:** Kurzfassung zuerst,
   technische Metadaten und Quellen auf Detailseiten.
4. **Power-User ernst nehmen, nicht als Normalfall setzen:** Tiling und
   Shortcuts gut auffindbar machen, ohne die Oberfläche zu überladen.
5. **Datensignale von Meinungen trennen:** Aktualisierungsdatum, unterstützte
   Versionen und Quelllinks sind beobachtbar; „gut“, „sicher“ oder „aktiv“
   benötigen erklärbare Kriterien.

## Quellen

### Primärquellen

- [GNOME Human Interface Guidelines – Design Principles](https://developer.gnome.org/hig/principles.html), abgerufen am 2026-08-22.
- [GNOME Foundation – Mission](https://foundation.gnome.org/), abgerufen am 2026-08-22.
- [GNOME JavaScript – Updates and Breakage](https://gjs.guide/extensions/overview/updates-and-breakage.html), abgerufen am 2026-08-22.
- [GNOME JavaScript – Extension Review Guidelines](https://gjs.guide/extensions/review-guidelines/review-guidelines.html), abgerufen am 2026-08-22.

### Qualitative Community-Quellen

- [r/gnome: From Qtile to GNOME: My Take After 4 Months](https://www.reddit.com/r/gnome/comments/1kepjir/) (2025-05-04), abgerufen am 2026-08-22.
- [r/gnome: Extension not working](https://www.reddit.com/r/gnome/comments/1k7uvym/) (2025-04-25), abgerufen am 2026-08-22.
- [r/gnome: Dynamic tiling WM extension](https://www.reddit.com/r/gnome/comments/1k19npz/) (2025-04-17), abgerufen am 2026-08-22.
- [r/gnome: Most visited GNOME site?](https://www.reddit.com/r/gnome/comments/105olgz/) (2023-01-07), abgerufen am 2026-08-22.
- [r/gnome: Extensions app replacement](https://www.reddit.com/r/gnome/comments/jiqd5v/) (2020-10-26), abgerufen am 2026-08-22.

## Offene Fragen

- Es fehlen repräsentative Daten zur Verteilung von Alltags- und Power-Usern.
- EGO-Downloadzahlen, Installationsdaten oder strukturierte Support-Anfragen
  wären besser geeignet, um Bedürfnisse zu gewichten.
- Vor einem Ranking-Feature müssen Kriterien für „aktuell“ und „gewartet“
  produktseitig definiert werden.
