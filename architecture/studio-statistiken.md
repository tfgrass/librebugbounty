# Studio-Statistiken v1 – Umfang und Datenvertrag

## Aktueller Folgeauftrag vom 2026-10-04

Der Nutzer verwendet inzwischen „Kontaktiert“ und verlangt, „Versendet“ aus dem
Dashboard zu entfernen. Die Zahlen für Ingest, Kontakte und Behebungen liegen
nach seiner Beobachtung inzwischen in einer vergleichbaren Größenordnung;
deshalb sollen sie gemeinsam in einem Diagramm stehen. Dieser Auftrag ersetzt
die unten dokumentierte frühere Entscheidung für getrennte Diagramme und eine
eigene Versandreihe. Die weiteren Abschnitte halten den damaligen Stand fest.

Umgesetzt sind genau drei Aktivitätsreihen: **Gemeldet** (Ingest),
**Kontaktiert** (gespeicherte Kontaktmarkierung) und **Behoben** (erste
aufgezeichnete manuelle Behebungsbewertung). Sie teilen eine Zeitachse und eine
beschriftete Zahlenskala. Alle drei Reihen sind zunächst sichtbar; Farben und
Linienmuster unterscheiden sie. Ein- und Ausblenden verändert die Skala nicht.
Tooltip, Tastaturbedienung, verlinkte Wertetabelle und Darstellung ohne
JavaScript bleiben vorhanden.

Die vier oberen Kennzahlen zeigen Gemeldet, Kontaktiert, Behoben und
unterschiedliche Hosts. Auch der Jahreskalender bietet nur die drei
Aktivitätsreihen. Bestätigte aktive Fälle gelten als offene Kontaktarbeit,
solange `contacted_at` fehlt; ein separater Versandmarker beeinflusst diese
Auswertung nicht mehr. Historische Versandzeitpunkte bleiben unverändert
gespeichert und werden nicht zu Kontakten umgedeutet. Die vorhandenen Detail-,
Listen-, Export- und Schreibverträge für Versanddaten bleiben bestehen.

Historische Kontakte bleiben am gespeicherten Datum sichtbar. Undatierte
Behebungsmarker erscheinen weiterhin gesondert im historischen Bestand und
erhalten keinen erfundenen Ereignistag. Alte Kalenderbookmarks mit
`heatmapMetric=sent` wechseln zur Kontaktansicht; `confirmed` wechselt zur
Ingestansicht.

## Historischer Stand vom 2026-10-03

Stand: 2026-10-03. Der Nutzer hat nach dem Sparring ausdrücklich beauftragt:
„setzte das dashboard um“. Die eigene Statistikseite und die folgenden
Zählregeln sind umgesetzt; die [Laufzeit- und Browserabnahme](abnahme-studio-statistiken.md)
ist erfolgreich. Weitere Ideen bleiben spätere Möglichkeiten.

## Wunsch des Nutzers

Eine eigene, visuell ausgearbeitete Seite „Dashboard“ oder „Statistiken“, erreichbar
über die untere Studio-Navigation. Gewünscht sind Tagesauswertungen für erfasste
bzw. gemeldete und kontaktierte Fälle, Wochen-/Monats-/Jahresübersichten,
Liniendiagramme und eine TLD-Verteilung als Tortendiagramm. Auf die Ideensammlung
folgte der ausdrückliche Umsetzungsauftrag für dieses Dashboard.

**Folgeauftrag:** Die dominierende Ingest-Linie ließ Kontakte kaum erkennen.
Der Nutzer verlangt eine lesbarere Darstellung und die rückwirkende Einbeziehung
der vorhandenen Daten. Umgesetzt sind ausgerichtete Einzelreihen mit eigener
beschrifteter Skala, standardmäßig sichtbare Kontakte und eine gesonderte
Anzeige erhaltener historischer Markierungen ohne belastbares Datum.

