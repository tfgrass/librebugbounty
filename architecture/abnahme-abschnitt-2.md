# Abnahme Arbeitsabschnitt 2

Stand: 2026-10-02. Abschnitt 2 wurde mit einer persistenten, seriellen
Screenshot-Warteschlange umgesetzt und in der laufenden DDEV-Installation mit
kontrollierten lokalen Seiten geprüft. Die fachliche Browserprüfung und die
Belegaufnahme sind jetzt getrennte Vorgänge.

## Erreichtes Verhalten

Beim Erfassen einer neuen URL gilt folgende Reihenfolge:

1. Fall und erster persistenter Screenshot-Auftrag werden atomar in einer
   Doctrine-Transaktion gespeichert.
2. Die bestehende XSS-Prüfung läuft sofort und headless; sie erzeugt kein
   Screenshot-Bild.
3. Der durch DDEV gestartete Screenshot-Worker verarbeitet Aufträge später
   nacheinander mit sichtbarem Chromium.

Die HTTP-Antwort kann weiterhin auf die unmittelbare XSS-Prüfung warten, wartet
aber nicht mehr auf die sichtbare Aufnahme. Die historische Einstellung
`cron_only` wird für neue UI-Eingänge ignoriert und in den Settings nicht mehr
angeboten. Eine exakt bereits vorhandene URL wird nicht erneut angelegt oder
geprüft. Fehlt einem älteren Finding jede Screenshot-Job-Historie, ergänzt die
Duplikateingabe genau einen Auftrag; vorhandene aktive oder terminale Aufträge
bleiben unverändert.

Die Aufnahme ist unabhängig vom technischen Ergebnis. Ein sichtbarer Dialog wird
offen gehalten und zusammen mit der Seite aufgenommen. Ohne Dialog entsteht nach
dem Wartefenster ebenfalls ein Bild der Seite; das gilt insbesondere bei
`inconclusive`. Ein erfolgreicher Auftrag legt eine neue Evidence-Zeile an und
erhält frühere Bilder.

## Persistentes Auftragsmodell

Die Migration `Version20261002000000` ergänzt `screenshot_job`. Ein Auftrag
enthält die beim Einreihen gültige URL, seine Zeitpunkte, Versuchszahl,
Fehlerdiagnose, Bildpfad und Aufnahme-Metadaten. Seine Zustände sind:

```text
queued -> running -> available
                  -> failed
```

- `queued`: persistent gespeichert und noch nicht beansprucht.
- `running`: atomar vom Worker beansprucht; `attempts` wurde erhöht.
- `available`: Bild und Evidence-Metadaten wurden erfolgreich gespeichert.
- `failed`: Aufnahme oder Speicherung ist fehlgeschlagen; die Diagnose bleibt
  am Auftrag sichtbar.

`active_key` entspricht bei `queued` und `running` der Finding-ID und ist eindeutig.
Dadurch existiert auch bei konkurrierendem Einreihen höchstens ein aktiver Auftrag
pro Fall. Terminale Aufträge setzen den Schlüssel frei und bleiben als Historie
erhalten. Der Claim erfolgt atomar in Reihenfolge `requested_at, rowid`; `rowid`
entscheidet auch bei gleichem Sekundenstempel nach tatsächlicher Einfügung.

Das Einreihen prüft die Existenz des Findings innerhalb des Queue-Mutations-Locks
erneut. Der eigentliche Insert verwendet `INSERT ... SELECT` aus der live
vorhandenen Finding-Zeile. Wurde ein zuvor geladenes Entity inzwischen gelöscht,
entsteht trotz `PRAGMA foreign_keys=0` kein verwaister ScreenshotJob; der Aufruf
endet mit einer eindeutigen Fehlermeldung.

Ein beim Prozessende zurückgelassener `running`-Auftrag wird beim nächsten
Workerstart wieder `queued`. Nach drei unterbrochenen Versuchen wird er
stattdessen `failed`, damit spätere Aufträge nicht dauerhaft verhungern. Ein
gewöhnlicher Aufnahmefehler ist sofort terminal und kann über einen neuen Auftrag
erneut versucht werden.

## Browseraufnahme und Serialisierung

Der Node-Sidecar stellt neben dem bestehenden `POST /retest` einen neutralen
Endpunkt `POST /screenshot` bereit. Dieser öffnet Chromium headed auf einem
1440x900-Xvfb-Desktop und fotografiert den kompletten Desktop mit
`ffmpeg`/`x11grab`:

