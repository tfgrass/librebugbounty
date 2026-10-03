# Lastenheft: LibreBugBounty stabilisieren und strukturieren

Stand: 2026-10-03 · Version 0.9 · Arbeitsfassung für die Umsetzung mit Codex

Umsetzungsstand: Arbeitsabschnitte 1 und 2 wurden ausdrücklich beauftragt und
umgesetzt. [Abnahme Abschnitt 1](abnahme-abschnitt-1.md),
[Abnahme Abschnitt 2](abnahme-abschnitt-2.md), [Backup-Nachweis](backup.md).
Abschnitt 2 besitzt jetzt eine persistente, seriell verarbeitete Screenshot-Queue
mit echten Aufnahmen mit und ohne Dialog. Seine Funktionsabnahmen sind erfolgt;
Stop/Start nach dem jüngsten Startup-Schutz ist nachgewiesen. Nur der strenge
N01-Nachweis aus einer vollständig frischen Projektkopie bleibt offen. Weitere
Abschnitte sind nicht automatisch beauftragt oder abgenommen.

Anschließend wurde der geklärte Teilabschnitt 3a ausdrücklich beauftragt und
umgesetzt: [Bewertung, Kontaktzeitpunkt, Verwerfen und Historie](abnahme-abschnitt-3a.md).
Abschnitt 3b wurde danach ebenfalls ausdrücklich beauftragt und umgesetzt:
[Bestand konsistent lesen und filtern](abnahme-abschnitt-3b.md).
Am 2026-10-03 wurde außerdem der [schnelle Einzeleingang](abnahme-schneller-eingang.md)
ausdrücklich beauftragt und umgesetzt. Danach wurde der schmale erste Studio-
Arbeitsbereich als nächstes vorzubereitendes Vorhaben gewählt und anschließend
umgesetzt: [Studio-Ingest v1](abnahme-studio-ingest.md). Das spätere
Review-Paket wurde danach ebenfalls umgesetzt. Der Nutzer hat anschließend den
Commit und drei Grundlagen beauftragt: übrige Formularaktionen absichern,
frischen DDEV-Betrieb nachweisen und neue Hinweise nach einem Urteil im Review
bearbeiten. Kontakte bleiben zurückgestellt.

## 1. Zweck und Verbindlichkeit

LibreBugBounty soll als lokale Fall- und Belegverwaltung zuverlässig nutzbar sein:
URL erfassen, den Fall bearbeiten, Browseransichten dokumentieren, Bilder und
Notizen wiederfinden und die eigene Bewertung nachvollziehen.

Dieses Lastenheft beschreibt gewünschte Ergebnisse und Abnahmebedingungen.
Es ersetzt weder die Bestandsprüfung noch einen technischen Umsetzungsplan.
Das Erstellen des Lastenhefts allein autorisierte keine Implementierung. Anschließend
wurden Arbeitsabschnitt 1 und später Abschnitt 2 samt Sicherung ausdrücklich
beauftragt und umgesetzt.

**Vom Nutzer bestätigt:** lokale Anwendung; Symfony und SQLite behalten;
Architektur aufräumen; Screenshots sollen die Seite einschließlich eines sichtbaren
Browserdialogs zeigen, wenn vorhanden; Betrieb unter DDEV.
Zusätzlich bestätigt: Manuelle Bewertungen bleiben erhalten; spätere technische
Ergebnisse werden ausschließlich als neue Hinweise angezeigt.
Auch ohne sichtbaren Dialog wird eine angeforderte Aufnahme erstellt und
aufbewahrt. Zweck: insbesondere uneindeutige Ergebnisse (`inconclusive`) anhand
der sichtbaren Seitenansicht unmittelbar manuell beurteilen können.
Im anschließenden Sparring wurde die Ausbaurichtung präzisiert: zunächst vorhandene
Funktionen stabilisieren und die bestehende Ansicht schrittweise erweitern; die
Resolve-artige Oberfläche folgt später mit bereits nutzbaren Arbeitsbereichen.
Für den Eingang wurde anschließend bestätigt: einzelne URLs schnell nacheinander
erfassen, nach bestätigter Speicherung das Formular sofort wieder verwenden und
den Verlauf der aktuellen Tabsitzung über Reload erhalten. Toasts erscheinen nur
im fokussierten Tab. Backend/API bedienen zunächst die klassische und die spätere
Studio-Oberfläche, nicht beliebige weitere Clients.
Nach der praktischen Nutzung des schnellen Eingangs wurde ergänzt: Die klassische
Gesamtseite ist für diesen Arbeitsfall zu überladen. Eine parallele, einfache
Studio-Eingabemaske soll bei halber Fensterbreite das Kopieren von ungefähr fünf
einzelnen URLs aus einem Nachbarfenster unterstützen. Die klassische Oberfläche
bleibt dabei erhalten.

