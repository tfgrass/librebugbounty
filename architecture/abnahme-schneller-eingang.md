# Abnahme – schneller Einzeleingang

Stand: 2026-10-03. Der Nutzer hat dieses begrenzte Vorhaben nach einer erneut
blockierenden realen Eingabe ausdrücklich beauftragt. Implementierung sowie
isolierte Integrations- und Browserprüfungen sind abgeschlossen.

## Revidierte Entscheidung und Ziel

Die in Arbeitsabschnitt 2 getroffene Teilentscheidung, jeden neuen UI-Eingang
unmittelbar und synchron headless zu prüfen, ist ausdrücklich ersetzt. Der
technische Vorgang konnte den HTTP-Request bis zum 120-Sekunden-Timeout blockieren.
Auf Seiten mit sehr vielen `alert()`-Aufrufen konnte die Prüfung zudem nicht
rechtzeitig zu Ende kommen. Der Fall und sein Screenshot-Auftrag waren zu diesem
Zeitpunkt bereits gespeichert, die Oberfläche blieb dennoch belegt.

Für den Eingang hat deshalb die bestätigte dauerhafte Speicherung Vorrang:

```text
einzelne URL
    -> Finding + erster ScreenshotJob atomar speichern
    -> Speicherung bestätigen und Formular wieder freigeben
    -> Screenshot-Worker verarbeitet den persistenten Auftrag separat
```

Der Intake startet weder synchron noch automatisch einen Retest. Es wird auch
keine neue Retest-Queue eingeführt. Manuelle und andere ausdrücklich ausgelöste
Retests bleiben verfügbar. Ein Statusabruf liest ausschließlich bereits
persistierte Zustände.

## Vereinbarter Abnahmevertrag

- Das klassische `POST /findings` und `POST /api/findings` verwenden denselben
  Speicherfall und den CSRF-Zweck `finding_create`.
- Ein neues Finding und sein erster ScreenshotJob werden gemeinsam committed.
  Schlägt das Queueing fehl, bleibt kein neues Finding ohne Initialauftrag zurück.
- Der Request kehrt nach dem Commit zurück, ohne Screenshot-Aufnahme,
  Browser-Retest oder RetestRun abzuwarten beziehungsweise zu starten.
- `POST /api/findings` antwortet bei einer neuen Speicherung mit HTTP 201 und bei
  einem exakten Duplikat mit HTTP 200. Finding-ID, Detail-Link und aktueller
  persistierter Zustand sind in beiden Antworten enthalten.
- Validierungsfehler antworten mit HTTP 422, ein ungültiges CSRF-Token mit HTTP
  403. Unerwartete interne Fehler geben keine internen Exceptiondetails aus.
- `GET /api/findings/status` akzeptiert höchstens 50 eindeutige gültige UUIDs,
  unterscheidet gefundene und fehlende IDs und verändert keine Daten. Der Zustand
  trennt manuelle Bewertung, letzte gespeicherte technische Beobachtung,
  Kontaktzeitpunkt und Screenshotzustand.
- Der Screenshotzustand unterscheidet `none`, `queued`, `running`, `available`,
  `failed` und `discarded`. `available` setzt eine tatsächlich lesbare Datei
  voraus; ein fehlender Dateibeleg bleibt als Fehler sichtbar.
- Nach bestätigter Speicherung werden URL und Notiz nur geleert, wenn sie während
  des Requests nicht bereits verändert wurden. Ohne begonnene Folgeeingabe kehrt
  der Fokus ins URL-Feld zurück; eine bereits aktive Bearbeitung behält ihn.
- Verlauf und Entwurf der aktuellen Tabsitzung liegen in `sessionStorage` und sind
  auf 50 Einträge begrenzt. Fehlgeschlagene und nicht bestätigte Eingaben bleiben
  erhalten. Erneutes Absenden erfordert einen bewussten Klick.
- Toasts erscheinen nur im sichtbaren, fokussierten Tab. Beim Reload werden alte
  Zustände wiederhergestellt, ohne sämtliche Meldungen erneut als Toast auszugeben.
