# Abnahme Betriebsabschluss: frischer DDEV-Betrieb

Stand: 2026-10-03 · getesteter Commit:
`5f28d2efdaa82f4e4de96343f9ef39ef23b3d043`

**N01 bestanden:** Eine vollständig frische, isolierte Arbeitskopie installiert
ihre Abhängigkeiten, führt alle versionierten Migrationen aus und verarbeitet
echte lokale Screenshot-Aufträge. Nach einem isolierten DDEV-Neustart bleiben
alle gespeicherten Datensätze und Bilder erhalten; weitere Aufnahmen funktionieren.
Der abschließende Lauf besteht 73 Harness-Prüfungen und neun Browser-Prüfungen der
tatsächlich gespeicherten Bilder.

## Aufbau und Trennung

Der Nachweis verwendet einen lokalen Git-Clone mit `--no-hardlinks` und einem
Detached Checkout des oben genannten Commits. Vor dem Start wurden ein sauberer
versionierter Arbeitsbaum sowie das Fehlen von `.env`, `vendor`, `node_modules`,
`playwright-worker/node_modules`, `storage` und `var` geprüft. Es wurden keine
Nutzdaten, Artefakte oder installierten Abhängigkeiten aus dem Bestand übernommen.

Einzige lokale Konfigurationsabweichung:

```yaml
# .ddev/config.local.yaml
name: librebugbounty-n01-t25-gexq
```

DDEV löste Name und Projektpfad vor der ersten Lifecycle-Aktion korrekt auf.
Alle Start-, Restart- und Delete-Aufrufe adressierten diese isolierte Kopie.
Der konfigurierte Workerhostname wurde vor beiden Capture-Runden geprüft und
löste ausschließlich auf IP-Adressen des eigenen Playwright-Containers auf.

| Bestandteil | Nachweis |
| --- | --- |
| DDEV / Docker | DDEV 1.25.2, Docker 29.7.2 unter Linux |
| PHP | Tatsächlich PHP 8.3.30 im frischen Web-Container |
| Composer | 84 Pakete frisch aus `composer.lock` installiert |
| Playwright | Image, Manifest, Lockfile und frische npm-Installation verwenden 1.61.1 |
| Datenbank | Private SQLite-Datei unter `storage/database/app.sqlite`; neun echte Doctrine-Migrationen bis `Version20261003000000` |
| Worker | Supervisor meldet `RUNNING`; genau ein tatsächlicher Queue-Worker-Prozess vor und nach Restart |
| Browserbetrieb | Vier tatsächlich gespeicherte 1440×900-PNGs statt eines bloßen `/health`-Nachweises |

Beide Dependency-Lockfiles blieben nach Start und Restart unverändert. Nach der
gesamten Abnahme war auch `git diff --quiet HEAD` erfolgreich.

## Echte Aufnahme und erneutes Öffnen

Der versionierte lokale Fixture-Server lieferte ausschließlich `/plain` und
`/dialog`, ohne externe Ressourcen. Eingänge erfolgten über die echte
Anwendungsseite, ihren vorhandenen Session-/CSRF-Token und `POST /api/findings`.
Es wurde weder ein Mock-Kernel noch `SchemaTool` zur Erstellung der Datenbank
verwendet. Der automatisch gestartete Worker verarbeitete die persistente Queue.

| Runde | Aufträge | Ergebnis |
| --- | --- | --- |
| Vor Restart | Eine normale Seite, eine Seite mit gewöhnlichem `alert()` | Beide `available`, FIFO-Reihenfolge, Aufnahmezeit und SHA-256 gespeichert |
| Nach Restart | Zwei neue lokale Eingänge mit denselben neutralen Seitentypen | Beide erneut `available`, FIFO-Reihenfolge, Aufnahmezeit und SHA-256 gespeichert |

Die normalen Seitenbilder enthalten sichtbaren Seiteninhalt; Metadaten:
`dialogSeen=false`, `captureMethod=desktop-page`. Die Dialogbilder zeigen einen
echten Chromium-Dialog, lokale URL und den Text „N01 harmless local browser
dialog“; Metadaten: `dialogSeen=true`, `dialogType=alert`,
`captureMethod=desktop-dialog`. Die Fixture öffnet den Dialog nach 250 ms.
Chromium hat zu diesem frühen Zeitpunkt hinter dem blockierenden Dialog einen
grauen Viewport; der dort noch nicht gemalte Seiteninhalt ist im Bild nicht
lesbar. Dieser tatsächliche Browserzustand wurde unverändert dokumentiert.

Jede Bilddatei wurde anhand ihres PNG-Headers und des gespeicherten Evidence-
Hashes geprüft. Die Artefaktroute liefert genau diese Bytes. Echte Browseraufrufe
zeigen die Bilder in Falldetail und Review; natürliche Bildmaße wurden geprüft.
Auch die beiden vor dem Restart gespeicherten Bilder bleiben danach in beiden
Ansichten lesbar. Die drei Browserdurchläufe berichten keine JavaScript-Fehler
und keine Anforderungen an externe Ziele.

Die Screenshot-Verarbeitung erhält jede Spalte der betroffenen Finding-Zeilen.
Es entstehen keine RetestRuns und keine manuellen Bewertungen. Das Öffnen von
Detail und Review erhält sämtliche persistierten Tabellen. Vor und nach Restart
wurden alle Tabelleninhalte und alle Artefaktbytes verglichen: unverändert.
Der abschließende `app:artifacts:audit` liefert Exitcode 0.