**Aus der Analyse abgeleiteter Vorschlag:** Die übrigen Muss-Anforderungen sind
die vorgeschlagene Baseline dieses Lastenhefts. Ihre Bezeichnung als Muss beschreibt
die gewünschte Abnahme und behauptet keine separate Nutzerentscheidung zu jedem
Detail. Offene Produktentscheidungen stehen ausdrücklich in Abschnitt 10.
Wird Codex später mit der Umsetzung dieses Lastenhefts beauftragt, arbeitet er
innerhalb dieses Umfangs; offene Entscheidungen werden dadurch nicht beantwortet.

Bestandsbelege und Unsicherheiten: [design.md](design.md).
Einstieg und bestätigte Vorgaben: [index.md](index.md).

## 2. Einsatz und Grenzen

- Ausgangspunkt ist eine persönliche lokale Installation mit bestehenden Daten.
- Bestehende URLs, IDs, Notizen, Kontaktzeitpunkte, Bewertungen und Belegverweise
  sind Nutzdaten. Ihre Erhaltung gehört zum Refactoring.
- DDEV ist die maßgebliche Entwicklungs- und Abnahmeumgebung. Host-PHP ist kein
  Ersatz für einen Nachweis im Web-Container.
- Die Anwendung verwendet SQLite. Ein zusätzlich von DDEV gestarteter MariaDB-
  Container begründet weder eine Migration noch einen Wechsel des Speichers.
- Zum Umfang gehören Fallverwaltung, Belegspeicherung/-darstellung, allgemeine
  Browserdokumentation, verständliche Fehler und wartbare Anwendungsstruktur.
- Nicht Teil dieses Auftrags sind Ausbau automatisierter Schwachstellenerkennung
  oder Exploit-Reproduktion, Massentests externer Ziele, automatische Kontaktaufnahme,
  öffentliche Plattform, Benutzerverwaltung für Teams oder ein Stackwechsel.
- Browserbezogene Abnahmen verwenden kontrollierte lokale Beispielseiten mit
  harmlosen Inhalten und gewöhnlichen Browserdialogen.

## 3. Fachliche Begriffe

| Begriff | Bedeutung |
| --- | --- |
| Fall | Erfasste URL mit Notizen, Bearbeitungsstand und Verweisen auf Belege. |
| Technische Beobachtung | Zeitgebundene Information eines Vorgangs; kann fehlschlagen oder uneindeutig sein. |
| Manuelle Bewertung | Bewusste Entscheidung des Nutzers über einen Fall. |
| Beleg | Bild oder andere gespeicherte Dokumentation mit Herkunft und Zeitpunkt. |
| Verfügbarkeit | Ob ein registrierter Beleg tatsächlich gelesen werden kann; unabhängig von der Fallbewertung. |
| Kontaktverlauf | Bereits gespeicherte Kontakt-/Meldeinformationen; keine automatische Versandfunktion. |

Ein vorhandenes Bild allein ist keine fachliche Bestätigung. Ein technischer Fehler
ist keine Aussage darüber, ob ein Fall erledigt ist.

## 4. Funktionale Muss-Anforderungen

### F01 – URL erfassen und Fall erhalten

- Eine unterstützte HTTP-/HTTPS-URL lässt sich über die Oberfläche speichern.
- Ungültige Eingaben erzeugen eine verständliche Fehlermeldung und keinen
  unvollständigen Fall. Die fachlich relevante Original-URL bleibt erhalten.
- Bei identischer URL für dieselbe Domain wird der vorhandene Fall wiedergefunden;
  bestehende Informationen werden nicht still überschrieben.
- Ein neuer UI-Fall und sein erster Screenshot-Auftrag werden gemeinsam committed;
  eine exakt doppelte Eingabe darf einen vollständig fehlenden Altauftrag ergänzen,
  aber keinen vorhandenen terminalen Auftrag oder Nutzdaten ersetzen.
- Nach diesem Commit kehrt der Eingangsrequest zurück. Er wartet weder auf die
  Screenshot-Aufnahme noch auf einen technischen Retest und startet im Intake
  keinen automatischen Retest.
- Klassischer Formular-POST und schneller JSON-Eingang verwenden denselben
  Speicherfall und denselben CSRF-Zweck. Der JSON-Eingang unterscheidet neue
  Speicherung (HTTP 201), exaktes Duplikat (HTTP 200), Eingabefehler und
  abgewiesene CSRF-Tokens.