- Die JSON-Grenze ist zunächst für die klassische und die geplante parallele
  Studio-Oberfläche bestimmt. Weitere Clients gehören nicht zum Vorhaben.

## Prüffälle

| ID | Prüffall | Erwartetes Ergebnis | Nachweisstand |
| --- | --- | --- | --- |
| SE01 | Neue kontrollierte lokale URL über beide POST-Wege speichern | Finding und genau ein initialer ScreenshotJob sind gemeinsam vorhanden; klassische Route leitet weiter, API antwortet 201. | Automatisiert und im isolierten Browser bestanden. |
| SE02 | Retest- und Screenshot-Browserclients beim Intake überwachen | Kein Clientaufruf und kein neuer RetestRun; die persistente Screenshot-Queue darf den Auftrag später unabhängig beanspruchen. | Automatisiert bestanden; Browserbestand nach fünf Eingaben: fünf Queue-Aufträge, null RetestRuns. |
| SE03 | Queue-Schreibfehler simulieren | API meldet einen generischen Fehler; das neue Finding wird mit dem Initialauftrag zurückgerollt. | Automatisiert bestanden. |
| SE04 | Exakte URL wiederholen | API antwortet 200 und verweist auf denselben Fall; Notiz und Historie bleiben erhalten, kein Retest startet. Ein historischer Fall ohne Auftrag erhält höchstens einen Reparaturauftrag. | Seriell und mit zwei parallelen Prozessen bestanden: genau eine Neuanlage und ein Duplikatergebnis. |
| SE05 | Ungültige URL und ungültiges/fehlendes CSRF-Token senden | HTTP 422 beziehungsweise 403; kein unvollständiger Fall und kein Browseraufruf. | Automatisiert bestanden. |
| SE06 | Status für mehrere vorhandene und eine fehlende UUID lesen | Gefundene Zustände und `missingIds` sind korrekt zugeordnet; GET verändert keine Zeile und ruft keinen Browserclient auf. | Automatisiert bestanden; zusätzlich gegen den laufenden Bestand rein lesend geprüft. |
| SE07 | Doppelte, ungültige und mehr als 50 Status-IDs senden | IDs werden eindeutig validiert; ungültige oder zu viele IDs ergeben eine verständliche 422-Antwort. | Automatisiert bestanden. |
| SE08 | Fünf kontrollierte lokale URLs einzeln in einem Tab erfassen | Nach jeder bestätigten Speicherung ist das Formular wieder nutzbar, ohne auf Screenshot oder technische Beobachtung zu warten; jede Zeile führt zum richtigen Fall. | Isolierter End-to-End-Browserlauf bestanden: fünfmal HTTP 201, fünf verschiedene Fall-IDs, langsamste Bestätigung 167 ms. |
| SE09 | Validierungsfehler und abgebrochene/zeitlich unbekannte Antwort erzeugen | Entwurf und Verlaufszeile bleiben erhalten; es gibt keinen automatischen zweiten POST, nur die bewusste Übernahme ins Formular. | Browserlauf mit simulierten Antworten bestanden; auch nach Reload insgesamt nur die zwei bewusst ausgelösten POSTs. |
| SE10 | Seite neu laden und zwei Tabs mit unterschiedlichem Fokus verwenden | Verlauf und Entwurf dieses Tabs bleiben sichtbar; alte Zustände erzeugen nach Reload keinen Toast, Statusänderungen nur im gerade sichtbaren und fokussierten Tab. | Browserlauf bestanden; Reload erhielt fünf Zeilen, ein neuer Tab begann leer, und ein simulierter nicht fokussierter Tab erhielt keinen Toast. Reale Zurücknavigation startete den Statusabruf neu; Chromium verwendete dabei keinen BFCache. |
| SE11 | Screenshotzustände `queued`, `running`, `available`, fehlende Datei, `failed` und verworfener Fall darstellen | Jede Verlaufszeile zeigt den persistierten Zustand des richtigen Findings; Polling startet keine Arbeit. | Service- und API-Tests bestanden; Browserwechsel von `queued` zu `available` blieb dem richtigen Fall zugeordnet. |
| SE12 | Bestehende Datenbank und Artefakte vor und nach lesenden Status-/Browserprüfungen vergleichen | Außer den bewusst angelegten lokalen Abnahmefällen entstehen keine Änderungen; bestehende Nutzdaten und Artefakte bleiben identisch. | Isolierte DB-Snapshots und Artefaktprüfungen bestanden. Der parallel aktiv genutzte Echtbestand wurde nur gelesen und wegen gleichzeitiger realer Eingaben nicht als statischer Vergleich verwendet. |