- `desktop-dialog`: Seite samt echtem, zu diesem Zeitpunkt offenem Browserdialog.
- `desktop-page`: sichtbare Seite, wenn innerhalb des Wartefensters kein Dialog
  aufgenommen wurde.

Der Endpunkt bewertet keine Schwachstelle. Er liefert Bild, tatsächlichen
Aufnahmezeitpunkt, finale URL, HTTP-Status, Dialoginformationen und
Aufnahmemethode. Der Symfony-Dienst validiert das Bild, schreibt es über die
konfigurierte `EvidenceStorageInterface` und speichert anschließend Evidence und
Auftragsmetadaten.

Die Verarbeitung besitzt mehrere klar getrennte Sperren:

- Der dauerhafte Symfony-Prozess besitzt ein nicht blockierendes Worker-Lock;
  ein zweiter `app:screenshot:worker` beendet sich mit Fehler.
- Ein Capture-Lock schützt den gesamten Claim-/Browser-/Speichervorgang.
- Ein Queue-Mutations-Lock serialisiert Einreihen gegenüber Löschung und Reset.
- Maintenance erwirbt beide Locks in fester Reihenfolge. Falllöschung, Reset und
  Startup-Recovery können daher nicht in eine laufende Aufnahme greifen.
- Der Node-Prozess besitzt zusätzlich eine FIFO-Anzeigelease. Sie schützt den
  gemeinsam genutzten Xvfb-Desktop auch dann, wenn ein anderer HTTP-Aufrufer eine
  sichtbare Browseroperation auslöst.

Die PHP-Locks liegen im gemeinsamen `/tmp` des DDEV-Web-Containers. Die
implementierte Garantie setzt den dokumentierten Betrieb mit genau einem
Web-Container voraus; sie ist kein verteilter Lock für mehrere Hosts oder
Container.

Die aktuelle SQLite-Verbindung meldet `PRAGMA foreign_keys=0`. Die Anwendung
verlässt sich bei der Falllöschung deshalb nicht auf das in der Migration
deklarierte `ON DELETE CASCADE`: `FindingService::deleteFinding()` entfernt aktive
und terminale ScreenshotJobs, Evidence und RetestRuns ausdrücklich vor dem Finding
und hält dabei den Maintenance-Lock. Direkte SQL-/ORM-Löschungen außerhalb dieses
Anwendungsfalls besitzen diese Garantie nicht.

## DDEV-Betrieb

Die Projektkonfiguration startet genau einen dauerhaften
`app:screenshot:worker --sleep=2` über Supervisor. Der Worker wartet beim ersten
Start auf die Datenbankmigration. Vor dem Claim prüft er bis zu 30 Sekunden
`GET /health` des Sidecars; ist dieser noch nicht bereit, bleibt der Auftrag
`queued` und Supervisor kann den Prozess erneut starten.

Der Health-Endpunkt prüft nur die Antwort des Node-HTTP-Prozesses. Er startet
keinen Browser und prüft Xvfb oder `ffmpeg` nicht. Der Sidecar hat außerdem keine
eigene Docker-Restart-Policy. Ein Ausfall dieser Komponenten nach dem Claim wird
daher als sichtbarer terminaler Aufnahmefehler behandelt.

Der Playwright-Container installiert seine festgeschriebenen Node-Abhängigkeiten,
startet Xvfb selbst, wartet auf dessen Unix-Socket und startet danach Node mit dem
passenden `DISPLAY`. Signale und Prozessende räumen Node und Xvfb gemeinsam auf.
Ein externer Cronjob ist für die normale Verarbeitung nicht erforderlich.

Das Playwright-Dockerimage, `package.json` und das versionierte
`package-lock.json` sind exakt auf `1.61.1` ausgerichtet; ein frischer Aufbau kann
damit `npm ci` verwenden. Weil die historisch genutzte `.env` ignoriert ist, setzt
`.ddev/config.yaml` außerdem `APP_SECRET`, die SQLite-`DATABASE_URL` und
`EVIDENCE_STORAGE_DIR` ausdrücklich für den Web-Container. Eine frische
DDEV-Kopie hängt damit für diese Grundkonfiguration nicht von lokalen,
unversionierten Dateien ab.

## Schutz fachlicher Daten

Der Screenshot-Pfad ruft keinen Retest auf. Vorher-/Nachher-Vergleiche der
isolierten Abnahmen belegen, dass Aufnahme und Aufnahmefehler folgende Werte nicht
ändern:

- `Finding.status` und `Finding.reviewState`
- Notizen und Kontakt-/Meldezeitpunkte
- `Finding.lastRetestedAt`
- vorhandene Retest-Läufe und deren Rohdaten
- frühere Evidence-Zeilen und Bilddateien