- Der lesende Eingangsstatus verarbeitet höchstens 50 gültige Finding-IDs. Er
  zeigt ausschließlich persistierte Fall-, Beobachtungs- und Screenshotzustände
  und startet keine Browserarbeit.
- Nach bestätigter Speicherung ist das Formular wieder frei. Fehlgeschlagene oder
  wegen eines Transportabbruchs nicht bestätigte Eingaben bleiben erhalten; eine
  erneute Übermittlung erfordert eine bewusste Nutzeraktion.

**Abnahme:** Fünf harmlose lokale URLs einzeln in einem Tab erfassen. Jede
bestätigte Speicherung gibt das Formular ohne Warten auf Browserarbeit wieder frei;
Finding und Initialauftrag sind anschließend vorhanden, RetestRun und Aufruf des
Retest-Clients nicht. Eine lokale URL zweimal eintragen: ein Fall, unveränderte
vorhandene Notiz und kein Retest. Ein synthetischer historischer Fall ohne
ScreenshotJob erhält genau einen; vorhandene Auftragshistorie bleibt unberührt.
Validierungs-, CSRF- und unbekannten Transportfehler prüfen; Entwurf und Verlauf
bleiben erhalten und verursachen keinen automatischen Wiederholungs-POST. Weitere
Nachweise: [Abnahme schneller Einzeleingang](abnahme-schneller-eingang.md).

### F02 – Übersicht und Detailansicht

- Bestehende Suche, Filter, Navigation und Seitennavigation bleiben nutzbar.
- Detailansichten funktionieren mit vorhandenen, fehlenden und ohne Belege.
- Ein Seitenaufruf verändert keine fachlichen Daten und löscht keine Belegverweise.
- Fallbewertung, technische Fehler, Belegverfügbarkeit und Kontaktinformationen
  sind unterscheidbar. Anzeige und Filter verwenden dieselbe Bedeutung.
- **Konkretisierung in 3b:** Aufgezeichnetes manuelles Urteil, letzte gespeicherte
  technische Beobachtung und Kontaktzeitpunkt sind getrennte, kombinierbare
  Filterdimensionen. Zähler und ihre Ziellisten verwenden dieselben Abfragen.
  Ein Rohstatus `fixed` ist keine nachträglich nachgewiesene manuelle Behebung.
  Keine Beobachtung bedeutet keine gespeicherte Laufzeile, unabhängig von alten
  Retest-Zeitstempeln. Archivscope umfasst neue und historisch verworfene Fälle;
  deren explizites Wiederfinden erfindet keine manuelle Herkunft.
- Bei mindestens 5.500 synthetischen Fällen bleibt die Übersicht paginiert;
  eine einzelne Seite lädt nicht sämtliche Belegdateien des Bestands.

**Abnahme:** Vorher-/Nachher-Vergleich der relevanten Datensätze bei wiederholtem
GET; alle bleiben gleich. Filter und angezeigte Zustände passen bei denselben
Testfällen zusammen. Die Nutzerbeobachtung einer funktionierenden DDEV-Detailansicht
wird als Ausgangspunkt respektiert; vermeintliche Regressionen werden reproduziert.

### F03 – Nachvollziehbare Browserbilder

- **Bestätigte Produktregel:** Eine angeforderte Aufnahme erfolgt auch ohne
  sichtbaren Dialog und unabhängig von der fachlichen Einordnung des Ergebnisses,
  insbesondere bei `inconclusive`. Dann wird die normale Seitenansicht dokumentiert.
  Fehlender Dialog oder uneindeutiges Ergebnis sind keine Auslassungsgründe.
- Eine angeforderte Aufnahme hat ein eigenes erkennbares Ergebnis: verfügbar,
  fehlgeschlagen oder mit genanntem Grund ausgelassen. Während laufender Arbeit
  darf die Oberfläche keinen bereits vorhandenen Beleg behaupten.
- Das Bild dokumentiert die angezeigte Seite und, wenn vorhanden, den echten
  sichtbaren Browserdialog. Nachgezeichnete Dialoge gelten nicht als Ersatz.
- Ein gespeichertes Bild ist nach erneutem Öffnen des Falls lesbar und seinem
  Ursprung sowie Aufnahmezeitpunkt zugeordnet.
- Fehler der Aufnahme sind sichtbar, auch wenn andere Teile des Vorgangs gelingen.
- Bestehende Bilder werden durch eine neue Aufnahme nicht still ersetzt.