## Ausgeführte technische Prüfung

```bash
ddev exec vendor/bin/phpunit
ddev exec php bin/console lint:container
ddev exec php -l src/Controller/WebController.php
ddev exec php -l src/Controller/FindingIntakeApiController.php
ddev exec php -l src/Service/IntakeStatusService.php
node --check public/js/intake.js
git diff --check
```

Der vollständige Lauf bestand mit **155 Tests und 1.446 Assertions**. Container-
Lint, PHP- und JavaScript-Syntax sowie `git diff --check` waren erfolgreich. Beide
API-Routen waren registriert. Der isolierte End-to-End-Lauf verwendete eine eigene
SQLite-Datenbank und blockierte alle Requests außerhalb der lokalen Testanwendung;
nach fünf Eingaben enthielt sie fünf Findings, fünf wartende ScreenshotJobs, null
RetestRuns und null Evidence-Zeilen. Die Testdatenbank wurde anschließend entfernt.

Die vom Nutzer gemeldete Eingabe wurde ausschließlich im vorhandenen Bestand
nachgesehen, nicht erneut aufgerufen: Finding und Screenshot waren bereits
gespeichert; der damalige synchrone Retest endete erst nach rund zweieinhalb
Minuten mit `error`. Damit entspricht die beobachtete Blockade genau dem jetzt
entfernten Wartepfad.

Die Browserabnahme verwendet ausschließlich kontrollierte lokale Beispielseiten.
Externe Requests werden dabei blockiert; gespeicherte externe Ziel-URLs werden
nicht geöffnet. Für die fünf schnellen Eingaben genügt die bestätigte Speicherung.
Der spätere Screenshotstatus wird getrennt anhand lokaler Fixtures geprüft.

## Grenzen

- Der schnelle Eingang erzeugt keine neue technische Beobachtung. Bis zu einem
  ausdrücklich gestarteten Retest kann „Keine gespeicherte technische Beobachtung“
  der korrekte Zustand sein.
- Der serielle Screenshot-Worker kann weiterhin warten oder fehlschlagen. Das
  ändert die zuvor bestätigte Speicherung des Falls nicht.
- `sessionStorage` ist Bedienzustand eines Tabs, keine serverseitige Historie und
  keine Synchronisation zwischen Tabs, Browsern oder Geräten. Nur die jüngsten
  50 Eingaben werden dort gehalten.
- Bei einem Transportabbruch kann der Server bereits gespeichert haben. Deshalb
  heißt der lokale Zustand „nicht bestätigt“. Eine bewusst wiederholte identische
  Eingabe wird serverseitig als Duplikat aufgelöst.
- Der Statusendpunkt liefert einen aktuellen Snapshot. Er verspricht keine Events
  und startet keine fehlende Arbeit nachträglich.
- Die parallele Resolve-/Studio-Oberfläche ist noch nicht Teil dieses Vorhabens.
  Der schnelle Eingang stellt nur die dafür benötigten schmalen Speicher- und
  Leseschnittstellen bereit.
- Es ist keine Schema- oder Bestandsmigration erforderlich. Der noch offene
  vollständige N01-Nachweis aus einer frischen isolierten DDEV-Kopie bleibt ein
  eigener Betriebsnachweis.
