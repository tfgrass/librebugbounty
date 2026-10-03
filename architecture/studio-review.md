# Studio-Review

Stand: 2026-10-03. Nach dem manuellen Einreihen der fehlenden Screenshots hat der
Nutzer die Umsetzung der eigenen Review-Ansicht beauftragt; sie ist umgesetzt.
E-Mail- und Kontaktmanagement bleiben zurückgestellt. Dieser Abschnitt verwendet
gespeicherte Fälle und Belege; eine neue technische Prüfung ist kein Bestandteil
der Sichtung.

**Rückmeldung nach der Präzisierung:** Der Nutzer hat die Review-Ansicht selbst
kurz ausprobiert und berichtet, dass sie zu funktionieren scheint. Das ergänzt
die isolierte Abnahme um eine erste manuelle Nutzung; es ist keine vollständige
Betriebsabnahme.

## Durchgängiger Umfang

Unter `/review` wird jeweils ein Fall aus dem Arbeitsvorrat gezeigt. Ein großes
Bildfeld und direkt erreichbare gespeicherte PoC-Angaben ermöglichen eine
manuelle Entscheidung. Die Seite ist in der unteren Studio-Navigation verlinkt.

Der erste unbewertete Vorrat entspricht dem zuletzt gemeinsam verwendeten Screenshot-Backfill:

- Aktiver Fall; weder manuell noch durch eine alte Kennzeichnung verworfen oder
  als Duplikat archiviert.
- Kein gespeichertes manuelles Urteil.
- Letzte gespeicherte technische Beobachtung ist `inconclusive` oder `error`,
  oder es gibt keinen gespeicherten technischen Lauf.

Die letzte Beobachtung folgt derselben Zeit-/SQLite-Reihenfolge wie die bestehende
Bestandsprojektion. Alte `fixed`-/Review-Werte werden sichtbar erhalten; aus dem
fehlenden neuen Historieneintrag wird keine frühere menschliche Entscheidung
abgeleitet. Automatisch bestätigte Fälle und Fälle mit gespeichertem manuellem
Urteil gehören nicht in diesen ersten Arbeitsvorrat. Der unten beschriebene
beauftragte Ausbau ergänzt neue Hinweise zu bereits bewerteten Fällen.

Standardmäßig werden nur Fälle mit tatsächlich lesbarer Bilddatei gezeigt. Die
Filter erlauben alle Bildzustände oder Fälle ohne verfügbares Bild sowie eine
getrennte Auswahl der drei technischen Anlässe. Queuezustand und Bildverfügbarkeit
sind verschiedene Angaben: Auch nach einem fehlgeschlagenen neuen Auftrag kann
ein früherer Beleg noch lesbar sein. Hintergrundaufträge werden durch Öffnen,
Filterwechsel oder Überspringen weder erstellt noch verarbeitet.

## Darstellung und Entscheidungen

Bildauswahl, bekannter Aufnahmezeitpunkt und Ablagezeit bleiben sichtbar. Eine
Aufnahme aus einem ScreenshotJob ist unabhängig vom technischen Retest; aus
zeitlicher Nähe wird keine gemeinsame Herkunft behauptet. Fehlende Dateien,
unbekannte Aufnahmezeit und noch laufende oder fehlgeschlagene Aufträge erhalten
einen sichtbaren Zustand.

URL, Methode und erwartetes Kennzeichen sind direkt sichtbar; Payload und
Request-Parameter erscheinen, wenn sie gespeichert wurden. Die Darstellung
generiert keine zusätzlichen Payloads oder Reproduktionsanleitungen. Notizen
bleiben lesbarer Kontext und lassen sich im verlinkten vollständigen Falldetail
bearbeiten. Alle gespeicherten Inhalte werden als Text escaped.

**Präzisierung durch den Nutzer:** Beide Links-/Rechts-Aktionen sollen ein Urteil
speichern: rechts „Vulnerable“, links „Not vulnerable“ als bestätigtes `fixed`.
Das ersetzt die zunächst umgesetzte neutrale linke Aktion. Überspringen bleibt
als kleiner separater Link erreichbar. Die Aktionen sind:

- **Vulnerable (rechts):** Manuelles Urteil `confirmed` samt Historie speichern.
- **Not vulnerable (links):** Manuelles Urteil `fixed` samt Historie speichern;
  der bestehende Schutzmarker lautet `confirmed_fixed`. Der Fall bleibt im
  aktiven Bestand, verlässt aber den unbewerteten Review-Vorrat.
- **Überspringen:** Zum nächsten Fall im aktuellen Durchlauf wechseln; weder
  Urteil noch Hinweis erledigen. Beim neuen Durchlauf kann der Fall wiederkehren.