**Ergänzter Nutzerbedarf und umgesetzte Festlegung:** Mehrere Fälle lassen sich
hintereinander erfassen, ohne jeweils auf die sichtbare Aufnahme zu warten. Neuer
Fall und erster persistenter Screenshot-Auftrag werden atomar gespeichert; ein
von DDEV gestarteter Worker arbeitet die Aufträge FIFO und einzeln ab. Ein
zusätzlicher Cronjob ist nicht nötig. Ein später erstelltes Bild dokumentiert
seinen tatsächlichen späteren Aufnahmezeitpunkt und wird nicht als Bild einer
früheren technischen Beobachtung ausgegeben.

**Ausdrücklich revidierte Teilentscheidung vom 2026-10-03:** Die vorherige
Festlegung „jeder neue UI-Eingang wird sofort headless technisch geprüft“ gilt
nicht mehr. Dieser synchrone Vorgang konnte den Eingangsrequest bis zu 120 Sekunden
blockieren; viele wiederholte `alert()`-Aufrufe konnten einen rechtzeitigen
Abschluss zusätzlich verhindern. Bestätigte dauerhafte Speicherung hat im Intake
Vorrang. Der Eingangsweg startet daher keinen automatischen Retest. Die bestehende
Screenshot-Queue bleibt bestehen; manuelle oder anderweitig ausdrücklich
ausgelöste technische Prüfungen sind davon unabhängig.

**Abnahme:** Eine kontrollierte lokale Beispielseite mit gewöhnlichem Dialog
liefert einen lesbaren Bildbeleg mit sichtbarer Seite und Dialog. Eine lokale
Beispielseite ohne Dialog liefert ebenfalls einen lesbaren Screenshot. Auch bei
einem simulierten Ergebnis `inconclusive` ist die Seitenaufnahme nach der seriellen
Worker-Verarbeitung in der Detailansicht verfügbar; das Bild verändert die
fachliche Einordnung nicht.
Ein simulierter Aufnahmefehler erscheint ausdrücklich als Fehler. Nach
DDEV-Neustart ist ein zuvor gespeicherter Beleg weiterhin verfügbar.

### F04 – Dateien und Metadaten konsistent behandeln

- Alle Zugriffe verwenden dieselbe konfigurierte Speicherwurzel; absolute Pfade
  eines Entwicklerrechners sind keine Voraussetzung.
- Ein Schreibfehler darf keine Erfolgsmeldung für einen angeblich verfügbaren
  Beleg erzeugen. Teilfehler hinterlassen einen erklärbaren, behandelbaren Zustand.
- Fehlt eine Datei, bleibt ihr historischer Verweis erhalten und wird als fehlend
  angezeigt. Ein Konsistenzbericht unterscheidet fehlende und nicht zugeordnete Dateien.
- Ein Konsistenzbericht ist zunächst lesend; eine Bereinigung ist eine eigene Aktion.
- Belege dürfen nur aus der konfigurierten Ablage ausgeliefert werden.

**Abnahme:** Speicher in ein temporäres Verzeichnis umstellen; Anlegen, Anzeigen,
Existenzprüfung und ausdrücklich ausgelöstes Löschen verwenden nur dieses Verzeichnis.
Schreibfehler und Datenbankfehler getrennt simulieren. Fehlende Datei erkennen,
ohne den Datensatz zu löschen. Vorhandene historische Pfade bleiben lesbar oder
werden mit dokumentierter Migration erhalten.

### F05 – Notizen, Kontaktinformationen und Historie schützen

- Technische Aktualisierungen löschen keine Notizen, Kontaktzeitpunkte oder
  bestehenden Belege. Eine Änderung der Bewertung ist keine Beleglöschung.
- Namen und Beschreibungen bestehender Verwaltungsbefehle entsprechen ihrer
  tatsächlichen Wirkung. Eine Aktualisierung bedeutet keinen impliziten Reset.
- Destruktive Aktionen sind ausdrücklich benannt, zeigen den betroffenen Umfang
  und besitzen einen eindeutigen Auslöser; sie sind keine Nebenwirkung beim Lesen.
- Neue Belege ergänzen die nachvollziehbare Historie. Vollständiges Event Sourcing
  ist hierfür keine Vorgabe.

**Umsetzungsstand:** Falllöschung und Reset entfernen ScreenshotJobs, Evidence und
RetestRuns ausdrücklich unter dem Maintenance-Lock. Die Anwendung verlässt sich
dabei nicht auf eine SQLite-Cascade, weil die aktuelle Verbindung Fremdschlüssel
nicht erzwingt. Einreihen revalidiert das Finding innerhalb des Queue-Locks und
erzeugt per `INSERT ... SELECT` keinen Auftrag aus einer nach Löschung veralteten
Entity-Referenz.

