# SQLite und Belege sichern

Stand: 2026-10-02. Umsetzung des zusätzlich beauftragten Sicherungsschritts und
Nachweis für N03. Vor der Queue-Migration wurde ein neuer konsistenter Stand
erstellt und wiederhergestellt; die danach verwendete Installation wurde gegen
diesen Stand verglichen.

## Wiederholbarer Sicherungsweg

Vom Projektverzeichnis auf dem Host aus:

```sh
python3 bin/backup-local.py create
```

Voraussetzungen: Python ab 3.11, Docker-Zugriff und die laufende DDEV-Installation.
Standardquellen sind `storage/database/app.sqlite` und `storage/artifacts`.
Bei abweichender Anwendungskonfiguration müssen `--database` und `--artifacts`
entsprechend gesetzt werden; das Skript interpretiert keine `.env`-Dateien.
Ein anderer DDEV-Projektname wird mit `--project` angegeben.

Das Skript pausiert kurz alle laufenden Container mit dem DDEV-Projektlabel,
erstellt einen SQLite-Snapshot mit der Online-Backup-API und kopiert sämtliche
Artefakte. Es verwendet keine rohe Kopie der laufenden SQLite-Datei: auch bestätigte
Daten im WAL gehören dadurch zum Snapshot. Währenddessen dürfen keine zusätzlichen
Host-Prozesse auf Datenbank oder Artefakte schreiben. Docker-Pause startet keine
Container und führt keine DDEV-Hooks aus.

Anschließend werden die selbst pausierten Container wieder freigegeben. Bereits
zuvor pausierte Container bleiben pausiert. Fehler, Strg+C und SIGTERM führen
ebenfalls durch diese Freigabe; während der Freigabe wird ein erneutes Abbruchsignal
ignoriert. Einzelne Docker-Aufrufe sind auf 20 Sekunden begrenzt, SQLite-Backup auf
15 Sekunden, die Snapshot-Phase auf 60 Sekunden zuzüglich Freigabe. Ein von einem
angehaltenen Schreibvorgang blockierter Snapshot wird abgebrochen statt unbegrenzt
zu warten. Bei einem Docker-Ausfall nennt das Skript nötige `docker unpause`-Ziele.
SIGKILL oder ein Host-Ausfall können keine programmatische Freigabe garantieren.

Standardziel außerhalb des Git-Projekts:

```text
~/.local/share/librebugbounty/backups/<UTC-Zeitstempel>-<Kennung>/
  database.sqlite
  artifacts/
  manifest.json
```

Verzeichnisse erhalten Modus `0700`, Dateien `0600`. Ein vorhandenes, nicht
privates Zielverzeichnis wird abgelehnt, ohne seine Rechte zu ändern. Mit
`--destination` lässt sich ein anderes privates Ziel wählen, etwa auf einem
separaten Datenträger. Der Standard schützt nicht gegen den Verlust desselben
Datenträgers.

Das Manifest enthält Prüfsummen, aggregierte Tabellenzahlen und Prüfsummen aller
Tabelleninhalte einschließlich IDs, Notizen, Kontaktfeldern und Belegzuordnungen.
Vorhandene fehlende Belege werden gezählt; ihre Metadaten bleiben bestehen.
Datensätze und Ziel-URLs werden nicht in der Konsolenausgabe ausgegeben.
Die Referenzanalyse berücksichtigt nach der Queue-Migration neben Evidence und
RetestRuns auch Bildpfade aus `screenshot_job`.

Vor der Erfolgsmeldung stellt das Skript das Backup in ein zweites temporäres
Verzeichnis wieder her und prüft es. Erst danach entfällt die Endung `.incomplete`.
Fehlgeschlagene Teilstände bleiben mit dieser Endung erhalten; alte Sicherungen
werden nicht gelöscht. Ältere separate SQLite-Kopien im Projekt werden weder
verändert noch erneut mitgesichert. Ein Backup-Zeitplan ist nicht eingerichtet.
Fehler beim Durchlaufen eines Artefaktverzeichnisses brechen sowohl Erfassung als
auch Kopiervorgang ab; unlesbare Unterverzeichnisse werden nicht still übersprungen.

Bei bereits gestopptem DDEV und gestoppten Host-Schreibprozessen ist `create
--offline` möglich. Bei laufenden Projektcontainern lehnt dieser Modus ab.

## Prüfen und getrennt wiederherstellen

```sh
python3 bin/backup-local.py verify /absoluter/pfad/zum/backup
python3 bin/backup-local.py restore /absoluter/pfad/zum/backup storage/recovery-check
```

Das Restore-Ziel darf noch nicht existieren. Der Befehl überschreibt weder eine
bestehende Kopie noch die laufende Anwendung. Er prüft SQLite-Integrität, sämtliche
Tabelleninhalte und Artefaktprüfsummen. Die Kopie lässt sich anschließend über
`DATABASE_URL` und `EVIDENCE_STORAGE_DIR` gezielt für eine getrennte Anwendung
konfigurieren. Das Umschalten der tatsächlich verwendeten Installation ist ein
eigener Vorgang; das Sicherungsskript nimmt es nicht automatisch vor.

## Erste Abnahme des Sicherungswerkzeugs

Erstellter Stand auf diesem Rechner:

```text
/home/tomka/.local/share/librebugbounty/backups/20261002T161417Z-fe6aa087
```

- SQLite-Integritätsprüfung erfolgreich; 6 Tabellen gesichert.
- 5.348 Fälle, 4.692 Domains, 8.016 Belege, 8.896 technische Läufe,
  4 Einstellungen und 6 Migrationsstände erhalten.