- **Verwerfen:** Eigene ausdrücklich beschriftete Aktion mit dem vorhandenen
  Verwerfungsgrund, optional „Duplikat“. Speichert `discarded`; der Fall wird im
  normalen Arbeiten ignoriert. Ein unklarer Bildbeleg allein ist kein Fehlalarm.

Pfeil rechts beziehungsweise Rechtswischen speichert `confirmed`; Pfeil links
beziehungsweise Linkswischen speichert `fixed`. Die Aktionen sind zusätzlich als normale
Schaltflächen/Links bedienbar. Wischgesten arbeiten in einer eigenen Bedienfläche,
damit Bildöffnung, Textauswahl und vertikales Scrollen erreichbar bleiben.
Tastenkürzel gelten nicht während Eingaben oder bei gehaltenen Wiederholungstasten.
Vulnerable und Not vulnerable bleiben in einer festen Aktionsleiste über der
Navigation sichtbar, auch wenn lange PoC-Angaben im Arbeitsbereich gescrollt
werden. Die Filter sind mit ihrer aktuellen Auswahl in einem nativen
aufklappbaren Bereich erreichbar. Formulare und Bildauswahl funktionieren auch
ohne JavaScript.

Bildauswahl allein setzt keine Bewertungsgrundlage. Ohne ausdrückliche Auswahl
bleiben Beleg- und Beobachtungsbezug unbekannt. Die Bewertung kann einen konkret
ausgewählten eigenen Beleg beziehungsweise eine eigene Beobachtung referenzieren;
ein fremder Bezug wird verworfen. Nach gespeicherter Entscheidung bleibt der
letzte Fall zum Öffnen und Korrigieren im vorhandenen Falldetail verlinkt.

## Speicherung und Weitergehen

Die leichte lesende Projektion lädt Kandidaten und Bildreferenzen gebündelt; nur
der angezeigte Fall wird mit seiner vollständigen Detailprojektion geladen.
Der Durchlauf verwendet einen stabilen Cursor statt einer nach jeder Entscheidung
verschobenen Seitennummer. Der Cursor des bewerteten Falls bleibt auch nach dem
Entfernen aus dem Vorrat die Referenz für den nächsten Fall.

`POST /review/{id}/assessment` benötigt CSRF und einen an den angezeigten
Bewertungs-/Beobachtungsstand gebundenen Kontext. Veraltete oder doppelte
Entscheidungen überschreiben kein inzwischen gespeichertes Urteil. Die bestehende
Bewertungsaktion speichert Urteil und Historie gemeinsam. Nur eine erfolgreich
gespeicherte Entscheidung führt zum nächsten Fall; ein Fehler erhält die Karte
und die übermittelten Auswahlwerte. Alle Rückwege bleiben validierte lokale
Studio-Pfade.

## Grenzen und Prüfung

Für den ersten Review-Vorrat war keine Migration nötig. Der anschließend
beauftragte Hinweis-Ausbau ergänzt eine eigene Sichtungshistorie und einen
Beobachtungsstand für künftige manuelle Urteile; technische Läufe und Bilder
werden dabei erhalten. Dauerhafte Wiedervorlage und Vorher-/Nachher-Vergleich
bleiben eigene Erweiterungen. N01 ist ein separater Betriebsnachweis.

Erste Abnahme vor der Präzisierung der Links-/Rechts-Aktionen:
gezielte HTTP-/Datenprüfung und isolierte Browserprüfung mit lokalen
Fixtures, einschließlich tatsächlich gespeicherter Entscheidungen, neutraler
Sichtung, fehlender Belege, kleiner Fenster und Nutzung ohne JavaScript.

- Die Gesamtsuite war vor der abschließenden Refresh-Korrektur mit **208 Tests
  und 2.983 Assertions** erfolgreich. Danach bestanden sämtliche Studio-Tests
  mit **40 Tests und 1.398 Assertions**; nach dem Umbau der Aktionsleiste bestand
  die fokussierte Review-Abnahme erneut mit **10 Tests und 161 Assertions**.
  Die Gesamtzahl nach der zusätzlichen Refresh-Regression wurde nicht erneut
  als vollständiger Suite-Lauf geprüft.