**Abnahme:** Einen synthetischen Fall mit Notiz, Kontaktzeitpunkt und Bild anlegen.
Die im Umfang überarbeiteten Aktualisierungsaktionen ausführen: diese Informationen
bleiben erhalten. Eine gezielte Löschaktion betrifft ausschließlich ihren ausgewiesenen Umfang.

### F06 – Beobachtung und Bewertung unterscheiden

- **Bestätigte Produktregel:** Neue technische Ergebnisse überschreiben keine
  manuelle Bewertung. Sie erscheinen als datierter Hinweis. Eine Änderung der
  manuellen Bewertung erfordert eine ausdrückliche Nutzeraktion.
- **Präzisierte Nutzeranforderungen:** Bei `inconclusive` wird eine manuelle
  Bestätigung benötigt. „Behoben“ markiert einen noch nicht so geführten Fall;
  „Verwerfen“ ignoriert den erhaltenen Fall im normalen Gebrauch vollständig,
  auch nach neuen technischen Beobachtungen. Duplikate sollen entsprechend
  gekennzeichnet werden können. „Kontaktiert“ enthält zunächst nur einen
  unabhängig gespeicherten Zeitpunkt.
- Neue Bewertungsänderungen erhalten eine einfache Historie mit Zeitpunkt und
  Herkunft sowie, soweit bekannt, Bezug auf die tatsächlich beurteilte Beobachtung
  oder den Beleg. Unbekannte historische Entscheidungsgrundlagen bleiben unbekannt.
- Technische Ergebnisse werden mit tatsächlichem Zeitpunkt und Herkunft gespeichert
  und nicht mit einer Nutzerentscheidung gleichgesetzt.
- Technische Fehler führen weder zu einer automatischen Erledigt-Markierung noch
  zu einer stillen Löschung einer Bewertung.
- Widersprüchliche Altdaten werden in einer Bestandsprüfung sichtbar gemacht.
  Eine Migration erfindet keine historische Nutzerentscheidung.
- Die Regeln gelten unabhängig davon, ob die Aktion über UI oder CLI aufgerufen wird.

**Abnahme:** Ein manuell als erledigt bewerteter synthetischer Fall erhält eine
anderslautende simulierte technische Beobachtung. Bewertung und ihre Herkunft
bleiben unverändert; ein datierter Hinweis auf die neue Beobachtung wird sichtbar.
Erst eine ausdrückliche Nutzeraktion ändert die Bewertung. Dasselbe Verhalten gilt
für UI und CLI. Widersprüchliche historische Werte bleiben nachvollziehbar.
Ein simuliertes `inconclusive` lässt sich ausdrücklich manuell beurteilen;
Kontaktzeitpunkt und Bewertung bleiben unabhängig. Ein verworfener synthetischer
Fall behält Notiz und Belege, bleibt im normalen Arbeiten ignoriert und wird durch
eine neue simulierte Beobachtung nicht automatisch reaktiviert.

### F07 – Fehler verständlich darstellen

- Eingabefehler, nicht bestätigter Requestausgang, nicht erreichbare technische
  Schnittstelle, Aufnahmefehler, fehlende Datei und fehlgeschlagene Speicherung
  sind unterscheidbar.
- Die Oberfläche nennt den betroffenen Vorgang und sein Ergebnis. Technische
  Detailinformationen sind für Diagnose verfügbar, ohne die normale Ansicht zu überladen.
- Ein erfolgreich gespeicherter Fall wird wegen eines Aufnahmefehlers nicht als
  insgesamt ungespeichert dargestellt.

**Abnahme:** Die genannten Fehlerfälle lassen sich mit lokalen Fixtures oder
simulierten Schnittstellen prüfen; jeder hat eine eindeutige sichtbare Meldung.

## 5. Betrieb, Tests und Datenübernahme

### N01 – Reproduzierbarer DDEV-Betrieb

Installation, Start, Abhängigkeiten, Konfiguration und Prüfung sind dokumentiert
und aus einer frischen Arbeitskopie reproduzierbar. Benötigte Komponenten werden
über die Projektkonfiguration bereitgestellt; manuelle Reparaturen in einem
laufenden Container gelten nicht als dauerhafte Lösung. Versionsvorgaben sind
konsistent. Ein grüner Containerstatus allein belegt keine funktionierende Aufnahme.

**Abnahme:** Aufbau in einer isolierten DDEV-Projektkopie und Wiederholung der
lokalen Funktionsabnahmen nach Neustart. Beachten: vorhandene post-start-Hooks
installieren Abhängigkeiten und führen Datenbankmigrationen aus.

