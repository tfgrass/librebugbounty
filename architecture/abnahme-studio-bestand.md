# Abnahme: Studio-Bestand und kanonische Routen

Stand: 2026-10-03. Beauftragter Abschnitt nach „genau bau das so“.
[Festlegung und Datenfluss](studio-bestand-routen.md).

## Ergebnis

Schneller Studio-Eingang auf `/`, Studio-Liste auf `/findings`, Studio-Falldetail
auf `/findings/{id}`. Classic bleibt unter `/legacy` mit Details, Einstellungen
und Prioritätsexport. Alte Studio-, Settings-, Export- und gefilterte Root-Links
bleiben erreichbar und erhalten ihre Queryparameter. Funktionale POST-, API-
und Artefaktwege sind stabil. Eine Datenmigration war nicht nötig.

Die neue Suche findet wörtliche Teilstrings in Domain, Titel und vollständiger
URL und kombiniert sich mit den vorhandenen Filtern. Listen und Counts verwenden
dieselben Regeln in beiden Oberflächen. Listenkontext bleibt nach Öffnen,
Bewertung, Notiz und Kontakt erhalten. GET startet keine Browserarbeit.

## Automatisierte PHP-Abnahme

DDEV mit PHP 8.3.30; Testdatenbank und Artefakte sind isoliert.

- `ddev exec php vendor/bin/phpunit`: **181 Tests / 2.261 Assertions erfolgreich**.
- Fokussierte HTTP-/Bestandssuite: **64 Tests / 1.369 Assertions**.
- Repositorysuite: **10 Tests / 144 Assertions**.
- Symfony Container-Lint, PHP-/JavaScript-Syntax und `git diff --check` erfolgreich.

Neue Prüfungen in `StudioInventoryTest` decken kanonische und alte Routen,
Queryerhaltung über begrenzte Redirectketten, Filter-/Archiv-/Countparität,
Domain-/Titel-/URL-Suche, Paging sowie tatsächlich abgeschickte Formularwerte ab.
Repositorytests prüfen literale `%`, `_`, `!` und Backslashes, Großschreibung,
Kombination mit Kontakt, Count/Paging und skalare Projektion ohne Entityhydration.

Bewertungs-, Kontakt- und Notiz-POSTs tragen ausschließlich validierten internen
Listenkontext weiter. Ungültige skalare Ziele werden ignoriert; Arrays werden
vor einer Mutation mit HTTP 400 abgewiesen. CSRF und gemeinsam gespeicherte
Zustände sind geprüft. Snapshot- und Browserclient-Mocks weisen nach, dass die
lesenden Seiten keine Anwendungsdaten, Jobs, Evidence oder technische Läufe
verändern beziehungsweise starten.

Die Tests fanden eine Redirectschleife durch zwei konkurrierende Legacy-Routen
mit und ohne abschließenden Slash. Die redundante Slashroute wurde entfernt;
Symfony übernimmt die Canonicalisierung. Außerdem waren Rückmeldungen nach
nativer Studio-Eingabe unsichtbar. Der Eingang rendert jetzt sowohl Status als
auch Fehler mit HTML-Escaping.

## Isolierte Browserabnahme

`tests/Support/studio_inventory_browser_router.php` erstellt ausschließlich eine
frische bewachte `/tmp/librebugbounty-studio-inventory-*`-Datenbank samt eigenem
Cache, Log und Artefaktspeicher. Fixtures verwenden lokale `127.0.0.x`-URLs;
es läuft kein Screenshot-Worker. Playwright erlaubt nur den lokalen Testorigin.
Die Anwendungsdatenbank und reale gemeldete URLs werden dabei nicht benutzt.

`tests/browser/studio-inventory.cjs`: **16 Szenarien erfolgreich**.

- 1440×900, 960×900, 720×900, 640×900 und 390×844: sichtbare Suche und Falllinks,
  getrennte Zustände, Tastaturzugang und kein horizontaler Seitenscroll.
