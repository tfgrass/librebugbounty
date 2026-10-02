# Abnahme Arbeitsabschnitt 3a

Stand: 2026-10-02. Der Nutzer hat nach Klärung der vier Produktfragen ausdrücklich
die Umsetzung des begrenzten Pakets 3a beauftragt. Umsetzung und lokale Migration
sind abgeschlossen; Abschnitt 3b bleibt ein eigener nächster Abschnitt.

## Geliefertes Verhalten

- Die Detailansicht trennt manuelles Urteil, letzte technische Beobachtung und
  Kontaktzeitpunkt. Bei `inconclusive` ist eine ausdrückliche Bestätigung möglich,
  auch wenn der gewachsene Status bereits `verified` ist. Ein manuell als behoben
  bewerteter Fall kann ausdrücklich neu bestätigt werden.
- Bestätigen, Behoben und Verwerfen verwenden einen gemeinsamen Anwendungsfall.
  Verwerfen kann den Grund Duplikat erhalten. Neue manuelle Bewertungen und ihre
  Historieneinträge werden in derselben Doctrine-Transaktion gespeichert.
- Verworfene Fälle und historische Duplikatstatus werden aus normalen Listen,
  Zählern, Exporten und Arbeitsauswahlen ausgeschlossen, bevor Limits greifen.
  Explizite Archivfilter und die direkte Detailansicht bleiben lesend erreichbar.
- Bereits wartende Screenshot-Aufträge verworfener Fälle werden weder als normale
  Warteschlange gezählt noch beansprucht. Historische Auftragseinträge bleiben
  erhalten. Wiederholte URL-Eingabe erzeugt für einen verworfenen Fall keine neue
  Aufnahme. Bereits begonnene Browserarbeit wird durch Verwerfen nicht abgebrochen.
- Technische Ergebnisse erhalten neue manuelle Urteile und alte Schutzmarker.
  Vor Bearbeitung und Ergebnisanwendung wird ein verwalteter Fall erneut geladen;
  eine zwischenzeitliche Entscheidung bleibt damit berücksichtigt.
- Kontaktieren setzt unabhängig vom Urteil einen Zeitpunkt. Wiederholtes
  Kontaktieren datiert einen vorhandenen Kontakt nicht um.
- Die neue Historie enthält Urteil, Zeitpunkt und Quelle sowie ausdrücklich
  gewählte Beobachtungs-/Belegbezüge mit bekannten Metadaten. Ohne Auswahl bleibt
  die Bewertungsgrundlage unbekannt. Historische Herkunft wird nicht nachgefüllt.
- Zusätzlich erfasst die Historie die zum Bewertungszeitpunkt gespeicherten
  Beobachtungs-IDs. Dies behauptet keine Sichtung. Später gespeicherte IDs sind
  auch bei identischem Sekundenzeitpunkt oder nach Löschen alter Laufdaten neu.
- Bewertungs- und Kontaktformulare einschließlich der alten Bewertungsroutes sind
  mit CSRF-Tokens geschützt. Die Kontaktaktion der Betreiberansicht nutzt dieselbe
  Absicherung. Andere bestehende Mutationswege wurden hier nicht umgebaut.
- `app:finding:assess` verwendet für ausdrückliche CLI-Bewertung und Kontaktieren
  denselben Anwendungsfall und startet keine Browserarbeit.

## Ausgeführte Prüfungen

| Prüfung | Ergebnis |
| --- | --- |
| Vollständige isolierte PHPUnit-Suite im DDEV-Web-Container | 124 Tests, 930 Assertions erfolgreich |
| Neue Web-Abnahme | Bestätigungsbedarf, Änderungen, datierte Hinweise, Gleichsekundenfälle, CSRF, Referenzzugehörigkeit, idempotenter Kontakt und unveränderte Daten bei GET geprüft |
| Bewertungs-/Beobachtungsabnahme | Neue und alte Bewertungen bei simulierten Ergebnissen erhalten; verspätete Ergebnisse und veraltete Batch-Entities berücksichtigt |
| Daten- und Repository-Abnahme | Atomarer Rollback bei Schreibfehler, unbekannte Altherkunft, Archive, Pagination, Queue-Ausschluss und historischer Datenerhalt geprüft |
| Container-Lint, Composer-Validierung, PHP-Syntax und `git diff --check` | Erfolgreich |
| Lokale HTTP-Abnahme nach Migration | Übersicht und bestehende Detailansicht antworten mit HTTP 200; Bewertungsabschnitt und Historie vorhanden |
| Screenshot-Worker nach Migration | Supervisor meldet RUNNING |

Die Suite läuft mit separaten temporären Datenbanken und Artefaktwurzeln.
Technische Antworten sind simuliert; es wurden keine gespeicherten externen
Ziel-URLs geprüft. Der HTTP-Nachweis verwendet ausschließlich lesende lokale
Anwendungsseiten und gibt keine Falldaten aus.

## Sicherung und Übernahme

Vor der Migration wurde ein konsistenter Datenbank-/Artefaktsnapshot erstellt
und getrennt restore-validiert:

`~/.local/share/librebugbounty/backups/20261002T204301Z-32fe022a/`

Die additive Migration `Version20261002010000` wurde zunächst auf einer Kopie
dieses Bestands geprüft und erst danach lokal angewendet. Vergleich aller
ursprünglichen Spalten/Zeilen der sechs Anwendungstabellen: unverändert.
Alle 5.348 Fall-IDs, Notizen, Kontaktfelder, Beleg-/Lauf-/Auftragsinformationen
und fünf vorhandenen Artefaktdateien blieben erhalten.

Die neuen aktuellen Bewertungsfelder blieben für alle Altfälle NULL;
`finding_assessment` enthält nach Migration null Einträge. Es wurde keine frühere
Nutzerentscheidung erfunden. Die Migrationstabelle erhielt den erwarteten Eintrag.
SQLite-Integrität ist erfolgreich; die 54 bereits vorhandenen Fremdschlüssel-
verletzungen sind unverändert geblieben und wurden nicht bereinigt.

Nach Migration wurde erneut gesichert und restore-validiert:

`~/.local/share/librebugbounty/backups/20261002T205850Z-eaf7092f/`

Ein automatisches Rückgängigmachen der Migration ist gesperrt, weil es neue
Nutzerentscheidungen löschen würde. Der Wiederherstellungsweg bleibt die geprüfte
Sicherung auf einer getrennten Kopie.

## Grenzen und nächster Abschnitt

3b übernimmt die weitergehende Vereinheitlichung von Begriffen, Statusanzeigen,
Filtern und Zählregeln. Die bisherigen fachlichen Buckets wurden über den nötigen
Ausschluss verworfener Fälle hinaus nicht neu entworfen. Der neue Bewertungsabschnitt
ist fokussiert im vorhandenen Controller; ein vollständiger Template-Umbau fehlt.

Arbeitsliste, Zurückstellen, gezieltes Abarbeiten von Hinweisen und Bildvergleich
bleiben das spätere Review-Paket. N01 aus einer frischen isolierten DDEV-Kopie
bleibt ein separater Betriebsnachweis. Die Beobachtung des Nutzers zu vielen
Dialogaufrufen wurde nicht reproduziert oder technisch diagnostiziert.