**Bestätigte Begriffe:** Der Nutzer hat klargestellt: „Gemeldet pro Tag“ bedeutet
neue Fälle aus dem Ingest; „Versendet pro Tag“ bezeichnet Meldungen an Betreiber.
Die Beschriftung „Gemeldet“ sagt damit nichts über eine externe Meldung oder eine
bestätigte Sicherheitslücke aus. Exakte doppelte Eingaben sind keine neuen Fälle.

## Beobachteter Ausgangspunkt

Die aktuelle Arbeitskopie enthält Studio-Eingang unter
`/`, Studio-Bestand unter `/findings`, Studio-Falldetail unter `/findings/{id}`
und klassische Ansichten unter `/legacy`. Eingang, Bestand und Detail verwenden
die untere Arbeitsbereichnavigation. Der Routenwechsel ist inzwischen in
[Studio-Bestand und Routen](studio-bestand-routen.md) als bestätigt und abgenommen
dokumentiert.

Für Statistiken sind folgende Daten vorhanden:

| Aussage | Vorhandene Grundlage und Grenze |
| --- | --- |
| Gemeldet / neu im Ingest | `finding.submitted_at`, bei fehlendem Wert `created_at`; `submittedAt` wird bei normaler Neuanlage gesetzt. Das ist kein Nachweis einer Meldung an den Betreiber. |
| Als kontaktiert markiert | `finding.contacted_at`; der aktuelle Schreibweg erhält den gespeicherten Zeitpunkt. Frühere Versionen konnten ihn bei erneuter Markierung ersetzen. Historische Daten belegen deshalb das erhaltene Markierungsdatum, nicht zuverlässig den Erstkontakt. Zählt Fälle, keine Mails, Follow-ups oder eindeutigen Betreiber. |
| Manuell bestätigt/behoben/verworfen | `finding_assessment.assessed_at` und Urteil. Neue Bewertungen haben Historie; bei historischen Statuswerten fehlen gegebenenfalls Zeitpunkt und Entscheidungsquelle. |
| Heutiger Bestand | `FindingReadRepository`: Bewertung, technische Beobachtung und Kontakt bleiben unabhängige Dimensionen; Archiv/Duplikate sind ausdrücklich auswählbar. |
| TLDs | Aus normalisiertem `domain.hostname` ableitbar. Fälle und unterschiedliche Hosts sind verschiedene Zähleinheiten. |

`reported_at` und `notified_owner_at` existieren, werden im normalen aktuellen
UI-/CLI-Eingang aber nicht gepflegt. Für die Statistik wurde ein ausdrücklicher
Erfassungsweg ergänzt: „Als versendet markieren“ im Studio-Falldetail speichert
den ersten Versandzeitpunkt in `notified_owner_at`. Eine bedingte SQL-Aktualisierung
erhält diesen Zeitpunkt auch bei wiederholten oder konkurrierenden Requests.
Die Aktion dokumentiert eine bereits versendete Meldung und versendet selbst
keine Mail. Sie ist CSRF-geschützt und verändert weder Kontaktmarker noch
Bewertung, technischen Status, Belege oder Aufträge. Bestehende Kontaktmarker
werden nicht nachträglich in Versandereignisse umgedeutet. Eine Migration ist
nicht nötig.
Antwortzeit, Follow-ups, Prämien und Betreibergruppen haben noch keine geeignete
strukturierte Grundlage. Typ und Schwere sind oft Standardwerte
(`reflected_xss`/`medium`); solche Diagramme sind aktuell wenig aussagekräftig.

Belege: `src/Entity/Finding.php`, `src/Entity/FindingAssessment.php`,
`src/Service/FindingService.php`, `src/Repository/FindingReadRepository.php`,
`src/Controller/StudioController.php` und `templates/studio/`.

Die rein lesende Bestandsprüfung am 2026-10-03 fand 5.493 Fälle mit Ingestdaten
ab 29.06.2026 und 2.808 datierte Kontaktmarker ab 09.07.2026. Ein Fall hatte
einen ausdrücklichen Versandzeitpunkt. Bewertungshistorie und neue manuelle
Bewertungsfelder waren leer; erhalten sind 60 `confirmed_fixed`-Marker,
20 `manually_checked`-Marker und 110 rohe `fixed`-Statuswerte. Diese Gruppen
überschneiden sich und dürfen nicht addiert werden. Der frühere Kontakt-Schreibweg
in Commit `e07eed9` ersetzte das Datum bei erneuter Markierung. Das sind Befunde
dieses Bestands, keine unveränderlichen Produktzahlen.

