# LibreBugBounty – Architekturstand

Stand: 2026-10-03. Einstieg für weitere Architekturgespräche und Umsetzung.

## Ziel und bestätigte Richtung

LibreBugBounty ist eine lokale Fall- und Belegverwaltung für URL-basierte
Security-Funde als persönliche Alternative zu OpenBugBounty. Bestätigt sind:

- Symfony, Doctrine, SQLite und DDEV bleiben bestehen; die Architektur wird im
  vorhandenen Stack geordnet.
- Ein Screenshot zeigt die sichtbare Seite und, wenn vorhanden, den echten
  Browserdialog. Auch ohne Dialog und bei `inconclusive` wird die Seite
  aufgenommen.
- Manuelle Bewertungen bleiben erhalten. Neue technische Ergebnisse erscheinen
  als Hinweise und dürfen eine Nutzerentscheidung nicht still überschreiben.
- Der schnelle Einzeleingang bestätigt den atomaren Commit von Finding und
  ScreenshotJob und kehrt dann ohne automatischen Retest zurück. Die nächste URL
  lässt sich sofort erfassen; Sitzungsverlauf und unbestätigte Eingaben bleiben
  im aktuellen Tab erreichbar.
- Zuerst werden vorhandene Abläufe in der aktuellen Ansicht stabilisiert und
  schrittweise erweitert. Die Resolve-artige Oberfläche übernimmt später zwei
  bis drei oder mehr bereits brauchbare Arbeitsbereiche.

## Umgesetzter Stand

### Arbeitsabschnitt 1: sichere Daten- und Belegbasis

Umgesetzt und in DDEV geprüft sind isolierter Testspeicher, einheitliche
Artefaktablage, lesende Detailansicht, sichtbare fehlende Belege und der Schutz
bestehender Informationen vor Refresh-Löschungen.

[Abnahme und Grenzen von Abschnitt 1](abnahme-abschnitt-1.md): 51 Tests mit 257
Assertions, Container-Lint und PHP-Syntaxprüfung waren erfolgreich.

### Arbeitsabschnitt 2: persistente Screenshot-Aufträge

Die Bildaufnahme ist vom technischen Retest getrennt:

```text
Web/CLI -> ScreenshotJob (SQLite) -> serieller Symfony-Worker
                                      -> POST /screenshot
                                      -> headed Chromium auf Xvfb
                                      -> PNG + Evidence + Aufnahmemetadaten
```

Neue Findings und ihr erster Screenshot-Auftrag werden atomar gespeichert. Der
von DDEV/Supervisor gestartete Worker verarbeitet persistente Aufträge in
FIFO-Reihenfolge einzeln. Die Zustände
`queued`, `running`, `available` und `failed` bleiben in der Detailansicht sichtbar.

**Revision vom 2026-10-03:** Die in Abschnitt 2 eingeführte unmittelbare
headless Prüfung jedes neuen UI-Eingangs ist für den Intake aufgehoben. Sie ließ
den Request trotz bereits gespeicherten Falls bis zum 120-Sekunden-Timeout auf
Browserarbeit warten; bei sehr vielen `alert()`-Aufrufen kam der Vorgang teils
nicht rechtzeitig zum Abschluss. Das verhinderte mehrere schnelle Einzeleingaben.
Bestätigte Speicherung hat im Intake deshalb Vorrang. Der Intake startet jetzt weder
synchron noch automatisch einen Retest. Manuelle und andere ausdrücklich
gestartete Retest-Wege bleiben davon unberührt.

Der neutrale `/screenshot`-Endpunkt erzeugt mit Chromium und `ffmpeg` eine
1440x900-Aufnahme des vollständigen Xvfb-Desktops. Er nimmt eine normale Seite
ebenso auf wie eine Seite mit offenem echtem Dialog. Aufnahme und Fehler ändern
weder Status und Review-State noch Notizen, Kontakte, `lastRetestedAt` oder
Retest-Historie.

Ein eindeutiger aktiver Schlüssel verhindert mehrere gleichzeitig aktive Aufträge
für denselben Fall. Worker-, Capture-, Queue- und Maintenance-Locks schützen die
Ein-Container-Installation vor konkurrierendem Capture, Reset oder Löschen. Nach
einer Unterbrechung wird ein Auftrag wieder eingereiht; nach drei Unterbrechungen
wird er terminal `failed`.

Da die aktuelle SQLite-Verbindung Fremdschlüssel nicht erzwingt, entfernt der
FindingService aktive und terminale ScreenshotJobs, Evidence und RetestRuns bei
einer Falllöschung ausdrücklich unter dem Maintenance-Lock. Die Löschgarantie
stammt aus diesem Anwendungsfall und nicht aus `ON DELETE CASCADE`.
Umgekehrt revalidiert das Einreihen das Finding unter dem Queue-Lock und fügt per
`INSERT ... SELECT` nur aus einer noch vorhandenen Finding-Zeile ein. Eine
veraltete Entity-Referenz kann daher nach einer parallelen Löschung keinen Job
erzeugen.