Schlägt das Speichern der Metadaten nach dem Dateischreiben fehl, wird die neue
Datei entfernt. Ein frischer Doctrine-Manager speichert den Auftrag danach als
`failed`; der dauerhafte Worker kann mit späteren Aufträgen fortfahren. Scheitert
auch das Entfernen, verweist die Diagnose ausdrücklich auf
`app:artifacts:audit`.

## Sichtbares Verhalten und Befehle

Die Detailansicht zeigt bis zu 20 Screenshot-Aufträge mit Status, Anforderungs-,
Start- und Aufnahmezeit, Versuchen und ausklappbarer Fehlerdiagnose. Bei
Queue-Bildern zeigt die Galerie den tatsächlichen Aufnahmezeitpunkt; historische
Bilder ohne diese Zuordnung bleiben als solche gekennzeichnet.

`app:screenshot:missing`, `app:screenshot:all` und `app:evidence:check` reihen nur
Aufträge ein. `app:review:refresh` führt seinen bestehenden headless Review aus
und reiht im zweiten Schritt fehlende Bilder ein. Die Queue-Befehle führen keine
Retests aus. `app:screenshot:worker --once` verarbeitet höchstens einen Auftrag
und dient der Diagnose oder einem kontrollierten Einzellauf. Im normalen
DDEV-Betrieb muss dafür der Supervisor-Worker vorher gestoppt und anschließend
wieder gestartet werden; sonst verhindert das Worker-Lock den zweiten Prozess.

Die `missing`-Auswahl bedeutet „keine Screenshot-Evidence-Zeile“. Eine vorhandene
Evidence-Zeile mit später fehlender Datei wird vom Artefakt-Audit gemeldet, aber
nicht automatisch durch diese Auswahl neu eingereiht.

## Durchgeführte Funktionsabnahmen

### Echte lokale Browseraufnahmen

Zwei kontrollierte lokale Seiten wurden über den realen DDEV-Sidecar aufgenommen
und visuell geprüft:

| Fixture | Ergebnis |
| --- | --- |
| Normale Seite ohne Dialog | Lesbares 1440x900-PNG, Methode `desktop-page`, HTTP 200 |
| Seite mit Alert | Lesbares 1440x900-PNG, Methode `desktop-dialog`; Seite und echter Alert mit Text `LOCAL DIALOG FIXTURE 20261002` sichtbar |

Die Aufnahme ohne Dialog belegt den für `inconclusive` benötigten allgemeinen
Seitenbeleg. Der Worker benötigt dafür keine positive XSS-Einstufung.

Nach dem abschließenden DDEV-Neustart endeten zwei gleichzeitig an den Sidecar
gesendete Aufnahmen mit dem Drei-Sekunden-Standardfenster nach 3,33 s
beziehungsweise 6,82 s. Damit wurde die tatsächliche serielle FIFO-Nutzung des
gemeinsamen Desktops erneut nachgewiesen.

### Persistente Queue auf isolierter Datenbank

Das Standard-Dialogfenster beträgt inzwischen drei Sekunden. Eine zusätzliche
50-ms-Nachlaufphase nach der normalen Desktopaufnahme verhindert, dass ein gerade
vom Playwright-Callback zugestellter Dialog beim Browserabbau übersehen wird.
Dialoghandler werden vor dem Teardown entfernt; ein fehlgeschlagener Dialog-Capture
wird nicht durch ein normales Seitenbild als Erfolg verdeckt.

Drei Aufträge wurden in Einfügereihenfolge verarbeitet. Der erste war vor dem
Workerstart künstlich `running` und wurde wieder eingereiht:

- Abschlussreihenfolge entsprach der Einfügereihenfolge.
- Versuchszahlen: `2, 1, 1`.
- Aufnahmemethoden: `desktop-page`, `desktop-dialog`, `desktop-page`.
- Drei lesbare 1440x900-PNGs und drei neue Evidence-Zeilen.
- Ein vorhandener `inconclusive`-Retest und sämtliche manuellen Felder aller
  Findings blieben unverändert.

Ein kontrollierter Capture-Fehler führte zu `failed`, sichtbarer Fehlermeldung,
keiner Evidence-Zeile und keiner zusätzlichen Datei. Das Finding blieb unverändert.

### Web-Eingang auf isolierter Datenbank