## Implementierter Aufbau

Name im Menü: **Statistiken**. Route: `/statistics`, Alias `/studio/statistics`.
Das dunkle Studio-Design mit großzügiger Diagrammfläche und klaren Akzentfarben
weiterführen. Hauptdiagramm über die volle Breite; darunter auf breiten Fenstern
zwei Karten nebeneinander, bei halbbreiten Fenstern stapelbar. Eine vertikal
scrollbare Statistikfläche erlaubt genügend Raum ohne horizontales Seitenscrollen;
die Arbeitsbereichnavigation bleibt erreichbar.

Oben stehen Zeitraumwahl, Zurück/Vorwärts und fünf kompakte Kennzahlen:
gemeldet, kontaktiert, versendet, erstmals manuell behoben, unterschiedliche Hosts
mit neu erfassten Fällen. Zeiträume: diese Woche, dieser Monat, ausgewähltes Jahr,
gesamter gespeicherter Zeitraum und freie Auswahl. Zusätzlich eine ausdrücklich
beschriftete Umschaltung Tag/Woche/Monat; auch ein ganzes Jahr kann täglich
dargestellt werden. Laufende Zeiträume werden mit gleich langen verstrichenen
Zeitspannen des Vorzeitraums verglichen; abgeschlossene Zeiträume mit dem ganzen
vorherigen Kalenderzeitraum. Bei unterschiedlichen Monatslängen wird die laufende
Vergleichsspanne auf die verfügbare Länge begrenzt und sichtbar erklärt.

| Element | Aussage und Darstellung |
| --- | --- |
| Aktivität im Zeitraum | Ausgerichtete Einzelreihen mit gemeinsamer Zeitachse und ausdrücklich eigener Zahlenskala: gemeldet (Ingest), kontaktiert, versendet (an Betreiber), erstmals manuell behoben; erstmals manuell bestätigt optional einblendbar. Absolute Tageswerte, Tooltip mit Datum und Werten sowie verlinkte Tabelle. Kleine Reihen bleiben bei starkem Ingest sichtbar; die Linienhöhen sind wegen unterschiedlicher Skalen nicht direkt vergleichbar. |
| Jahreskalender | Kalender-Heatmap, auswählbar für Gemeldet oder Versendet; Kontaktmarkierungen sind eine weitere getrennte mögliche Reihe. Macht intensive Wochen und Pausen direkt sichtbar. |
| TLD-Verteilung | Donut für im Zeitraum neu erfasste Fälle; Umschaltung Fälle/unterschiedliche Hosts, größte fünf TLDs plus Sonstige, absolute Werte und Prozentanteile. |
| Stand heute | Manuelle Bewertungen als klar getrennte Bestandsverteilung; zusätzlich bestätigt und noch nicht kontaktiert. Technische Beobachtungen separat anzeigen. |
| Offene Kontaktarbeit | Bestätigte, aktive Fälle ohne Kontakt- oder Versandzeitpunkt nach Alter seit Erfassung: bis 7, 8–30 und über 30 Tage. Bereits versendete Fälle sind keine offene Kontaktarbeit, auch wenn der separate Kontaktmarker fehlt. |
| Historischer Bestand | Gesamtzahl datierter Kontakte und frühestes erhaltenes Kontaktdatum; ältere `confirmed_fixed`-/`manually_checked`-Marker und rohe `fixed`-Statuswerte ohne aufgezeichnetes neues manuelles Urteil jeweils gesondert. Zeitraumunabhängig, TLD-gefiltert, einschließlich Archiv und mit passenden Falllinks. Keine Datumsrekonstruktion; die Markergruppen können sich überschneiden. |