Die versionierte DDEV-Konfiguration enthält die lokale SQLite-/Artefakt-
Grundkonfiguration ausdrücklich. Playwright-Dockerimage, Node-Manifest und Lockfile
sind auf Version `1.61.1` ausgerichtet; eine frische Kopie kann `npm ci` ohne eine
unversionierte lokale `.env` ausführen.

[Abnahme von Abschnitt 2](abnahme-abschnitt-2.md) dokumentiert echte lokale Bilder
mit und ohne Dialog, reale Serialität, Recovery, Fehlerpfade, neutralen
Finding-Zustand, Web-Intake, Bestandsmigration und DDEV-Betrieb.

[SQLite-/Artefaktsicherung und Wiederherstellung](backup.md) wurden vor der
schreibenden Queue-Migration erneut geprüft. Alle fünf vorhandenen
Anwendungstabellen und die vorhandenen Artefakte blieben bei der Migration
inhaltlich unverändert. Nach Migration und Laufzeitabnahme wurde zusätzlich ein
Post-Migrationsbackup erstellt und restore-validiert.

## Noch offen

Arbeitsabschnitt 2 ist funktional umgesetzt. Stop/Start nach dem zuletzt ergänzten
Warten auf das Queue-Schema war erfolgreich; Worker, Sidecar, echte Bilder,
Serialität und Dateipersistenz wurden danach erneut geprüft. Als strengerer
Betriebsnachweis bleibt N01 aus einer vollständig frischen isolierten
DDEV-Projektkopie offen.

**Arbeitsabschnitt 3a wurde ausdrücklich beauftragt und umgesetzt:** getrennte
manuelle Bewertung bei `inconclusive`, Verwerfen mit Duplikatgrund, unabhängiger
Kontaktzeitpunkt und neue Bewertungshistorie. Technische Beobachtungen erhalten
manuelle Urteile und alte Schutzmarker. Verworfene Fälle werden im normalen
Arbeiten einschließlich wartender Screenshot-Aufträge ignoriert.
[Abnahme und Übernahme von 3a](abnahme-abschnitt-3a.md): 124 isolierte Tests mit
930 Assertions erfolgreich; Bestandskopie und anschließende lokale Migration
erhielten alle ursprünglichen Anwendungsdaten und Artefakte.

**Arbeitsabschnitt 3b wurde anschließend ausdrücklich beauftragt und umgesetzt:**
Übersicht und CLI unterscheiden aufgezeichnetes manuelles Urteil, letzte gespeicherte
technische Beobachtung und Kontaktzeitpunkt. Die kombinierbaren Filter und globalen
Zähler verwenden dieselbe lesende Projektion; ihre Links führen zu der jeweils
gezählten Menge. Alte Status-/Gruppenfilter bleiben als Diagnose erkennbar, das
Archiv umfasst auch historische Duplikat-/Verwerfungswerte.
[Abnahme von 3b](abnahme-abschnitt-3b.md) dokumentiert Mischbestands-, Paging- und
Leseprüfungen sowie lokale HTTP-/Browserabnahme. Keine Migration war nötig.

**Schneller Einzeleingang wurde am 2026-10-03 ausdrücklich beauftragt:** Der Nutzer
hatte erneut beobachtet, dass das Absenden einer einzelnen URL auf die technische
Prüfung wartet. Eine neue Eingabe speichert Finding und ersten ScreenshotJob
weiterhin atomar und bestätigt diese dauerhafte Speicherung anschließend sofort;
der Request wartet weder auf den Screenshot-Worker noch auf einen Retest. Exakte
Duplikate verweisen auf den bestehenden Fall. Der klassische POST kehrt ebenfalls
nach der Speicherung zurück, die bisherige Ansicht verwendet zusätzlich einen
schmalen JSON-Eingang und einen ausschließlich lesenden Statusabruf.

Das Formular wird nach bestätigter Speicherung wieder frei. Ein auf maximal 50
Einträge begrenzter Verlauf liegt in `sessionStorage` und bleibt beim Reload des
Tabs sichtbar. Fehlgeschlagene und nicht bestätigte Eingaben bleiben für eine
bewusste erneute Übernahme erhalten; ein unbekannter Requestausgang löst keinen
automatischen zweiten POST aus. Statusänderungen erscheinen im Verlauf, Toasts
nur im sichtbaren und fokussierten Tab und nach einem Reload nicht erneut für alte
Zustände. Polling liest ausschließlich persistierte Zustände und startet keine
Browserarbeit.

Der API-Umfang ist bewusst auf die klassische und die spätere parallele
Studio-Ansicht begrenzt. Eine allgemeine Drittclient-API, eine neue Queue für
technische Prüfungen und die Studio-Oberfläche selbst gehören nicht zu diesem
Abschnitt. [Vertrag, Nachweise und Grenzen](abnahme-schneller-eingang.md): 155
isolierte Tests mit 1.446 Assertions sowie der End-to-End-Browserlauf mit fünf
Einzeleingaben waren erfolgreich.