Eine neue lokale Dialog-URL wurde zusammen mit ihrem Auftrag atomar gespeichert,
unmittelbar headless als
`still_vulnerable` geprüft und mit `screenshotRequested=false` protokolliert. Vor
dem Worker gab es noch kein Screenshot-Evidence, aber einen `queued`-Auftrag. Der
Worker erzeugte anschließend ein echtes 1440x900-Dialogbild und setzte den Auftrag
auf `available`; Finding und RetestRun blieben dabei unverändert.

Ein zweiter identischer POST hinterließ genau einen Fall, einen Auftrag und einen
RetestRun. Die ursprüngliche Notiz blieb erhalten; die Oberfläche meldete das
Duplikat und leitete zur vorhandenen Detailansicht. Ergänzende automatisierte
Prüfungen belegen außerdem, dass ein vorhandener Altfall ohne jeden Job durch die
Duplikateingabe genau einen Auftrag erhält, während terminale Historie nicht durch
einen neuen Auftrag ersetzt wird.

### Bestandsmigration und Laufzeit

Vor der Migration wurde das in [backup.md](backup.md) dokumentierte Backup
`20261002T165845Z-d697f3b8` erstellt und wiederhergestellt. Anschließend wurde die
Migration in der verwendeten Installation erfolgreich angewendet:

- sieben Migrationsstände, neue leere Tabelle `screenshot_job`;
- Inhalte und Tabellenhashes aller fünf bestehenden Anwendungstabellen
  unverändert;
- vorhandene Artefakte und ihre Hashes unverändert;
- Homepage HTTP 200, Sidecar-Health HTTP 200 und leerer Screenshot-POST korrekt
  HTTP 400;
- Playwright-Container `healthy`, Supervisor meldete den Worker `RUNNING`, genau
  ein PHP-Workerprozess, Node mit gesetztem `DISPLAY`.

Nach Migration und Laufzeitprüfung wurde zusätzlich das Backup
`20261002T172248Z-293f5bb8` des migrierten Zustands erstellt und restore-validiert:
7 Migrationen, 4.692 Domains, 5.348 Findings, 8.016 Evidence-Zeilen, 8.896
RetestRuns, 0 ScreenshotJobs, 4 Settings und 4 Artefaktdateien. Die 382
historischen fehlenden Referenzen bleiben korrekt als fehlend ausgewiesen.

Der abschließende vollständige DDEV-Stop/Start nach allen Startup-Korrekturen war
erfolgreich. Die sieben Migrationen waren aktuell; der Worker wartete ohne
`BACKOFF`, wurde vom Post-start-Hook einmal sauber neu gestartet und blieb unter
Supervisor `RUNNING`. Danach liefen genau ein PHP-Worker sowie Node und Xvfb mit
`DISPLAY=:99`; Playwright war `healthy`, `/health` und Homepage antworteten mit
HTTP 200, ein leerer `/screenshot`-POST korrekt mit HTTP 400.

Normale Seite und echter Dialog wurden nach dem Neustart erneut als 1440x900-PNG
visuell geprüft; der Dialogtext war korrekt. Bereits gespeicherte isolierte Bilder
hatten vor und nach dem Neustart dieselben Hashes. Damit sind Startup-Schutz und
Persistenz in der verwendeten Installation belegt. Die strengere N01-Abnahme aus
einer vollständig frischen isolierten Projektkopie bleibt offen.

### Automatisierte Prüfungen

Nach den Intake- und Dialog-Race-Korrekturen wurden folgende Läufe erfolgreich
abgeschlossen:

| Prüfung | Ergebnis |
| --- | --- |
| PHPUnit im DDEV-Web-Container | 69 Tests, 395 Assertions erfolgreich |
| Gezielte Screenshot-Queue-Suite | 15 Tests, 100 Assertions erfolgreich |
| Backup-Suite | 10 Tests erfolgreich |
| Node-Suite | 7 Tests erfolgreich |
| Vollständige PHP-Syntax und Symfony-Container-Lint | Erfolgreich |
| Node-Syntax und `npm ci --dry-run` | Erfolgreich |
| `git diff --check` | Erfolgreich |

Queue-spezifisch wurden unter anderem atomare Deduplizierung, FIFO-Claim,
Readiness vor Claim, Recovery, der nach drei Unterbrechungen terminale Auftrag,
Datei- und Datenbankfehler, neutrale CLI-Befehle sowie Reset-/Löschsperren geprüft.
Ein Integrationstest löscht einen Fall mit aktivem und terminalem ScreenshotJob,
Evidence und RetestRun und belegt, dass alle abhängigen Zeilen explizit entfernt
werden. Eine weitere Regression lädt ein Finding, löscht es und weist nach, dass
das spätere Einreihen über diese veraltete Entity-Referenz keinen Job erzeugt.