**Umsetzungsstand:** DDEV stellt die lokale SQLite-/Artefakt-Konfiguration
versioniert bereit und startet den Queue-Worker über Supervisor. Playwright-Image,
Node-Manifest und Lockfile verwenden `1.61.1`. Betrieb und ein Neustart der
verwendeten Installation einschließlich des jüngsten Schema-Wartepfads sind
belegt; die frische isolierte Projektkopie bleibt offen.

### N02 – Tests unabhängig von Nutzdaten

Alle Datei- und Datenbanktests laufen auf separaten temporären Daten. Die Trennung
muss auch für Lösch-/Reset-Fälle gelten. Bestehende Tests werden erst ausgeführt,
wenn diese Trennung überprüft oder eine vollständig isolierte Projektkopie verwendet
wird. Ein Browser-Mock ersetzt nicht den Bildnachweis aus F03.

**Abnahme:** Kontroll-Datei und Kontroll-Datenbank außerhalb der Testwurzeln bleiben
nach der Suite unverändert; Fixtures werden nur innerhalb der Testablage angelegt
und aufgeräumt. Tests verwenden keine gespeicherten externen Ziel-URLs.

### N03 – Bestandsübernahme und Wiederherstellung

Vor schreibenden Migrationen wird ein konsistenter Sicherungsstand aus Datenbank
und Artefakten erstellt. Eine Wiederherstellung wird auf einer getrennten Kopie
geprüft. Vorhandene IDs und fachliche Informationen bleiben erhalten; notwendige
Änderungen sind dokumentiert. Fehlende Altdateien werden nicht als wiederhergestellt
ausgegeben. Ein erneuter Start erfordert keine manuelle Datenbereinigung.

**Abnahme:** Migration auf einer Kopie; Vergleich von Fall-IDs, Notizen,
Kontaktinformationen und Belegzuordnungen. Jede beabsichtigte Abweichung dokumentieren.
Wiederhergestellte Kopie ist lesbar. Erst danach Änderungen an der tatsächlich
verwendeten Installation entsprechend dem später erteilten Umsetzungsauftrag.

### N04 – Lokale Anwendungsgrenzen

Zustandsändernde Web-Aktionen sind gegen unbeabsichtigte fremde Formularaufrufe
geschützt; reine Leseaufrufe bleiben ohne fachliche Nebenwirkung. Private Notizen,
vollständige Ziel-URLs und Browserinhalte werden nicht unnötig in Diagnoseausgaben
oder versionierte Testdaten übernommen. Kein öffentlicher Betrieb wird vorausgesetzt.

**Umsetzungsstand am 2026-10-03:** Auch Einstellungen, Recheck,
Screenshot-Einreihung und Löschen prüfen jetzt vorhandene Session-/Aktions-Tokens.
Native eigene Formulare enthalten die passenden Tokens. Isolierte HTTP-Prüfungen
belegen bei zwölf bestehenden Schreibpfaden, dass fehlende, ungültige und
missgebildete Tokens weder Daten noch Dateien ändern oder Dienste aufrufen.
Aktion, Fall und Session sind gebunden; gültige eigene Formulare funktionieren.
Der Review-POST besitzt zusätzlich den vorhandenen CSRF- und Kontextschutz.

## 6. Architekturleitplanken

Festgelegt sind Symfony, SQLite und DDEV. Folgende Struktur ist eine Empfehlung:

- Eine Anwendung mit klar getrennten Zuständigkeiten für Fälle, Belege,
  technische Laufdaten und Darstellung.
- UI und CLI verwenden gemeinsame Anwendungsfälle und fachliche Regeln.
- Dateizugriffe haben eine gemeinsame austauschbare Grenze. Tests können diese
  Grenze isolieren, ohne produktive Pfade nachzubauen.
- Controller koordinieren Eingaben und Antworten; Darstellung liegt vorzugsweise
  in Twig. Ein Frameworkwechsel für das Frontend ist nicht erforderlich.
- Änderungen fachlicher Zustände sind an wenigen auffindbaren Stellen definiert
  und unabhängig von Browser und Dateisystem prüfbar.

Für Arbeitsabschnitt 2 wurde innerhalb dieser Leitplanken eine kleine persistente
Queue in SQLite gewählt. `ScreenshotJob` bildet Auftrag und Fehlerhistorie ab;
ein Symfony-Prozess verarbeitet sie seriell und ruft einen neutralen
Browser-Screenshot-Endpunkt auf. Diese Entscheidung ergänzt die Architektur,
ohne Redis, Message Broker oder einen weiteren Anwendungsdienst einzuführen.

