# Arbeitsmodell und Bestandsanalyse

Stand: 2026-10-02. Bestandsanalyse mit anschließendem Umsetzungsstand.

**Aktualisierung:** Arbeitsabschnitt 1 ist ausdrücklich beauftragt, umgesetzt und
in DDEV geprüft. [Abnahme, Änderungen und Grenzen](abnahme-abschnitt-1.md).
Abschnitt 2 ist inzwischen ebenfalls beauftragt und umgesetzt:
[Persistente Screenshot-Queue und Funktionsabnahme](abnahme-abschnitt-2.md),
[Sicherung und Wiederherstellung](backup.md).
Die nachfolgenden Bestandsbefunde dokumentieren den Ausgangspunkt vor dieser
Umsetzung; insbesondere Speicherzugriffe, Testspeicher, GET-Bereinigung und der
destruktive Refresh-Vorlauf wurden überarbeitet. Screenshot-Aufnahme,
Serialisierung und DDEV-Prozessbetrieb wurden danach neu geordnet. Spätere
Architekturvorschläge bleiben als solche bestehen, sofern sie nicht ausdrücklich
als umgesetzt gekennzeichnet sind.

## Bestätigte Anforderungen

Grundlage: Nutzerauftrag und Antworten im Architekturgespräch vom 2026-10-02.

- Lokal nutzbare Oberfläche als persönliche Alternative zu OpenBugBounty.
- URL eingeben und den zugehörigen Fall mit Browseransicht und Screenshots bearbeiten.
- Zuverlässigkeit des gewachsenen Projekts durch Refactoring verbessern.
- Symfony und SQLite bleiben erhalten; Architektur im bestehenden Stack aufräumen.
- Gewünschter Screenshot: Seite samt sichtbarem Browserdialog, wenn vorhanden.
  Eine reine Seitenaufnahme erfüllt diese Anforderung nicht in jedem Fall.
- Auch ohne Dialog wird eine angeforderte Seitenaufnahme erstellt und aufbewahrt.
  Nutzerbegründung: `inconclusive` und andere Ergebnisse direkt anhand des
  Screenshots besser beurteilen können. Am 2026-10-02 ausdrücklich bestätigt;
  zuvor war die Aufbewahrung ohne Dialog offen. Abnahme in F03 des Lastenhefts.
- DDEV ist die tatsächliche Betriebsumgebung.
- Neue technische Ergebnisse überschreiben keine manuelle Bewertung. Die
  Bewertung bleibt erhalten; neue Beobachtungen werden ausschließlich als Hinweis
  angezeigt. Ausdrücklich bestätigt in der anschließenden Lastenheft-Rückfrage.
- Vorhandene Funktionen zuerst stabilisieren; neue Funktionen schrittweise in der
  bestehenden Ansicht nutzbar machen. Erst bei zwei bis drei brauchbaren
  Arbeitsbereichen zur Resolve-artigen Gestaltung wechseln und die erarbeiteten
  Funktionen übernehmen. Der Nutzer hat diese Reihenfolge im weiteren Sparring
  ausdrücklich präzisiert.

Die damalige automatische Verarbeitung im analysierten Ausgangscode war
beobachteter Bestand, keine vollständig bestätigte Spezifikation sämtlicher
Abläufe oder Zustandsübergänge.

Die anschließende UI-Diskussion ergänzt die bestätigte Richtung: Duplikate mit
Rückmeldung überspringen, Sichtung mit Notizen und Bildvergleich bei Änderungen,
bearbeitbare Meldungen mit Codex-CLI-Mailentwürfen, Gruppierung auch anhand
gemeinsamer Entwickler sowie Bestand/Dashboard/Statistiken. Entscheidungen,
Lösungsvorschläge und offene Regeln stehen im [UI-Arbeitsmodell](ui-workflow.md).
Die dortige Richtung erteilt noch keinen Implementierungsauftrag.

## Beobachteter Aufbau