**Umsetzungsentscheidung zur Zähleinheit:** Der zuletzt vorgeschlagene Zuschnitt
wurde nach dem Umsetzungsauftrag verwendet: Für vergleichbare Hauptlinien jeweils
Fälle zählen. „Versendet“ zählt erstmals an Betreiber gemeldete Fälle am Tag ihres
ersten Versands. Eine Sammelmail mit fünf Fällen ergibt fünf versendete Fälle;
Mailanzahl und Follow-ups bleiben spätere eigene Kennzahlen. Grundlage ist der
ausdrücklich festgehaltene Erstversandzeitpunkt je Fall, keine Schätzung aus Notizen.

Kennzahlen und Diagramme öffnen passende Falllisten. Der gemeinsame Listenvertrag
ist um `event`, `from`, `to`, `tld`, `sent` und den Diagnosefilter `legacy_review`
erweitert. Ereignisfilter unterscheiden
Ingest, Erstversand, gespeicherte Kontaktmarkierung und die erste passende manuelle Bewertung; die
datierte Auswahl bleibt im URL-/Formular-/Rückkehrkontext erhalten. Hosts zählen
unterschiedliche vollständige Hostnamen und haben keinen irreführenden Link auf
eine größere Fallmenge. Gleiches gilt für Hostsegmente und „Sonstige“ im Donut.

## Weitere Ideen, nicht alle für v1

- **Kumulierte Aktivität:** gleiche Ereignisse als aufsummierte Linien anzeigen.
  Macht den eigenen Fortschritt über Monate sichtbar. Beschriftung auf den
  erhaltenen Datenbestand begrenzen.
- **Zeit bis zur Kontaktmarkierung:** Median zwischen Erfassung und gespeicherter
  Kontaktmarkierung, Zahl auswertbarer Fälle und noch unkontaktierte Fälle
  daneben. Die Kennzahl beschreibt nur die auswertbare kontaktierte Teilmenge;
  sie ist keine erwartete Wartezeit für alle Fälle. Historische Marker können
  erneute Markierungen darstellen und erlauben keine gesicherte Erstkontaktzeit.
- **Monatsrückblick:** kompakte Zusammenstellung der Aktivität mit Veränderung
  zum vergleichbaren Vorzeitraum und noch offenem Arbeitsbestand.
- **Zeit bis zur manuellen Behebungsbestätigung:** später für Fälle mit
  belastbarem Kontakt- und Bewertungsverlauf; Zeitpunkt der Bestätigung ist
  nicht zwingend der tatsächliche Zeitpunkt der Reparatur.
- **Antworten und Meldungsergebnisse:** interessant, sobald Meldungen und
  Rückmeldungen eigenständig erfasst werden. Nicht aus Notizen schätzen.
- **Betriebsübersicht:** Screenshot-Wartezeit und fehlgeschlagene Aufträge als
  späterer kompakter Zusatz, falls dafür im Alltag ein Bedarf entsteht.

## Vorgeschlagene Zählregeln und Grenzen

- Ereigniskurven verwenden das jeweilige Ereignisdatum. Ein Fall mit heute
  gespeichertem Kontaktmarker zählt heute als Kontaktmarkierung, auch wenn er
  vor Monaten erfasst wurde. Die Kontaktzahl ist keine Kontaktquote der im gleichen Zeitraum erfassten
  Fälle. Kontaktquote braucht eine gemeinsame Erfassungskohorte.
- Bestandskarten zeigen ausdrücklich **Stand heute**. Eine Zeitraumwahl erzeugt
  keinen rückwirkend rekonstruierten Fallstatus.
- Für bestätigt/behoben je Fall die erste aufgezeichnete passende manuelle
  Bewertung verwenden. Wiederholte identische Bewertungen erzeugen heute weitere
  Historienzeilen und sollen die Zahl der Fälle nicht erhöhen. Spätere Revisionen
  ändern die Ereignisaussage nicht, können aber den heutigen Bestand verändern.