- Die damalige abschließende isolierte Browser-Abnahme bestand **19 Prüfungen** bei
  1440, 960, 640 und 375 CSS-Pixeln. Geprüft sind die dauerhaft sichtbaren
  Aktionen, Bild-/Filterauswahl, neutrale Navigation, Tastenkürzel und echte
  Touchgesten einschließlich Erholung nach abgebrochener Mehrfinger-Geste.
  Bestätigen mit expliziter Grundlage und Verwerfen speichern auch ohne
  JavaScript. Fremde Bezüge und veraltete Entscheidungen werden abgewiesen;
  gespeicherte technische Läufe, Bilder und Aufträge bleiben unverändert.
  Es gab keine externen Requests oder JavaScript-/Ressourcenfehler.
  Die Testserver wurden beendet und ihre isolierten Daten entfernt;
  Bericht und Bilder liegen unter
  `/tmp/librebugbounty-studio-review-final-verified/`.
  Reproduzierbar mit `tests/Support/studio_review_browser_router.php` und
  `tests/browser/studio-review.cjs` gemäß den dortigen Startbefehlen.
- Container-Lint, PHP-/JavaScript-Syntax und `git diff --check` erfolgreich.
- Der abschließende lesende Abruf von `/review` im laufenden DDEV-Projekt ergab
  **HTTP 200**. Es wurden keine echten Fälle durch die Abnahme bewertet.

Abnahme nach der ausdrücklichen Präzisierung zu zwei bewertenden Hauptaktionen:

- Sämtliche Studio-Tests bestanden mit **41 Tests und 1.427 Assertions**.
  Darin sind **11 Review-Tests mit 190 Assertions** enthalten. Die zusätzliche
  Prüfung bestätigt `fixed` samt `confirmed_fixed`, Bewertungshistorie,
  expliziter Grundlage und unmittelbar nächstem Fall. Der bewertete Fall bleibt
  im aktiven Bestand; technische Läufe, Belege und Screenshot-Aufträge bleiben
  unverändert. Konflikte, fehlende Belege und fehlgeschlagene Schreibvorgänge
  werden auch beim neuen `fixed`-Weg geprüft.
- Der lesende Live-Abruf lieferte **HTTP 200** und beide neuen Bewertungsbuttons
  sowie den separaten Überspringen-Link. JavaScript-Syntax und
  `git diff --check` bestanden.
- **23 isolierte Browserprüfungen** bestanden. Pfeiltasten und echte Wischgesten
  speichern links `fixed` und rechts `confirmed` genau einmal und zeigen den
  unmittelbar nächsten Fall. Beide nativen Bewertungsbuttons speichern auch
  ohne JavaScript mit ausdrücklich gewählter Grundlage. Der separate
  Überspringen-Link bleibt neutral; Eingaben, Textauswahl, Wiederholungstasten,
  Tastenkombinationen und abgebrochene Gesten lösen keine Bewertung aus.
  Die Aktionsleiste passt bei 375, 640, 960 und 1440 CSS-Pixeln ohne Überlappung.
  Technische Daten und Belege blieben unverändert; keine externen Requests oder
  JavaScript-/Ressourcenfehler. Der isolierte Testserver und seine Daten wurden
  entfernt. Bericht und Bilder:
  `/tmp/librebugbounty-studio-review-decisions-final/`.

## Neue Hinweise nach einem Urteil

**Beauftragter Ausbau:** Der Nutzer hat den Commit von Review v1 und alle drei
Grundlagen aus dem folgenden Sparring ausdrücklich beauftragt: ältere
Schreibaktionen absichern, frischen DDEV-Betrieb nachweisen und neue gespeicherte
Hinweise zu bereits bewerteten Fällen im Review bearbeiten.

**Spätere Nutzerkorrektur:** Die zusätzliche CSRF-Absicherung der vier älteren
Aktionen wird vorerst zurückgestellt und ihre Ergänzung zurückgenommen. Der
Hinweis-Ausbau und der frische Installationsnachweis werden weiter umgesetzt.

**Verbindliche Präzisierung:** Nur widersprüchliche Ergebnisse, Unklarheiten und
Fehler sollen erneut erscheinen. Die zunächst vorgeschlagene Variante „alle
neuen Ergebnisse“ ist dadurch verworfen. Bei `confirmed` widerspricht ein neues
`fixed`, bei `fixed` ein neues `still_vulnerable`. `inconclusive` und `error`
erzeugen bei beiden Urteilen einen Anlass. Passende Ergebnisse und `pending`
erzeugen keinen Anlass. Archivierte Fälle bleiben ausgeschlossen.

`kind=changed` zeigt diese Fälle; `all` verbindet beide Vorräte ohne doppelte
Karten. Die drei bisherigen technischen Filter bleiben der ersten Sichtung
zugeordnet. Bildfilter und Cursor gelten für beide Vorräte. Alle ungelösten
auslösenden Beobachtungen erscheinen mit Zeit, Ergebnis und Anlass. Die jüngste
Gesamtbeobachtung bleibt separat sichtbar: Ein später passendes Ergebnis erledigt
einen früheren Widerspruch nicht stillschweigend.