Symfony 7, Doctrine ORM, SQLite und serverseitiges HTML. Ein Node-/Playwright-
Sidecar bietet HTTP-Schnittstellen. PHP speichert Metadaten und Bilder lokal.
Der große WebController enthält weiterhin Routing, Anwendungsabläufe, HTML/CSS,
Statusdarstellung und Dateiauslieferung; die frühere lesende Datenbereinigung
wurde entfernt.

Der Web-POST speichert einen Fall und seinen ersten Screenshot-Auftrag atomar und
wartet anschließend immer auf den getrennten headless Retest. DDEV betreibt einen
seriellen Symfony-Worker für Screenshots. CLI-Commands verwenden im älteren
Prüfpfad teils RetestService, teils ReviewService, mit unterschiedlichen
Voreinstellungen. Finding, ReviewState und RetestRun tragen weiterhin teilweise
überlappende Informationen.

Nützliche vorhandene Ansätze: DTOs, abstrahierter Browsertransport, Repositories,
Migrationen, deduplizierte URLs und ein EvidenceStorageInterface. Die
Screenshot-Queue verwendet die Dateischnittstelle durchgehend; ältere direkte
Retest-Screenshotoptionen bleiben ein separater Legacy-Pfad.

## Belegte Befunde und Grenzen

### Datenerhalt und Tests haben höchste Priorität

1. **Reset-Tests können echte Artefakte löschen.**
   `tests/ResetServiceTest.php:63` legt Testdateien unter `tests/storage/artifacts`
   an, verwendet aber den echten ResetService mit echtem Filesystem und ruft
   `resetAll()` auf. `src/Service/ResetService.php:47` löscht unabhängig von der
   Testkonfiguration das projektweite `storage/artifacts`. Dasselbe Problem
   besteht für `resetVerificationState()` ab Zeile 78. Nicht ausgeführt.

2. **Evidence refresh vernichtet zusätzlich fachliche Daten.**
   `src/Command/EvidenceRefreshCommand.php:280` löscht Evidence und Läufe und ruft
   `resetFreshStartState()` auf. `src/Service/FindingService.php:156` entfernt dabei
   auch Notizen, Melde- und Kontaktzeitpunkte. Die Löschung findet vor erfolgreichem
   Ersatz statt. Konkret belegter Datenverlustpfad; nicht ausgeführt.

3. **Dateispeicherung umgeht ihren eigenen Vertrag.**
   `src/Service/RetestService.php:126` verwendet feste Projektpfade statt des
   injizierten EvidenceStorageInterface; das Ergebnis von `file_put_contents`
   wird nicht geprüft. Evidence wird separat geflusht, bevor der Lauf gespeichert
   wird. Datei, Evidence und Lauf können auseinanderlaufen. Löschen und Ausliefern
   verwenden ebenfalls eigene Pfadberechnungen.

### Screenshot- und Bestandsbeobachtungen

SQLite `storage/database/app.sqlite` wurde mit `mode=ro` und `query_only` gelesen.
Es wurden keine Ziel-URLs oder privaten Notizen in diese Dokumentation übernommen.

| Beobachtung | Ergebnis |
| --- | --- |
| Fälle | 5.348 |
| Gespeicherte Läufe | 8.896 |
| Evidence-Einträge | 8.016, davon 67 Screenshots |
| Läufe mit Screenshot-Verweis | 315, alle im Juli 2026 |
| Letzter Lauf mit Screenshot-Verweis | 2026-07-23, Datum laut Datenbank |
| Läufe ab August mit Screenshot-Verweis | 0 |
| Letzte 50 Läufe | Jeweils Aufnahmefehler, kein Screenshot-Verweis |
| Fehlerkategorie dieser 50 Aufnahmen | Externes Aufnahmeprogramm nicht gefunden |
| Vorhandene Dateien hinter 67 Screenshot-Evidence-Verweisen | 0 am erwarteten lokalen Pfad |
| Vorhandene Dateien hinter 315 Lauf-Screenshot-Verweisen | 0 am erwarteten lokalen Pfad |