Weiter offen bleibt **4: Betriebsnachweise abschließen**,
einschließlich des frischen isolierten DDEV-Aufbaus. Die Regeln zum Abarbeiten von
Hinweisen folgen vor dem späteren Review-Paket.
[Zuschnitt und spätere offene Fragen](ui-workflow.md#erneutes-sparring-zum-zuschnitt-von-abschnitt-3).
Historische Mehrdeutigkeit bleibt erhalten, statt frühere Entscheidungen zu erfinden.

**Nächste Ausbaurichtung:** Nach dem schnellen Eingang kann Eingang und Bestand in
einer parallelen Resolve-/Studio-Ansicht angeordnet werden. Beide Oberflächen
verwenden dieselbe Fallverwaltung und dieselben schmalen Anwendungs- und
Leseregeln. Frameworkwahl, genaue Navigation und Auswahlkontext der Studio-Ansicht
bleiben offen; Review und Meldungen behalten ihre eigenen offenen Fachregeln.
[Entscheidung und Zuschnitt](ui-workflow.md#schneller-eingang-und-parallele-resolve-ansicht--sparring-nach-3b).

Weitere bekannte Grenzen:

- Standardmäßig werden Dialoge drei Sekunden nach `domcontentloaded` beobachtet;
  Capture und eine kurze 50-ms-Nachlaufphase fangen Dialoge an der Grenze ab.
- Neue Findings und der erste Queue-Auftrag werden in einer Transaktion gespeichert.
  Das erneute Einreichen einer exakten alten URL ergänzt einen fehlenden Auftrag,
  ohne vorhandene Nutzerdaten oder terminale Aufträge zu verändern.
- Der bestehende Domain-Upsert committed vor dieser Transaktion separat; ein
  Fehler im folgenden Commit kann eine leere Domainzeile, aber keinen Finding ohne
  Initialauftrag hinterlassen. Direkte ORM-Inserts außerhalb des FindingService
  besitzen diese Intake-Garantie nicht.
- Generische `app:retest:* --screenshot`-Befehle verwenden noch den direkten
  Legacy-Aufnahmepfad.
- Die Prozesslocks setzen den vereinbarten einzelnen DDEV-Web-Container voraus.
- Der Sidecar-Healthcheck prüft Node, nicht Chromium, Xvfb oder `ffmpeg`; eine
  eigene Docker-Restart-Policy ist nicht konfiguriert.
- `app:screenshot:missing` wählt fehlende Evidence-Zeilen, nicht vorhandene Zeilen
  mit inzwischen fehlender Datei. Dafür bleibt der Artefakt-Audit maßgeblich.
- Historische Bestandsprobleme bleiben sichtbar: 382 fehlende Dateireferenzen,
  vier nicht referenzierte Dateien und bereits vor der Migration vorhandene
  Fremdschlüsselverletzungen. Es fand keine automatische Bereinigung statt.

## Ausbau nach der Stabilisierung

Das [UI-Arbeitsmodell](ui-workflow.md) hält die bestätigte Richtung fest:

- Eingang mit verständlicher Meldung bei exakter doppelter URL; dieser einfache
  Fall ist bereits umgesetzt. Ähnlichkeitsregeln bleiben offen.
- Review mit Notizen, mehreren Sichtungsgründen und Vorher-/Nachher-Bildern,
  besonders bei `vulnerable -> inconclusive`.
- Bearbeitbare Meldungen, später Mailentwürfe über Codex CLI und Gruppierung nach
  Domain, Betreiber, Entwickler oder begründeter Ähnlichkeit.
- Bestand mit Dashboard, Suche und verständlichen Statistiken.

Diese Funktionen wachsen zunächst in der vorhandenen Oberfläche. Der spätere
Resolve-Umbau ändert Navigation und Anordnung, verwendet aber die erprobten
Anwendungsfälle und Daten weiter.

## Dokumente

- [Lastenheft und Arbeitsabschnitte](lastenheft.md)
- [Arbeitsmodell und Bestandsanalyse](design.md)
- [Abnahme Arbeitsabschnitt 1](abnahme-abschnitt-1.md)
- [Abnahme Arbeitsabschnitt 2](abnahme-abschnitt-2.md)
- [Abnahme Arbeitsabschnitt 3a](abnahme-abschnitt-3a.md)
- [Abnahme Arbeitsabschnitt 3b](abnahme-abschnitt-3b.md)
- [Abnahme schneller Einzeleingang](abnahme-schneller-eingang.md)
- [Backup und Wiederherstellung](backup.md)
- [Arbeitsbereiche und UI](ui-workflow.md)

Die ursprünglichen Befunde in `design.md` beschreiben den Ausgangspunkt und sind
als historische Analyse gekennzeichnet. Tatsächlicher Implementierungsstand und
offene Grenzen stehen in diesem Index und den Abnahmeprotokollen.

Die Architekturunterlagen liegen unter `architecture/`, weil das bestehende
`docs/`-Verzeichnis für den aktuellen Benutzer nicht beschreibbar war. Bestehende
Dateirechte wurden nicht verändert.
