# Abnahme Arbeitsabschnitt 1

Stand: 2026-10-02. Auftrag: „Setze Arbeitsabschnitt 1 aus architecture/lastenheft.md um.“

## Ergebnis und Prüfstand

Abschnitt 1 „Sicher bearbeiten und lesen“ ist umgesetzt und im DDEV-Web-Container
geprüft. Ausgangspunkt war Commit `5043f1c009e95f93c49941592493dc166d9994ae` plus die
unversionierten Architekturunterlagen aus diesem Gespräch. Die Umsetzung liegt
als uncommittierte Arbeitskopie vor; es wurde kein Commit erstellt.

Laufzeit: DDEV-Projekt `librebugbounty`, PHP 8.3.30, Symfony 7.4.14, Doctrine ORM
3.6.7, DoctrineBundle 2.18.3. SQLite bleibt Anwendungsspeicher. Keine Migration
oder schreibende Verwaltungsaktion wurde auf Nutzdaten ausgeführt.

## Änderungen

- `EvidenceStorageInterface` umfasst Schreiben, Lesen, Existenzprüfung, gezieltes
  Löschen, explizites Leeren und Auflisten. `LocalEvidenceStorage` verwendet dafür
  ausschließlich `EVIDENCE_STORAGE_DIR`.
- Bestehende logische Pfade mit `storage/artifacts/` bleiben kompatibel. Die
  physische Ablage ist unabhängig davon konfigurierbar; keine Datenmigration nötig.
  Neue Dateinamen sind eindeutig. Symlinks und Pfade außerhalb der Ablage werden
  beim Zugriff zurückgewiesen.
- EvidenceService, FindingService, ResetService, die Ablage eingehender Bilddaten
  im RetestService sowie die Web-Auslieferung verwenden dieselbe Speichergrenze.
  Aufnahme und Browsersteuerung wurden nicht verändert.
- Die Detailansicht bereinigt keine Daten mehr beim GET und ruft keinen geschützten
  EntityManager-Zugriff auf. Fehlende Dateien bleiben als nicht verfügbare Belege
  sichtbar; vorhandene Bilder und Notizen werden angezeigt.
- `app:artifacts:audit` meldet fehlende/unlesbare Verweise und nicht zugeordnete
  Dateien ausschließlich lesend. Auch alte Laufverweise werden berücksichtigt.
- `app:evidence:refresh` löscht keine alten Belege, Läufe, Notizen oder Kontaktstände.
  Der bisherige destruktive Vorlauf wurde entfernt. Explizite Reset-Befehle behalten
  ihre Wirkung, zeigen vorher die betroffenen Mengen und erfordern `--force`.
- PHPUnit überschreibt geerbte Laufzeitpfade mit einem neu erzeugten temporären
  Verzeichnis. Tests verwenden getrennte SQLite-/Artefaktdaten sowie einen eigenen
  TestKernel mit temporärem Cache und Logs. Ein Schutz prüft den Datenbankpfad vor
  jedem Schema-Reset. Temporäre Daten werden am Prozessende aufgeräumt.
- Kernel-Konfigurationsimporte sind an den Projektpfad gebunden, damit ein
  TestKernel an anderem Speicherort dieselbe Anwendung konfigurieren kann.

## Ausgeführte Prüfungen

| Prüfung | Ergebnis |
| --- | --- |
| `ddev exec vendor/bin/phpunit` | **51 Tests, 257 Assertions, erfolgreich**, keine Risky Tests; 4,133 Sekunden im abschließenden Lauf |
| `ddev exec php bin/console lint:container` | Erfolgreich; Service-Injektionen passen zu ihren Typen |
| PHP-Syntaxprüfung im DDEV-Web-Container | Alle 29 neuen/geänderten PHP-Dateien erfolgreich |
| `git diff --check` | Keine Whitespace-Fehler |
| Hashvergleich bestehender Nutzdateien vor/nach Umsetzung und Testläufen | 62 Dateien in `storage/database` und `storage/artifacts`; 0 geändert, 0 gelöscht, 0 hinzugefügt |

Die Hashprüfung umfasste vorhandene Dateien samt Unterverzeichnissen; sie behauptet
keine Wiederherstellung schon zuvor fehlender Dateien. Vollständige Pfade und
private Nutzdaten wurden nicht in dieses Protokoll übernommen.

## Zuordnung zur Abnahme

