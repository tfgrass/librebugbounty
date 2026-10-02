# Abnahme – Studio-Ingest v1

Stand: 2026-10-03. Bezugsstand: Umsetzung nach dem
[Half-Screen-Plan](plan-studio-ingest.md). Der anschließende Nutzeraufruf von
`/studio` zeigte 404, weil zuvor ausschließlich der Plan vorbereitet war.
Der erste parallele Studio-Arbeitsbereich ist jetzt implementiert.

## Gelieferter Umfang

- GET `/studio` liefert ein vollständiges, eigenständiges dunkles PHP-Template.
  `/studio/` führt zum kanonischen Einstieg. Die klassische Oberfläche bleibt
  unter `/` erreichbar; beide enthalten funktionierende Wechsel-Links.
- URL und Erfassen sind der primäre Arbeitsfluss; Kennzeichen und Notiz liegen
  unter Details. Enter im URL-Feld sendet, Enter in der Notiz bleibt ein Umbruch.
- Kompakte Verlaufszeilen zeigen Domain, ursprüngliche URL, bestätigte Speicherung
  beziehungsweise Duplikat/Fehler/unbestätigten Ausgang und den Screenshotstand.
  Fall-Links öffnen die vorhandene klassische Detailansicht.
- Beide Renderer nutzen einmalig dieselbe Transport-, Session-, Retry- und
  Pollinglogik in `public/js/intake.js`. Der Root legt Ansicht, Endpunkte und
  Session-Key fest. Classic und Studio teilen Entwurf und maximal 50 Zeilen
  innerhalb desselben Tabs.
- Zurück-/Vorwärtsnavigation und BFCache-Wiederherstellung lesen den gemeinsamen
  Sessionstand neu ein, damit auch die native Formularwiederherstellung keinen
  veralteten Entwurf zurückbringt. Abgebrochene alte Requests dürfen anschließend
  weder den neuen Entwurf noch den Verlauf überschreiben. Ein unbestätigter POST
  wird nie automatisch erneut gesendet.
- Das Studio verwendet isolierte Styles. Der Verlauf scrollt im Desktopfenster
  separat; Mobilansichten und kleine Fensterhöhen erhalten passenden Seitenscroll.
  Lange URLs begrenzen nicht die Fensterbreite. Desktop-Toasts bleiben im
  Verlaufsbereich; unter 480 Pixeln sitzen sie am unteren Fensterrand.

Es gibt keine neue Migration, keinen neuen fachlichen API-Endpunkt und keine
zusätzliche Hintergrundprüfung. Der bereits vereinbarte Speichervertrag bleibt
unverändert: Finding und erster ScreenshotJob gemeinsam, Rückkehr nach Commit,
kein automatischer Retest beim Eingang.

## Nachweise

| Prüffall | Ergebnis |
| --- | --- |
| Studio-GET, Slash-Weiterleitung, 405 bei POST, gemeinsame CSRF-Semantik | Sechs HTTP-Integrationstests bestanden. |
| GET verändert weder Daten noch Artefakte und ruft keine Browserclients auf | DB-/Dateisnapshots und `never`-Mocks bestanden. |
| Konfiguriertes Kennzeichen und HTML-Escaping | Default, leeres Default-Feld und HTML-enthaltende Eingabe bestanden. |
| Vollständige PHPUnit-Suite | 161 Tests, 1.513 Assertions erfolgreich. |
| Fünf echte Eingaben per Enter | Fünf HTTP-201-Antworten, höchstens 104 ms bis zur bestätigten Speicherung; fünf Findings und fünf wartende ScreenshotJobs, keine Retests oder Evidence. |
| Half-Screen-Geometrie und visuelle Browserbilder | 640/720/960 × 900: fünf vollständige Zeilen und sichtbare Eingabe, kein horizontaler Überlauf. 390 × 844 ebenfalls erfolgreich. |
| Duplikat, Validierung, CSRF und Transportabbruch | Duplikat, echte 422/403, kein automatischer Retry, genau ein POST beim bewussten erneuten Erfassen bestanden. |
| Entwurf, Sitzungsverlauf und Navigation | Reload, unabhängiger neuer Tab, Classic ↔ Studio und echte Zurück-/Vorwärtsnavigation erfolgreich. |
| Fokus, Toasts und Tastatur | Unveränderte Polls erhalten DOM und Fokus; Toast überdeckt die Eingabe nicht; Enter in Notizen bleibt ein Umbruch. |

Der isolierte Browserlauf bestand alle 20 Prüfungen. Insgesamt gab es zwölf
beabsichtigte POST-Versuche einschließlich Fehlerfällen und klassischer
Regression. Die fünf Speichervorgänge dauerten 104, 47, 92, 88 und 65 ms. Es gab
keine externen Requests und keine JavaScript-Ausnahmen. Testserver und temporäre
Datenbank wurden anschließend entfernt.

Der tatsächliche DDEV-Aufruf von `/studio` lieferte HTTP 200. Bei `/studio/`
entfernt Nginx den abschließenden Slash mit HTTP 301; die Symfony-Route selbst
ist im Integrationstest mit ihrer HTTP-308-Weiterleitung geprüft.

Container-Lint für die normale und die Testumgebung, PHP-/JavaScript-Syntax und
`git diff --check` waren erfolgreich. Der unabhängige Review fand keine
blockierende Regression im gemeinsamen Zustand, Lebenszyklus, CSRF-/Text-Escaping
oder in der Trennung von klassischer und Studio-Darstellung.

## Wiederholbare Browserabnahme

`tests/browser/studio-intake.cjs` verwendet den vorhandenen Playwright-Sidecar.
`tests/Support/studio_browser_router.php` benötigt eine neue, isolierte
`/tmp/librebugbounty-studio-*`-Wurzel und läuft ausschließlich als eigener
PHP-Testserver. Die Startbefehle stehen im Browser-Skript. Der Router besitzt eine
rein lesende Zählhilfe, ist kein Bestandteil der Produkt-Routen und verhindert
versehentliche Nutzung der realen Datenbank.

Der Lauf erzeugt lokale Beispiel-URLs, blockiert Requests außerhalb des
Testorigins und führt keinen Screenshot-Worker gegen die Abnahmedatenbank aus.
Browserbilder und Ergebnis-JSON entstehen unter `var/studio-acceptance/` und
lassen sich mit demselben Lauf neu erzeugen.

## Grenzen

Im Browserlauf war bei echter Zurück-/Vorwärtsnavigation kein BFCache aktiv.
Der zusätzliche BFCache-Zweig wurde mit einem gezielten `pageshow.persisted`-
Ereignis geprüft. Unterdrückte Toasts bei unfokussierter Seite wurden über ein
`document.hasFocus()`-Fixture geprüft, weil Headless-Chromium jede gesteuerte
Seite als fokussiert behandelt; tatsächlicher Betriebssystem-Tabfokus ist damit
nicht nachgewiesen. Die gemessenen Antwortzeiten gelten für den lokalen,
isolierten Abnahmelauf.

Der erste Studio-Arbeitsbereich umfasst den Eingang. Bestand, Fallbearbeitung,
Bewertung, Einstellungen und Belegdarstellung wechseln weiterhin in die klassische
Anwendung. Eine Studio-Reviewseite, ein Inspector, Bildvergleich und Meldungen
bleiben gesonderte spätere Vorhaben. Der Sitzungsverlauf ist Bedienzustand eines
Tabs; er ist keine dauerhafte serverseitige Historie oder Gerätesynchronisation.