Neue Aufnahmefehler und fehlende alte Dateien sind unterschiedliche Probleme.
Fehlende Dateien belegen nicht, wann oder wodurch sie verschwanden. Reset-Tests
und destruktive Refreshes sind mögliche Ursachen, keine nachgewiesene historische
Erklärung. Frühere Speicherorte und Backups wurden nicht untersucht.
Die Aufnahmefehler belegen vergangene Läufe, nicht den Zustand eines aktuell
laufenden Containers; beim Review lief kein Docker-Container.

Aufnahmefehler liegen im Rohresultat. Normale Erfolgsmeldung und Lauftabelle
stellen sie nicht als eigenständiges Ergebnis dar. Ein abgeschlossener Lauf kann
erfolgreich aussehen, obwohl sein Bild fehlt. Die verschiedenen Einstiege liefern
zudem keinen einheitlichen Screenshot-Vertrag.

### Detailansicht, Zustände und Nachvollziehbarkeit

4. **Detailansicht verwendet keine öffentliche Doctrine-Methode.**
   `src/Controller/WebController.php:916` ruft `getEntityManager()` auf dem
   EvidenceRepository auf. In der installierten Doctrine-Version ist sie protected;
   PHP-Reflection bestätigt `public=no`. Der Repository-Magic-Call stellt hierfür
   keine gültige öffentliche API bereit. Der Aufruf erfolgt auch bei leerer
   Evidence-Liste. Die äußere Fehlerbehandlung würde zur Startseite umleiten.
   **Nachtrag:** Der Nutzer berichtet am 2026-10-02, dass die Detailansicht in
   DDEV funktioniert. Eine anschließende Prüfung im laufenden Web-Container ergab
   PHP 8.3.30, Doctrine ORM 3.6.7, DoctrineBundle 2.18.3 und Symfony 7.4.14.
   Container und Host haben denselben Controller-Dateihash. Die Methode ist auch
   im Container protected; ein isolierter Aufruf am Doctrine-Repository ohne
   Datenbankzugriff ergibt BadMethodCallException. Damit ist der API-Konflikt
   belegt, ein aktueller Ausfall der vom Nutzer verwendeten Ansicht aber nicht.
   Den tatsächlichen Route-/Aufrufpfad auf isolierten Daten prüfen, bevor eine
   Laufzeitursache behauptet wird. Kein HTTP-Aufruf gegen Nutzdaten durchgeführt.

5. **Lesende Ansicht enthält eine destruktive Bereinigung.**
   Derselbe Controllerpfad soll fehlende Dateien durch Löschen der Evidence-Zeile
   behandeln. Bei erfolgreichem Durchlaufen würde ein GET Daten verändern und
   Diagnoseinformationen beseitigen. Fehlende Datei und ungültiger Beleg sind
   verschiedene Sachverhalte.

6. **Fachlicher Zustand und technische Beobachtung sind vermischt.**
   RetestService, ReviewService, FindingService und UI-/Repository-Buckets tragen
   jeweils Teile der Statuslogik. Im Bestand existiert ein Fall mit
   `status=verified` und `review_state=confirmed_fixed`. Das belegt widersprüchliche
   Werte, nicht den historischen Verursacher. Die maßgebliche manuelle Entscheidung
   ist aus solchen Kombinationen nicht zuverlässig abzuleiten.

7. **Auftrags- und Zeitdarstellung ist unvollständig.**
   Der normale RetestService persistiert einen Lauf erst nach Rückkehr des
   Transports. Bei Transportausnahmen kann ein dokumentierter Versuch fehlen.
   Bei `recordBrowserResult()` beginnt die gespeicherte Uhr erst nach der
   Browserarbeit. Web-Eingaben warten synchron auf die gesamte Verarbeitung.