| Anforderung im Abschnitt | Nachweis |
| --- | --- |
| F02: lesende Detailansicht mit vorhandenen, fehlenden und ohne Belege | `WebReadAcceptanceTest::testDetailAndImagesAreReadOnlyWithPresentMissingAndNoEvidence`: echte Symfony-Routen über Kernel-Requests, wiederholter GET, 200/404 wie erwartet, vollständiger Vorher-/Nachher-Vergleich von Domain, Finding, Evidence, Run und Setting |
| F02: vorhandenes Bild bleibt lesbar | Import eines harmlosen PNG über EvidenceService, anschließend Bildroute; Bytes, Content-Type und Bildabmessungen geprüft |
| F02: Übersicht mit 5.500 Fällen | `testOverviewPaginates5500CasesAndKeepsFilters`: je 10 unterschiedliche Fälle auf Seite 1/2, korrekte Gesamtzahl, Domain-/Statusfilter, keine eingebetteten Belegbilder in der Übersicht |
| F04: einheitlicher Speicher und historische Pfade | `LocalEvidenceStorageTest`: verschobene Ablage, alter logischer Pfad, getrennte Dateien bei gleichem Dateinamen, gezieltes Löschen und explizites Leeren |
| F04: fehlende Dateien bleiben diagnostizierbar | Detailtest und `testArtifactAuditIncludesRunReferencesAndNeverDeletes`: fehlender Laufverweis und nicht zugeordnete Datei erkannt, Daten und Datei erhalten |
| F04: Speicherfehler | `EvidenceFailureTest`: Schreibfehler persistiert keinen erfolgreichen Beleg; bei fehlgeschlagener Metadatenspeicherung wird nur die neu importierte Datei entfernt, alter Beleg bleibt erhalten |
| F04: Ablagegrenze | `LocalEvidenceStorageTest`: Pfadgrenzen und Symlinks werden abgewiesen; Leeren verfolgt keinen Symlink in eine benachbarte Ablage |
| F05: Refresh erhält Bestandsinformationen | `RefreshPreservationTest`: realer Single-Finding-Commandpfad mit simuliertem Transport, sowohl zurückgegebenes Fehlerresultat als auch geworfene Ausnahme; alte Belege, Lauf, Notiz und Kontakt-/Meldezeitpunkte bleiben erhalten |
| F05: Reset ist explizit und zeigt Umfang | `ResetCommandSafetyTest`: alle drei Reset-Befehle verweigern Änderungen ohne Force; Dry-run bleibt auch zusammen mit Force lesend; Wirkungen und Mengen erscheinen |
| N02: Testspeicher getrennt | Suite läuft in generiertem temporärem Verzeichnis; Kontroll-Datei und SQLite-Kontrolldatenbank neben der Artefaktwurzel bleiben bei Löschtests unverändert; zusätzlich Hashvergleich des tatsächlichen Nutzbestands |

Die Integrationstests laufen mit tatsächlichem Symfony-Routing, Doctrine und
temporärer SQLite im DDEV-Web-Container. Es handelt sich nicht um eine manuelle
Browserabnahme über den Nginx-/TLS-Router. Browserantworten werden simuliert;
es wurden keine externen Ziel-URLs aufgerufen.

## Während der Abnahme gefundene Testprobleme

Der erste Integrationslauf verwendete alte Doctrine-Metadaten aus dem vorhandenen
Testcache: ReviewState fehlte in DQL, Kontaktzeitpunkte wurden nicht geladen.
Ein pro Testlauf neuer Symfony-/Doctrine-Cache beseitigt diese Kopplung an frühere
Projektstände. Das ist ein Befund zur Testumgebung und keine nachträgliche Erklärung
für die vom Nutzer zuvor als funktionierend beschriebene Detailansicht.

## Grenzen und nächste Abschnitte

- F03, tatsächliche Aufnahme mit sichtbarem Browserdialog, ist **nicht abgenommen**.
  Dieser Abschnitt belegt Speicherung und Auslieferung eines importierten Bildes.
- Die fachliche Neustrukturierung der Zustände, Filter und Hinweise aus F06 bleibt
  Abschnitt 3. Die bestätigte Produktregel zum Erhalt manueller Bewertungen gilt
  weiter, ist im bisherigen allgemeinen Browserablauf noch nicht vollständig umgesetzt.
- Das Audit repariert keine Dateien und löscht nichts. Fehlende alte Bilder bleiben
  fehlend. Eine Löschung kann auch künftig nur ausdrücklich beauftragt werden.
- Datei und Datenbank bilden keine gemeinsame ACID-Transaktion. Für einen
  fehlgeschlagenen Evidence-Import gibt es Dateikompensation; Prozessabbruch oder
  spätere Fehler in mehrteiligen Abläufen können weiterhin auditierbare Restdateien
  hinterlassen. Eine umfassende Lauf-/Fehlertransaktion gehört zum nächsten Entwurf.
- Symlinks in der konfigurierten Belegablage werden bewusst nicht unterstützt.
- Keine Bestandsmigration, keine Wiederherstellung und kein Test eines frischen
  DDEV-Neuaufbaus durchgeführt; diese übergreifenden Abnahmen gehören zu späteren Abschnitten.

README und Architekturindex verweisen auf den aktuellen Stand und die Diagnose.