- „Behoben“ ist ein ausdrückliches manuelles Urteil. Technisches `fixed` bedeutet
  nach den bestehenden Regeln „Kein Nachweis“ und ersetzt dieses Urteil nicht.
- Aktivität umfasst den gesamten erhaltenen Bestand inklusive Archiv, damit
  späteres Verwerfen die dokumentierte Tätigkeit nicht versteckt. Bestandskarten
  verwenden ausdrücklich den aktiven Bestand. Jede Anzeige nennt ihren Umfang.
- Tage ohne datierte Ereignisse haben Nullwerte. Undatierte historische
  Entscheidungen erhalten keinen erfundenen Tag und werden als unbekannt
  kenntlich gemacht. `updated_at` taugt nicht als Ersatz für Ereigniszeitpunkte.
- Löschungen und bestimmte Resets entfernen Daten bzw. Zeitpunkte. Das Dashboard
  ist eine Auswertung des erhaltenen Bestands, kein unveränderliches Lebenszeitkonto.
- Die sichtbare Tagesgrenze ist Europe/Berlin. DDEV-PHP wurde mit
  `date_default_timezone_get()` ebenfalls als Europe/Berlin geprüft. Die bestehenden
  offsetlosen Doctrine-Zeitwerte werden mit der Laufzeitzeitzone eingelesen und
  für die Darstellung nach Berlin umgerechnet; Listenfilter rechnen dieselben
  Grenzen in die Speicherzeitzone zurück. Eine Änderung der Speicherzeitzone ist
  kein automatisch unterstützter Datenmigrationsweg.
- TLD bezeichnet die letzte DNS-Endung, beispielsweise `.uk`; `.co.uk` wäre eine
  Public-Suffix-Auswertung. IPs und lokale Hosts separat ausweisen. Eine TLD ist
  keine gesicherte Aussage über das Land eines Betreibers.

## Gelieferter Zuschnitt und weitere Grenzen

Das v1-Arbeitspaket liefert die eigene Seite, untere Navigation,
Zeitraumauswahl, Kennzahlen, Aktivitätslinien, Jahres-Heatmap, TLD-Donut und einen
kompakten heutigen Arbeitsbestand mit Altersverteilung. Zweck: Aktivität
über Tage/Monate nachvollziehen und offene Arbeit erkennen. Die Auswertung liest
SQLite und übernimmt dieselben fachlichen Bestandsdefinitionen wie die Liste;
`StatisticsRepository` liest skalare Fakten, `StatisticsService` aggregiert sie
und `StatisticsPeriod` beschreibt die Kalenderintervalle. Native SVGs, isoliertes
CSS und kleines JavaScript ergänzen das Symfony-Template; es gibt keine externe
Diagrammbibliothek oder zusätzliche Frontend-Anwendung. Lange Zeiträume werden
ausdrücklich auf höchstens 730 Diagrammgruppen verdichtet; ein Schaltjahr bleibt
auf Wunsch vollständig als 366 Tageswerte sichtbar. Der Jahreskalender ist auf
das volle Kalenderjahr des Ankers bezogen und berücksichtigt den TLD-Filter.

Die Abnahme prüft insbesondere gleiche Ergebnisse zwischen Kennzahl,
Diagramm und verlinkter Liste, korrekte Zeitraumgrenzen, Kontaktzählung ohne
Doppelzählung, explizite historische Datenlücken sowie bedienbare Diagramme bei
640–960 CSS-Pixeln. Der Aufruf der Seite bleibt rein lesend.

**Geklärt durch die Nutzerantwort:** „Gemeldet“ ist Ingest; Meldungen an Betreiber
heißen „Versendet“. Die bisher offene Begriffsfrage ist abgeschlossen.

Der Versandmarker hält den jetzigen Zeitpunkt der Bestätigung fest; rückwirkende
Datumsbearbeitung und eine Historie einzelner Mails oder Follow-ups gehören nicht
zu v1. Antwortzeiten, Prämien und allgemeine Meldungsverwaltung bleiben späteren
Vorhaben vorbehalten.