Keine Vorgabe für Microservices, Redis, vollständiges Event Sourcing oder eine
neue Repository-Abstraktion. Die nun vorhandene SQLite-Queue ist eine konkrete
lokale Umsetzung der bestätigten asynchronen Aufnahme, keine allgemeine Vorgabe
für weitere Hintergrundarbeit. Zusätzliche Infrastruktur, automatische Änderungen
manueller Bewertungen und destruktive Datenmigrationen sind keine versteckten
Implementierungsdetails.

## 7. Sinnvolle Arbeitsabschnitte für Codex

Bestätigte Ausbaurichtung: zuerst die bestehende Anwendung stabilisieren und
Funktionen in der aktuellen Ansicht nutzbar machen. Die folgende Aufteilung bleibt
der Vorschlag für die Stabilisierung; jeder Abschnitt liefert ein prüfbares Ergebnis.
Ein früher Resolve-Prototyp ist zurückgestellt. Die Kennungen der Abschnitte bleiben
erhalten. Beauftragt und umgesetzt sind Abschnitt 1, Abschnitt 2 samt
ergänzendem Backup sowie 3a und 3b; Nachweise und Grenzen stehen in den jeweiligen
Abnahmeprotokollen. Der schnelle Einzeleingang wurde danach als eigenes begrenztes
Vorhaben beauftragt.

1. **Sicher bearbeiten und lesen:** DDEV-Ausgangslage dokumentieren, Testdaten
   isolieren, einheitlichen Speicher verwenden, lesende Detailansicht und sichtbare
   fehlende Belege absichern. Schwerpunkt F02, F04, F05, N02.
2. **Belege zuverlässig verwalten:** Persistente serielle Screenshot-Aufträge,
   Bildablage und Anzeige mit lokalen Fixtures, nachvollziehbare Aufnahmefehler
   und Erhalt früherer Belege. F03, F04, F07, N01. Funktional umgesetzt; der
   vollständige N01-Frischaufbau bleibt als Betriebsnachweis offen.