## Diagnostik und Grenzen

- Bei kaltem Start meldet Supervisor zunächst einen `spawn error`, solange
  Composer-Abhängigkeiten fehlen. Die versionierten Post-Start-Hooks installieren
  anschließend die Abhängigkeiten, migrieren und starten den Worker erfolgreich.
  Es war keine manuelle Reparatur im Container nötig.
- Mappingvalidierung und `doctrine:migrations:up-to-date` bestehen vor und nach
  Restart. Die vollständige `doctrine:schema:validate`-Synchronitätsprüfung meldet
  weiterhin Exitcode 2 an den alten Tabellen `finding`, `evidence` und
  `retest_run`. Der lesende SQL-Dump schlägt Tabellen-Neuaufbauten vor, obwohl
  die gespeicherten und vorgeschlagenen Spaltentypen übereinstimmen: Er ergänzt
  explizites `ON UPDATE NO ACTION`, ersetzt historische `idx_*`-Indexnamen durch
  automatisch benannte Indizes und lässt den zusätzlichen `idx_finding_status`
  weg. Die FK-Namen sind unverändert; SQLite verwendet für ein ausgelassenes
  `ON UPDATE` bereits `NO ACTION`. Die neuen Screenshot-,
  Assessment- und Acknowledgement-Tabellen erscheinen nicht im Diff. Der SQL-Dump
  wurde nicht angewendet. Für Intake, Queue, Bildspeicherung, Anzeige, Restart
  und Artefaktaudit trat daraus kein Funktionsfehler auf; vollständige historische
  Schema-Synchronität ist damit weiterhin nicht nachgewiesen.
- Der gemeinsame DDEV-Router meldet bereits eine ungültige ältere `ui.crt`.
  Diese Abnahme verwendet HTTP auf der privaten IP des isolierten Web-Containers
  und belegt daher keine Reparatur oder vollständige TLS-Abnahme des gemeinsamen
  Routers.
- Die Bewertung und Ausführung fremder PoCs sowie neue automatisierte technische
  Prüfungen gehören nicht zu diesem Betriebsnachweis. Aufnahmen verwenden
  ausschließlich kontrollierte lokale Seiten und neutrale Metadaten.

## Aufbewahrung und Bereinigung

Berichte und Bilder wurden vor dem Entfernen des isolierten DDEV-Projekts aus
der Kopie heraus gesichert:

`/tmp/librebugbounty-n01-final-t25_gexq/report/`

Dort liegen `report.json`, vier rohe Capture-PNGs und unter `browser/` neun
Screenshots sowie drei Browserberichte. Der vollständige diagnostische Schema-
SQL-Dump ist zusätzlich als `schema-diff.sql` aufbewahrt. Das Verzeichnis ist ein
lokaler temporärer Abnahmebeleg; der reproduzierbare Harness ist versioniert.

`ddev delete --omit-snapshot --yes --clean-containers=false
librebugbounty-n01-t25-gexq` endete mit Exitcode 0. Anschließend existieren keine
Container dieses isolierten Projekts mehr. Die bestehenden `librebugbounty`-
Container für Web, Playwright und Datenbank laufen mit identischen Startzeitpunkten
wie vor dem Versuch weiter. Ihre Lifecycle-Aktionen wurden nicht aufgerufen.
Nach der Sicherung aller Berichte wurden auch die ausschließlich für diese
Abnahme angelegten temporären Checkout-/Runtime-Verzeichnisse separat entfernt.

## Nachweis wiederholen

Aus dem Repository auf dem Host ausführen. Der Reportpfad darf noch nicht
existieren und muss außerhalb des frischen Checkouts liegen:

```bash
N01_COMMIT=5f28d2efdaa82f4e4de96343f9ef39ef23b3d043
N01_ROOT="$(mktemp -d -t librebugbounty-n01.XXXXXXXX)"
N01_PROJECT="librebugbounty-n01-$(date +%s)"
git clone --local --no-hardlinks --no-checkout . "$N01_ROOT/checkout"
git -C "$N01_ROOT/checkout" checkout --detach "$N01_COMMIT"
printf 'name: %s\n' "$N01_PROJECT" > "$N01_ROOT/checkout/.ddev/config.local.yaml"
python3 -B "$N01_ROOT/checkout/tests/n01_ddev_acceptance.py" \
  --checkout "$N01_ROOT/checkout" \
  --project-name "$N01_PROJECT" \
  --expected-commit "$N01_COMMIT" \
  --report-dir "$N01_ROOT/report"
```

Der Harness schützt den Projektnamen, prüft Frische und Quellenstand, führt die
Abnahmen aus, bewahrt Berichte außerhalb der Kopie auf und entfernt ausschließlich
das eigens benannte DDEV-Projekt. Die lokale Checkout-Dateibaumkopie bleibt zur
Diagnose erhalten und kann anschließend separat entfernt werden. Die drei
versionierten Bausteine sind
[Host-Harness](../tests/n01_ddev_acceptance.py),
[neutrale Browserfixtures](../tests/Support/n01_fixture_router.php) und
[Prüfung gespeicherter Bilder](../tests/browser/n01-ddev.cjs).

Anforderungen: [N01 im Lastenheft](lastenheft.md), frühere Betriebsabnahme:
[Abschnitt 2](abnahme-abschnitt-2.md), Review-Festlegungen:
[Studio-Review](studio-review.md).