- Alle Spalten sämtlicher Tabellen einschließlich IDs und Notizen mit der Quelle
  verglichen; keine Abweichung.
- 4 vorhandene Artefaktdateien kopiert und ihre Prüfsummen verglichen.
- **382 bereits fehlende Dateireferenzen**, keine davon durch das Backup als
  wiederhergestellt ausgegeben. Diese Zahl zählt Referenzen aus Belegen und Läufen,
  nicht zwingend unterschiedliche Dateien.
- Wiederherstellung in einer zweiten temporären Kopie erfolgreich. Zusätzlich
  eine getrennte Kopie im ignorierten `storage/`-Bereich mit PHP/PDO im
  DDEV-Web-Container geprüft: `integrity_check=ok`, alle Tabellenzahlen sowie
  Datenbank- und Artefaktprüfsummen stimmen. Temporäre Prüfkopie danach entfernt.
- 10 isolierte Python-Tests erfolgreich: WAL-Inhalte, fehlende Altverweise,
  Datei-/Datenbankbeschädigung, kein Überschreiben, Symlink-Ablehnung, Zeitlimit,
  Freigabe nach Abbruch und nach teilweise fehlgeschlagener Pause sowie Ablehnung
  des Offline-Modus bei laufenden Containern. Zwei weitere Fehlerprüfungen
  simulieren fehlende Leserechte beim Erfassen und Kopieren der Artefakte:
  jeweils Abbruch mit `PermissionError`, ausschließlich ein `.incomplete`-Stand,
  keine Veröffentlichung als erfolgreiches Backup. Beide Prüfungen schlugen
  vor der Korrektur fehl. Das bestehende reale Backup wurde nach der Korrektur
  nochmals erfolgreich verifiziert, ohne Containerpause oder Neustart.

Tests erneut ausführen:

```sh
python3 -B -m unittest discover -s tests -p backup_local_test.py -v
```

## Sicherung und Vergleich der Queue-Migration

Unmittelbar vor der schreibenden Migration für persistente Screenshot-Aufträge
wurde mit demselben Verfahren ein neuer Stand erstellt und erfolgreich
wiederhergestellt:

```text
/home/tomka/.local/share/librebugbounty/backups/20261002T165845Z-d697f3b8
```

Der Stand vor der Migration enthielt:

- 4.692 Domains, 5.348 Findings, 8.016 Evidence-Zeilen und 8.896 RetestRuns;
- 4 Settings und 6 bereits angewendete Migrationen;
- 4 vorhandene Artefaktdateien;
- 382 bereits fehlende Dateireferenzen.

Die SQLite-Fremdschlüsselprüfung zeigte bereits vor der neuen Migration 54
historische Verletzungen: 18 Evidence-Zeilen und 36 RetestRuns verwiesen auf nicht
mehr vorhandene Findings. Der Sicherungsvorgang bewahrt diese Metadaten und
behauptet keine Bereinigung. Die verwendete SQLite-Verbindung meldet
`PRAGMA foreign_keys=0`; Sicherung und Anwendung dürfen deshalb bei Löschungen
nicht auf deklarierte Cascades vertrauen.

Nach Anwendung von `Version20261002000000` besitzt die verwendete Datenbank sieben
Migrationsstände und die zunächst leere Tabelle `screenshot_job`. Für alle fünf
vorher vorhandenen Anwendungstabellen stimmen Zeilenzahlen und vollständige
Inhaltshashes mit dem Sicherungsstand überein. Anzahl und Prüfsummen der vier
Artefaktdateien sind ebenfalls unverändert. Damit hat die Queue-Migration keine
vorhandenen Fälle, Notizen, Kontakte, Läufe, Belege oder Einstellungen geändert.

Nach Migration und abschließender Laufzeitprüfung wurde außerdem der migrierte
Zustand dauerhaft gesichert und separat wiederhergestellt:

```text
/home/tomka/.local/share/librebugbounty/backups/20261002T172248Z-293f5bb8
```

Der verifizierte Snapshot enthält 7 Migrationen, 4.692 Domains, 5.348 Findings,
8.016 Evidence-Zeilen, 8.896 RetestRuns, 0 ScreenshotJobs und 4 Settings. Die vier
Artefaktdateien wurden mitgesichert; 382 historische fehlende Referenzen bleiben
als solche ausgewiesen. Damit stehen sowohl der geprüfte Zustand direkt vor der
Queue-Migration als auch ein geprüfter Rückkehrpunkt danach zur Verfügung.

## Früheres DDEV-Verhalten bei der ersten Werkzeugabnahme

Bei der ersten Abnahme des Sicherungswerkzeugs hat der Versuch der
Containerprüfung über `ddev exec php ...` unerwartet
Container neu erstellt und den vorhandenen Post-start-Hook ausgeführt. Das Backup
war zu diesem Zeitpunkt bereits vollständig erstellt und wiederhergestellt.
Der Hook meldete den bereits aktuellen Migrationsstand
`DoctrineMigrations\Version20260709000000`; es wurde keine Migration angewendet.
Auch Composer meldete keine Installation oder Änderung von Abhängigkeiten.

Danach wurden Schema und alle Tabelleninhalte der Live-Datenbank erneut vollständig
mit dem gesicherten Stand sowie alle Artefakthashes verglichen: **unverändert**.
Die abschließende DDEV-Prüfung erfolgte direkt mit `docker exec` im laufenden
Web-Container, ohne erneuten DDEV-Start. Die Projektcontainer waren anschließend
wieder betriebsbereit. Das Sicherungsskript selbst verwendet ausschließlich Docker
`ps`, `inspect`, `pause` und `unpause`, kein `ddev exec` und keinen Neustart.