3. **Bewertung und Darstellung ordnen:** Die bestätigte Regel aus F06 umsetzen;
   gemeinsame Begriffe/Filter und Controller-/Template-Trennung. Mehrdeutige
   Altdaten separat kennzeichnen, statt historische Entscheidungen zu erfinden.
   Der konkretisierte Teil **3a – einen Fall verlässlich bewerten** ist umgesetzt
   und abgenommen. **3b – Bestand konsistent lesen und filtern** wurde anschließend
   beauftragt und umgesetzt; [Abnahme](abnahme-abschnitt-3b.md) und
   [Zuschnitt](ui-workflow.md#erneutes-sparring-zum-zuschnitt-von-abschnitt-3).
4. **Bestand übernehmen und Übergabe abschließen:** Migration und Wiederherstellung
   an Kopien prüfen, Dokumentation aktualisieren und Abnahmeprotokoll erstellen.
5. **Schneller Einzeleingang:** Finding und ersten Screenshot-Auftrag dauerhaft
   speichern und den Request anschließend ohne automatischen Retest beantworten.
   Fünf einzelne URLs lassen sich in einem Tab nacheinander erfassen. Ein lokaler
   Sitzungsverlauf erhält bestätigte, fehlgeschlagene und nicht bestätigte
   Eingaben; ein lesender Statusabruf meldet ausschließlich persistierte Zustände.
   Beauftragt am 2026-10-03; [Abnahme und Grenzen](abnahme-schneller-eingang.md).
6. **Studio-Ingest v1 / Half-Screen:** Den bereits funktionierenden Eingang unter
   einer parallelen, reduzierten Studio-Oberfläche anbieten. URL-Eingabe und
   kompakter Sitzungsverlauf bleiben bei 640 bis 960 CSS-Pixeln gut nutzbar; die
   klassische Oberfläche bleibt erreichbar. Umgesetzt:
   [Abnahme](abnahme-studio-ingest.md), [Umsetzungsplan](plan-studio-ingest.md).

Abschnitt 3 verbessert die vorhandene Darstellung; er setzt keinen vollständigen
UI-Neubau voraus. Sicherung und Wiederherstellungsnachweis aus N03 gelten bereits
vor jeder betroffenen schreibenden Migration, unabhängig von der Abschnittsnummer.

Anschließende Erweiterungen entstehen ebenfalls zunächst in der bestehenden
Oberfläche: Review-Arbeitsliste mit Bildvergleich sowie Meldungen mit Entwürfen
und später Gruppierung. Ihr Zuschnitt ist noch ein Vorschlag und steht im
[UI-Arbeitsmodell](ui-workflow.md). Sind zwei bis drei Arbeitsbereiche
im Alltag brauchbar, lässt sich der Wechsel zur Resolve-artigen Navigation und
Anordnung konkret planen. Funktionen und Daten werden dabei weiterverwendet.

Bestehende allgemeine Browserdokumentation nur im beschriebenen lokalen Prüfrahmen
beurteilen. Diese Abschnitte enthalten keinen Auftrag zur Erweiterung automatisierter
Sicherheitsprüfungen gegen externe Systeme.

## 8. Lieferumfang und Fertig-Kriterien

- Überarbeiteter Code innerhalb des vereinbarten Abschnitts.
- Falls erforderlich nachvollziehbare Datenmigrationen und Wiederherstellungsweg.
- Geeignete isolierte Tests sowie Abnahme der betroffenen Anforderungen in DDEV.
- Aktualisierte README: Start, Speicherorte, Einstellungen, Tests und Diagnose.
- Kurzes Abnahmeprotokoll mit Anforderungs-ID, Nachweis, Ergebnis und offenen Grenzen.
- Aktualisierter Architekturstand mit tatsächlich getroffenen Entscheidungen.

Ein Abschnitt ist nicht allein deshalb fertig, weil Unit-Tests grün sind. Die
zugehörigen sichtbaren Ergebnisse müssen nachgewiesen sein. Keine Behauptung,
alte Bilder seien gerettet, solange lediglich ihre Verweise erhalten wurden.

## 9. Einstiegstext für den ausführenden Codex

> Lies `architecture/index.md`, `architecture/lastenheft.md` und die für deinen
> Abschnitt relevanten Bestandsbefunde in `architecture/design.md`. Prüfe zuerst
> Arbeitsbaum und tatsächliche DDEV-Laufzeit. Behandle Host-Befunde nicht als
> Nachweis eines Containerfehlers. Führe vorhandene Reset-Tests erst nach belegter
> Isolation aus. Beginne mit dem ausdrücklich beauftragten Arbeitsabschnitt und
> ordne Änderungen den Anforderungs-IDs zu. Erhalte bestehende Nutzdaten und
> fremde Änderungen. Arbeite mit kontrollierten lokalen Fixtures. Kläre nur die
> Produktentscheidungen, die deinen Abschnitt tatsächlich blockieren; triff
> gewöhnliche Implementierungsentscheidungen selbst. Berichte Änderungen,
> ausgeführte Abnahmen und verbleibende Grenzen. Ein offener Punkt ist keine
> stillschweigende Erlaubnis, eine neue Produktregel einzuführen.

Dieser Einstiegstext wird mit dem tatsächlich gewünschten Abschnitt beauftragt;
seine Ablage allein startet keine Implementierung.

## 10. Offene Produktentscheidungen

Der minimale Umfang der Bewertungshistorie ist inzwischen bestätigt und in F06
festgehalten. Weitergehende Review-Regeln und die automatische Auswahl einer
Bildvergleichsreferenz werden vor dem späteren Review-Paket geklärt; siehe
[UI-Arbeitsmodell](ui-workflow.md#erneutes-sparring-zum-zuschnitt-von-abschnitt-3).

Die frühere offene Entscheidung zu langen Screenshot-Vorgängen ist für diesen
Anwendungsfall getroffen: persistente SQLite-Aufträge und ein einzelner
DDEV-Worker. **Die anschließende frühere Festlegung einer sofortigen synchronen
headless Verifikation im Intake ist revidiert:** Der Eingang startet keinen
automatischen Retest. Eine mögliche spätere, ausdrücklich sichtbare automatische
Prüfstrategie wäre ein neues Vorhaben; sie ist keine offene Implementierungsfrage
des schnellen Eingangs.

Für den vollständigen späteren Studio-Ausbau bleiben Navigation und gemeinsamer
Auswahlkontext offen. Für Studio-Ingest v1 ist die Richtung enger: eigener
Symfony-Screen unter `/studio`, isolierte dunkle Styles und Wiederverwendung der
vorhandenen Intake-/Statusgrenzen werden empfohlen. Ein neues SPA-Framework ist
keine Voraussetzung. Bestand, Inspector, Review und Meldungen sind nicht Teil
dieses ersten Studio-Abschnitts.

Mehrbenutzerbetrieb, öffentliche Bereitstellung und automatische Kommunikation
sind spätere mögliche Vorhaben und keine Blocker für die lokale Stabilisierung.
