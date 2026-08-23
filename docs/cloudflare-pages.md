# Cloudflare Pages einrichten

Cloudflare Pages liefert die statische Website aus. Der bestehende Server bleibt
für Datenbank, Import und die Erzeugung der drei JSON-Snapshots zuständig.

```text
Bestehender Server                 GitHub Actions / Cloudflare Pages
------------------                 ---------------------------------
Nächtlicher URL-Cron               committed dist/ auschecken
JSON-Snapshots erzeugen    --->     aktuelle JSONs herunterladen
                                    vollständiges dist/ deployen
```

Nachts laufen kein npm-, Composer-, PHP- oder Twig-Build. Der Workflow ersetzt nur
die JSON-Dateien im bereits gebauten `dist/` und lädt das vollständige Verzeichnis
mit Cloudflares offiziellem Wrangler-Client hoch.

## 1. Snapshot-Origin vorbereiten

Die GitHub Action benötigt auch nach der DNS-Umstellung eine dauerhaft erreichbare
Adresse zum bestehenden Server. Geeignet ist beispielsweise:

```text
https://origin.example.com/data
```

Unter dieser Basis-URL müssen ohne Anmeldung erreichbar sein:

```text
extensions.json
extensions.v2.json
comments.json
```

Der Origin-Hostname muss auf den bestehenden Server zeigen und darf später nicht
auf die Cloudflare-Pages-Site umgestellt werden. Alternativ kann ein technischer
Hostname des Hosters verwendet werden.

Vor dem Fortfahren alle drei URLs im Browser aufrufen. Erwartet werden HTTP 200 und
JSON-Inhalte. `extensions.json` und `extensions.v2.json` müssen byte-identisch sein.

## 2. Pages-Projekt einmalig anlegen

Wrangler wird nur für die einmalige Einrichtung lokal und später automatisch in
GitHub Actions verwendet. Es wird nicht auf dem Produktionsserver installiert.

```bash
npx wrangler login
npx wrangler pages project create extensionhub --production-branch master
```

Nach dem Browser-Login legt der zweite Befehl das Direct-Upload-Projekt `extensionhub`
mit `master` als Production Branch an. Der Projektname kann abweichen, muss dann
aber identisch als GitHub-Variable konfiguriert werden.

## 3. Cloudflare API-Token erstellen

1. Cloudflare Dashboard öffnen.
2. **My Profile → API Tokens → Create Token → Create Custom Token** öffnen.
3. Berechtigung **Account → Cloudflare Pages → Edit** vergeben.
4. Unter **Account Resources** nur den vorgesehenen Cloudflare-Account auswählen.
5. Token erstellen und einmalig kopieren.
6. Die Account-ID auf der Übersichtsseite des Cloudflare-Accounts kopieren.

Token und Account-ID niemals in Dateien oder Commits eintragen.

## 4. GitHub konfigurieren

Im GitHub-Repository **Settings → Secrets and variables → Actions** öffnen.

Unter **Secrets** anlegen:

| Name | Inhalt |
|---|---|
| `CLOUDFLARE_API_TOKEN` | API-Token aus Schritt 3 |
| `CLOUDFLARE_ACCOUNT_ID` | Cloudflare Account-ID |

Unter **Variables** anlegen:

| Name | Beispiel |
|---|---|
| `CLOUDFLARE_PAGES_PROJECT` | `extensionhub` |
| `SNAPSHOT_BASE_URL` | `https://origin.example.com/data` |

`SNAPSHOT_BASE_URL` darf keinen abschließenden Slash enthalten. Der Projektname darf
nur Kleinbuchstaben, Ziffern und Bindestriche enthalten und muss alphanumerisch
beginnen und enden.

## 5. Statische Site erzeugen

Das Repository enthält bereits den Command:

```bash
ddev exec php bin/console app:build-static-site
```

Er erzeugt `dist/` mit:

```text
dist/
├── index.html
├── 404.html
├── _headers
├── _redirects
├── build/
└── data/
    ├── extensions.json
    ├── extensions.v2.json
    └── comments.json
```

`dist/` wird zusammen mit Codeänderungen committed, mit Ausnahme von
`dist/data/*.json`: Diese drei Dateien werden lokal generiert, aber nicht
committed, weil `.gitignore` `dist/data/` ausschließt. Der Deploy-Workflow lädt
vor jedem Deployment aktuelle Kopien vom Snapshot-Origin herunter (siehe
Abschnitt 1 „Snapshot-Origin vorbereiten“).
Der Command muss erneut laufen, wenn sich Twig, JavaScript, CSS oder die
gebauten Vite-Dateien ändern. Reine JSON-Aktualisierungen benötigen keinen
lokalen Build und müssen nicht committed werden.

## 6. Erstes Deployment testen

Nach dem Push auf `master`:

1. In GitHub **Actions → Deploy Cloudflare Pages** öffnen.
2. **Run workflow → master → Run workflow** wählen.
3. Warten, bis der Job **Deploy dist/ to Cloudflare Pages** erfolgreich ist.
4. Die von Cloudflare angezeigte `*.pages.dev`-Adresse öffnen.
5. Startseite und eine direkte `/extension/{pk}/{slug}`-URL prüfen.

Der Workflow validiert vor dem Upload:

- alle drei Dateien sind parsebares JSON;
- `extensions.json` und `extensions.v2.json` sind byte-identisch;
- das Deployment enthält höchstens 20.000 Dateien;
- keine Datei ist größer als 25 MiB.

## 7. Nachtjob abstimmen

Der vorhandene URL-Cron auf dem Server bleibt unverändert und erzeugt nur die JSONs.
Die GitHub Action läuft täglich um 03:17 UTC (`17 3 * * *`). Der Server-Cron muss
vorher vollständig beendet sein. Falls der Import später läuft oder länger dauert,
muss der Zeitplan in `.github/workflows/deploy-cloudflare-pages.yml` verschoben
werden.

Die nächtlich geladenen JSON-Dateien werden nicht zurück in Git committed. Sie
existieren nur im jeweiligen Cloudflare-Pages-Deployment.

## Fehlerdiagnose

| Fehler | Prüfung |
|---|---|
| `SNAPSHOT_BASE_URL is empty` | GitHub-Variable fehlt |
| Download liefert 404/403 | Origin-Hostname, Pfad oder Zugriffsschutz prüfen |
| JSON validation failed | Nachtjob und erzeugte Dateien auf dem Server prüfen |
| Pages project name is invalid | GitHub-Variable und Cloudflare-Projektname vergleichen |
| Wrangler meldet fehlende Berechtigung | API-Token und Account-Auswahl prüfen |
| Direkte Detail-URL funktioniert nicht | `dist/_redirects` und Pages-Deployment prüfen |