8. **Konfiguration, Commands und Dokumentation widersprechen sich.**
   Das Review-Timeout aus den Einstellungen gilt nicht einheitlich. README und
   UI-Changelog beschreiben teils andere Abläufe als der Code. Die PHP-Tests
   verwenden überwiegend simulierte Browserantworten; ein Vertragstest für einen
   tatsächlich lesbaren Bildbeleg samt Fehlerdarstellung fehlt im untersuchten
   Bestand. Ein grüner PHP-Unit-Test wäre kein Nachweis einer funktionierenden Aufnahme.

Priorisierte Zustandsanalyse, kein vollständiges Audit. Kein behauptetes
Testergebnis: Die Suite wurde wegen der Speicherprobleme nicht ausgeführt.

## Aus der Ausgangsanalyse abgeleitete Zielregeln

Diese Liste war bei der ersten Analyse ein Vorschlag. Die Punkte zu Speicherung,
Belegverfügbarkeit, Dateiablage, Fehlern und UI-/CLI-Neutralität wurden inzwischen
in das Lastenheft übernommen und für die Abschnitte 1 und 2 umgesetzt. Die
durchgehende Trennung aller technischen Beobachtungen von manuellen Bewertungen
bleibt Abschnitt 3.

- Das Speichern eines Falls bleibt unabhängig vom Gelingen weiterer Arbeit.
- Eine angeforderte Aufnahme hat einen sichtbaren Zustand: ausstehend, verfügbar,
  fehlgeschlagen oder bewusst ausgelassen, jeweils mit Grund und Zeitpunkt.
- Fehlende Dateien bleiben diagnostizierbar; Lesen verändert keine Daten.
- Neue Belege ergänzen den Verlauf. Alte Belege, Notizen und Kontaktinformationen
  bleiben erhalten, bis eine ausdrücklich dafür bestimmte Löschaktion erfolgt.
- Technische Beobachtung, manuelle Bewertung und Kontaktverlauf sind getrennt.
- Jeder Beleg hat nachvollziehbare Herkunft; manuelle/importierte Belege brauchen
  keinen künstlich erzeugten Browserlauf.
- Eine Dateispeicher-Abstraktion gilt für Schreiben, Lesen, Existenzprüfung und
  Löschen. Tests und Nutzdaten haben getrennte Speicherwurzeln.
- Fehler bei Dateiablage oder Metadatenspeicherung erzeugen keinen falschen Erfolg.
  Konsistente Backups und Wiederherstellung gehören zur Datenhaltung.
- UI und CLI verwenden dieselben fachlichen Regeln und Begriffe.

## Architekturmodell und umgesetzte Queue-Entscheidung

**Modularer Monolith mit wenigen klaren Außengrenzen.** Symfony bleibt Anwendung
und Darstellung, SQLite der Metadatenspeicher, lokaler Dateispeicher die Ablage für
Belege. Die Browserkomponente ist eine externe technische Schnittstelle. Ihre
Beobachtungen sind Eingaben für die Fallverwaltung, keine fachlichen Wahrheiten.

Sinnvolle Verantwortungsbereiche:

- **Fallverwaltung:** URLs, Notizen, manuelle Bewertungen, Kontaktinformationen.
- **Belegverwaltung:** Dateien, Herkunft, Integrität und Verfügbarkeit.
- **Technische Laufdaten:** Zeitpunkte, Beobachtungen und verständliche Fehler.
- **Darstellung:** schmale Controller, Twig-Templates und eine gemeinsame Ableitung
  der sichtbaren Statusangaben.

UI und CLI rufen dieselben Anwendungsfälle auf. Zustandsregeln lassen sich als
reine Funktionen ohne Browser, HTTP oder Dateisystem prüfen. Doctrine bleibt für
Persistenz zuständig; eine zusätzliche Repository-Hierarchie ist nicht automatisch
nötig. Schnittstellen lohnen vor allem an tatsächlichen Außengrenzen wie Dateispeicher.