## Zuordnung zu den Anforderungen

| Anforderung | Ergebnis |
| --- | --- |
| F01 Speicherung unabhängig von Aufnahme | Finding und Initialauftrag atomar; Browser-Prüffehler lassen beide bestehen. Exaktes Duplikat erhält Nutzerdaten und repariert nur vollständig fehlende Job-Historie. |
| F03 Bild mit/ohne Dialog und bei `inconclusive` | Mit echten lokalen Seiten nachgewiesen; Auftrag, Fehler und Aufnahmezeit sind persistent sichtbar. |
| F04 konsistente Datei-/Metadatenbehandlung | Über Storage-Grenze, Fehlerpfade und Artefakt-Audit geprüft. |
| F05 Historie schützen | Frühere Bilder bleiben erhalten; Queue und Worker verändern keine manuellen Felder. |
| F07 verständliche Fehler | Queue- und Capture-Fehler stehen separat in Eingang und Detailansicht. |
| N01 DDEV-Betrieb | In der verwendeten Installation einschließlich Stop/Start nach dem letzten Startup-Fix belegt; nur die frische isolierte Projektkopie bleibt offen. |
| N02 isolierte Tests | Erfüllt für PHP-, Queue-, Browser- und Backup-Prüfungen. |
| N03 Sicherung vor Migration | Vor-Migrationsbackup, unveränderte Bestandstabellen, Post-Migrationsbackup und beide getrennten Restores belegt. |

## Bekannte Grenzen und Folgearbeit

- Abschnitt 2 hält die manuelle Bewertung im Screenshot-Pfad vollständig neutral.
  Allgemeine Retests können die bestehenden Status-/Review-Felder weiterhin
  verändern. Die vollständige F06-Trennung bleibt Arbeitsabschnitt 3.
- Der Dialog wird standardmäßig drei Sekunden nach `domcontentloaded` beobachtet.
  Später ausgelöste Dialoge führen zu einer normalen Seitenaufnahme. Die kurze
  Grace-Phase schützt nur den Übergang zwischen Capture und Teardown.
- Finding und Initialauftrag werden gemeinsam committed; die zugehörige Domain
  wird vom bestehenden `DomainService` zuvor separat gespeichert. Ein seltener
  Datenbankfehler beim folgenden Commit kann deshalb eine leere Domainzeile
  hinterlassen, aber keinen Finding ohne Initialauftrag.
- Direkte ORM-Inserts außerhalb von `FindingService::createFinding()` umgehen den
  Intake-Vertrag. Die Anwendungseinstiege verwenden den Service; die Datenbank
  erzwingt nicht selbst, dass jedes Finding einen Job besitzt.
- `app:retest:* --screenshot` verwendet weiterhin den älteren, direkten
  Retest-Aufnahmeweg. Die persistente Queue gilt für Intake,
  `app:screenshot:*`, `app:evidence:check` und die Screenshot-Phase von
  `app:review:refresh`.
- Die Sperren sind für den vereinbarten einzelnen DDEV-Web-Container ausgelegt.
- Die SQLite-Verbindung erzwingt Fremdschlüssel nicht. Falllöschung und Reset
  entfernen ScreenshotJobs, Evidence und RetestRuns ausdrücklich. Direkte
  Datenbankmanipulationen umgehen diese Anwendungsregeln.
- `/health` belegt Node, aber nicht Chromium, Xvfb oder `ffmpeg`; der Sidecar hat
  keine Docker-Restart-Policy. Ein Fehler nach dem Claim wird terminal sichtbar.
- Ein harter Prozessabbruch zwischen Dateischreiben und Metadaten-Commit kann eine
  nicht referenzierte Datei hinterlassen. `app:artifacts:audit` findet sie.
- Zwei exakt gleiche, wirklich parallele Web-Eingaben können beide den
  Vorab-Lookup passieren. Der Unique Constraint verhindert den doppelten Fall,
  der zweite Request kann dann aber eine Fehlermeldung statt der freundlichen
  Duplikatweiterleitung erhalten.
- Die `missing`-Befehle erkennen fehlende Evidence-Zeilen, nicht eine fehlende
  Datei hinter einer noch vorhandenen Zeile.
- `doctrine:schema:validate` meldet weiterhin historische Unterschiede an alten
  Tabellen; `screenshot_job` erscheint nicht in diesem Diff.
- Der Bestands-Audit findet die bereits bekannten 382 fehlenden Dateireferenzen
  und vier nicht referenzierten Dateien. Abschnitt 2 bereinigt diese Altdaten
  bewusst nicht.