„Geprüft · Bewertung behalten“ legt eine unveränderliche Sichtung ab. Urteil,
Bewertungsdatum, gesamte Finding-Zeile, Kontakt und Bewertungshistorie bleiben
erhalten. Die Sichtung ist an das konkrete Urteil und den gesamten bekannten
Beobachtungsstand gebunden. Ein erneutes explizites Urteil, auch mit demselben
Wert, begründet einen neuen Bewertungsstand. Links/rechts speichern weiterhin
`fixed` beziehungsweise `confirmed`; Überspringen lässt Hinweise offen.

Künftige Urteile und Sichtungen speichern Zustandsfingerprints der vorhandenen
Beobachtungen. Damit werden neue IDs, rückdatierte Eingänge und Änderungen an
bestehenden Läufen erkennbar. Ältere Urteile behalten ihre vorhandene ID-Grenze;
fehlt die Historie ganz, dient der Bewertungszeitpunkt als konservative Grenze.
Unklare Gleichzeitigkeit bleibt sichtbar. Die Migration erfindet keine Urteile,
Sichtungen oder Bewertungsgrundlagen.

Kontextschutz erkennt Änderungen seit der angezeigten Karte einschließlich
konkurrierender Sichtungen. Ein Fehler erhält Karte, Bild und Eingaben; nur ein
erfolgreicher Schreibvorgang führt weiter. Die optionale konkrete Grundlage wird
weiterhin ausdrücklich gewählt. Sichtungshistorie und Snapshots bleiben beim
Reset technischer Belege erhalten; Falllöschung entfernt auch dessen Sichtungen.
Detailhinweis und Review nutzen dieselbe Regel. Native Formulare funktionieren
ohne JavaScript. Die Sichtung startet keine Aufnahme oder technische Prüfung.

**Abnahme des Hinweis-Ausbaus:**

- Die Gesamtsuite besteht mit **220 Tests und 3.205 Assertions**. Darin prüfen
  **10 neue Backendtests mit 178 Assertions** die komplette Ergebnis-/Urteilmatrix,
  kumulative Hinweise, neue IDs und geänderte vorhandene Läufe, alte Grenzen,
  unmigrierte Lese- und Bewertungswege sowie Reset/Löschen ohne FK-Erzwingung.
  Tatsächliche SQL-Fehler beim Sichtungsinsert und beim Schreiben des
  Bewertungsstands rollen sämtliche Änderungen zurück.
- **14 neue Notice-Browserprüfungen** und **23 bisherige Review-Browserprüfungen**
  bestehen. Die festen Aktionen passen bei 375, 640, 960 und 1440 CSS-Pixeln;
  Beibehalten und beide Urteile funktionieren ohne JavaScript. Fremde Grundlagen
  und neue Eingänge nach Anzeige bleiben ohne Schreibwirkung. Sichtung erhält
  die gesamte Finding-Zeile, Bewertungshistorie, technische Daten und Dateien.
  Keine externen Requests oder JavaScript-/Ressourcenfehler. Berichte/Bilder:
  `/tmp/librebugbounty-studio-review-notices-browser-final/` und
  `/tmp/librebugbounty-review-foundations-baseline-final/`.
- Die additive Migration erhielt auf der wiederhergestellten Kopie alle
  bisherigen Spalten und Inhalte sowie 661 Artefaktdateien. Anschließend wurde
  sie unter einem kurzen SQLite-Schreiblock auf der Anwendung ausgeführt:
  **5.733 Findings, 5.069 Domains, 8.703 Evidence-Zeilen, 8.897 RetestRuns,
  75 Bewertungen, 690 Screenshot-Aufträge und 4 Settings** blieben vollständig
  erhalten. Die neue Sichtungstabelle blieb leer, alte Zustandsgrenzen `NULL`.
  Alle 673 Dateien der aktuellen Sicherung blieben bytegleich. 9 Migrationen,
  SQLite-Integrität `ok`; Details und Sicherungsstände in [Backup](backup.md).
- Lesende Live-Abrufe von Review einschließlich `kind=changed`, Bestand und
  Statistik lieferten nach Migration **HTTP 200**. Es wurden keine echten Fälle
  durch die Abnahme bewertet, keine Zielbesuche gestartet. Container-Lint,
  PHP-/JavaScript-Syntax und `git diff --check` erfolgreich.

Der frische N01-Aufbau ist als eigener
[Betriebsnachweis](abnahme-betriebsabschluss.md) abgeschlossen.