Ein begrenztes Architektur-Experiment: unveränderliche Beleghistorie plus gesonderte
aktuelle Fallansicht. So bleiben historische Beobachtung und heutige Bewertung
unterscheidbar, ohne das ganze System auf Event Sourcing umzustellen. Vorschlag,
keine getroffene Entscheidung.

Für sichtbare Screenshot-Aufnahmen ist die getrennte Verarbeitung inzwischen
beschlossen und umgesetzt. Ein `ScreenshotJob` liegt persistent in derselben
SQLite-Datenbank und durchläuft `queued`, `running`, `available` oder `failed`.
Ein über DDEV/Supervisor betriebener Symfony-Worker beansprucht FIFO genau einen
Auftrag, wartet vor dem Claim auf den Playwright-Sidecar und ruft dessen neutralen
`POST /screenshot`-Endpunkt auf. Die Aufnahme ergänzt Evidence und Auftragsmetadaten,
ohne Retest oder Finding-Bewertung auszuführen. Dadurch bleibt die Browserkomponente
eine technische Außengrenze und es wird kein zusätzlicher Broker benötigt.

Ein eindeutiger aktiver Schlüssel schützt pro Finding gegen doppelte aktive
Aufträge. PHP-Sperren schützen Capture, Queue-Mutationen und Maintenance im
vereinbarten einzelnen Web-Container; die Node-Anzeigelease ist die letzte
Serialisierungsgrenze des gemeinsamen Xvfb-Desktops. Unterbrochene Arbeit wird
begrenzt wiederholt, Aufnahmefehler bleiben terminal sichtbar. Details und
belegte Fehlerfälle stehen in [Abnahme Abschnitt 2](abnahme-abschnitt-2.md).

Die aktuelle SQLite-Verbindung erzwingt deklarierte Fremdschlüssel nicht. Deshalb
ist das Löschen eines Findings ebenfalls ein Anwendungsfall: Unter dem
Maintenance-Lock werden aktive und terminale ScreenshotJobs ausdrücklich entfernt,
ebenso Evidence und RetestRuns, bevor das Finding gelöscht wird. Eine direkte
Datenbanklöschung besitzt diese Garantie nicht. Das Gegenrennen wird ebenfalls
abgesichert: Queueing revalidiert das Finding innerhalb des Queue-Locks und nutzt
`INSERT ... SELECT`, sodass eine nach der Löschung veraltete Entity-Referenz keinen
ScreenshotJob erzeugt.

Für den bestätigten schrittweisen Ausbau empfiehlt sich: Anwendungsregeln beim
jeweiligen Funktionsumbau aus der Darstellung herauslösen, vorhandene Ansichten
daran anbinden und geeignete Twig-Bausteine weiterverwenden. Es braucht dafür
keine vorsorgliche separate Frontend-API. Jede Erweiterung liefert einen in der
aktuellen Anwendung nutzbaren Ablauf. Ein späterer Wechsel von Navigation und
Anordnung nutzt dieselben Anwendungsfälle und Daten; er kann dennoch eigene
Arbeit für Auswahlkontext, Bedienung und Darstellung erfordern.

## Alternativen und Voraussetzungen

- Nur Controller/Templates trennen: kleiner Einstieg, löst weder Datenverlust
  noch widersprüchliche Status- und Speicherverträge.
- Mehrere eigenständige Dienste: zusätzliche Betriebs- und Schnittstellenkosten,
  bisher kein belegter Bedarf. Neu bewerten bei mehreren Nutzern oder entfernten
  Installationen, nicht allein wegen der Anzahl gespeicherter Fälle.
- Vollständiges Event Sourcing: Migration und Versionierung wären teuer. Eine
  Beleg-/Entscheidungshistorie genügt möglicherweise; abhängig vom Auditbedarf.
- Eine vollständig synchrone Aufnahme wurde für den Eingang verworfen, weil
  mehrere Fälle ohne Warten auf den sichtbaren Browser erfasst werden sollen.
  Die unmittelbare headless Verifikation bleibt synchron und getrennt; die frühere
  `cron_only`-Einstellung wird im UI-Eingang nicht mehr berücksichtigt.