- Domain-/Titel-/URL-Suche mit `%`, `_` und `!`, kombinierte Filter,
  historisches Archiv, Duplikate, leere Treffer und Zählerlinks.
- Alte Studio-/Rootfilter-/Settings-/Exportlinks und gemeinsam lesender Classic.
- Native Suche und Detailrückkehr auch ohne JavaScript.
- Seite zwei öffnen, Notiz/Kontakt/Bewertung speichern und zur selben gefilterten
  Seite zurückkehren; gemeinsame Zustände anschließend in Legacy lesen.
- Entwurf mit URL, Kennzeichen und mehrzeiliger Notiz sowie Sitzungsverlauf über
  Eingang, Bestand und Classic erhalten.

Der vollständige Snapshot bleibt während der lesenden Prüfung unverändert.
Nach den ausdrücklichen Nutzeraktionen bleiben 31 Findings, drei vorhandene Jobs,
zwei technische Beobachtungen und null Evidence erhalten. Zwei manuelle
Bewertungseinträge entstehen durch die beiden ausdrücklich abgeschickten Urteile.
Der eine API-Intake ist eine exakte Wiederholung eines Falls mit vorhandenem Job.
Es entstehen keine neuen technischen Aufträge oder Beobachtungen.
Keine externen Requests, JavaScriptfehler oder internen Ressourcenfehler.

Die früheren Browser-Skripte wurden auf die kanonischen Pfade angepasst und
anschließend mit jeweils frischen isolierten Fixtures erneut ausgeführt:

- `studio-intake.cjs`: **20 Prüfungen**, fünf neue einzelne URLs nach 120–283 ms
  bestätigt gespeichert, fünf Jobs vorgemerkt, null RetestRuns/Evidence.
- `studio-detail.cjs`: **21 Prüfungen**, einschließlich fünf Fenstergrößen,
  lokaler Belege, Classic-Wechsel, nativer Schreibformulare und Rückkehr zum
  Eingang mit erhaltenem Entwurf. Sieben vorhandene Fälle, fünf Fixturejobs,
  eine Fixturebeobachtung und sechs Evidence bleiben erhalten; vier manuelle
  Urteile werden ausdrücklich gespeichert. Keine externen Requests oder
  JavaScript-/internen HTTP-Fehler.

Screenshots wurden visuell geprüft. Lokale, nicht versionierte Laufartefakte:
`var/studio-inventory-acceptance/`,
`var/studio-intake-canonical-acceptance/`,
`var/studio-detail-canonical-acceptance/`, jeweils mit `result.json`.
Zusätzlicher lesender HTTP-Smoke-Test auf `https://librebugbounty.ddev.site`:
Studio-Eingang und -Liste sowie Legacy-Übersicht, Einstellungen und Export
antworten mit HTTP 200; die alten Links erreichen ihre kanonischen Ziele.
Eine ungültige Studio-Fall-ID antwortet mit HTTP 404.

Alle eigens gestarteten Testserver sind gestoppt, ihre temporären Datenbanken
und Cachewurzeln gezielt entfernt. Der normale DDEV-Worker bleibt unangetastet.

## Grenzen

Diese Abnahme belegt Oberfläche, gespeicherte Fachzustände und Routenumstellung.
Die gespeicherten Fixturebeobachtungen sind keine neue technische Verifikation.
Der Screenshot-/Retestbetrieb wurde für diesen Abschnitt nicht geändert.

Einstellungen und Prioritätsexport behalten zunächst die klassische Darstellung.
Studio-Bestand und Falldetail erhalten aktuelle Daten beim nächsten GET; nur der
Eingang besitzt den vorhandenen lesenden Statusabruf. Der Rücksprung kann nach
einer Verwerfung auf eine inzwischen kleinere aktive Trefferliste führen; Paging
wird dann durch den gemeinsamen Lesedienst auf eine gültige Seite begrenzt.
ASCII-Groß-/Kleinschreibung ist geprüft; eine zusätzliche Unicode-Suchnormalisierung
ist nicht eingeführt. Review, Bildvergleich und Meldungsbearbeitung bleiben offen.