## Umgesetzte Stabilisierung

Prüfbares Ergebnis: Bestehenden oder manuell angelegten Fall öffnen, vorhandene
Belege sehen, fehlende Belege erklärt bekommen und Notizen/Kontaktstand bei weiterer
Bearbeitung behalten. Tests arbeiten ausschließlich in temporärer Ablage.

Abnahmevorschläge für diese begrenzte Stabilisierung:

1. Detailansicht funktioniert mit und ohne Evidence; GET verändert keine Datensätze.
2. Fehlende Datei führt zu sichtbarer Information statt gelöschtem Beleg.
3. Ein importiertes harmloses Testbild bleibt nach erneutem Öffnen lesbar.
4. Ablagefehler erscheinen als Fehler; Metadaten behaupten kein vorhandenes Bild.
5. Tests einschließlich Löschfällen lassen eine Kontroll-Datei außerhalb ihrer
   Speicherwurzel unberührt.
6. Änderungen technischer Daten löschen weder Notizen noch Kontaktzeitpunkte.

Dieser erste Abschnitt wurde beauftragt und umgesetzt; Nachweise stehen im
[Abnahmeprotokoll](abnahme-abschnitt-1.md). Die anschließende Browser-/Dialogarbeit
wurde als persistente serielle Queue umgesetzt und anhand kontrollierter lokaler
Seiten mit und ohne Dialog geprüft. Sie umfasst keine Optimierung automatisierter
Schwachstellenreproduktion gegen externe Ziele.

Als nächster fachlicher Abschnitt bleibt die durchgehende Trennung von technischer
Beobachtung, manueller Bewertung und Sichtungsbedarf. Der Screenshot-Pfad erfüllt
diese Neutralität bereits; die gewachsenen allgemeinen Retest-/Review-Pfade noch
nicht. Vor einer Übergabe sind manuelle Urteile und reine
„Hinweis geprüft“-Aktionen zu klären, wie im [UI-Arbeitsmodell](ui-workflow.md)
beschrieben.

## Entscheidende offene Punkte

Das angeforderte [Lastenheft](lastenheft.md) beschreibt den Zielzustand und die
Abnahme genauer. Es kennzeichnet bestätigte Vorgaben und vorgeschlagene Anforderungen.
Die technische Bestandsanalyse bleibt hier, damit Beobachtung und Soll nicht
vermischt werden.

Neue UI-Richtung: [Arbeitsbereiche und Duplikatbehandlung](ui-workflow.md).
Resolve-artige Pages für Erfassen, Sichten und Mailvorbereitung bleiben das
spätere Ziel. Bestätigt ist der Vorrang funktionierender Abläufe in der aktuellen
Ansicht; der zunächst vorgeschlagene frühe UI-Prototyp ist zurückgestellt.
Die konkreten dortigen Seiten- und Modellvorschläge bleiben Vorschläge.

- **Geklärt:** Eine neue technische Beobachtung hebt eine manuelle Entscheidung
  nicht auf. Entscheidung erhalten und neue Beobachtung als Hinweis zeigen;
  Details und Abnahme stehen in F06 des Lastenhefts.
- **Geklärt:** Angeforderte Aufnahmen entstehen auch ohne sichtbaren Dialog,
  insbesondere bei `inconclusive`, und bleiben zur manuellen Beurteilung erhalten.
  Fehlender Dialog oder uneindeutiges Ergebnis sind keine Auslassungsgründe.
- Persönliche lokale Installation oder später gemeinsame Bearbeitung? Erst
  Letzteres begründet weitergehende Nutzer-/Betriebsmodelle.
- Wie wichtig sind lückenlose Entscheidungsverläufe und Wiederherstellung älterer
  Stände? Davon hängt die Tiefe einer Historie ab.
