# Abnahme Studio-Statistiken v1

## Dashboard-Folgeauftrag vom 2026-10-04

Geprüft wurde die Arbeitskopie auf Basis von `053f874`. Der neue Auftrag
entfernt „Versendet“ aus dem Dashboard und vereint Gemeldet, Kontaktiert und
Behoben in einem Diagramm mit gemeinsamer Zahlenskala. Dieser Nachtrag ersetzt
die nachfolgenden historischen Aussagen zu Versandkennzahlen und Einzelachsen;
der aktuelle [Datenvertrag](studio-statistiken.md) dokumentiert die Änderung.

- Vollständige DDEV-PHPUnit-Suite: **263 Tests, 10.249 Assertions erfolgreich**.
- Dashboard-Browserabnahme: **11 Szenariogruppen erfolgreich**, einschließlich
  1440×1000, 960×1000, 640×1000 und 390×844 sowie Nutzung ohne JavaScript.
- Gleiche Fallzahlen liegen in allen Reihen auf derselben Höhe; Ein-/Ausblenden
  verändert weder Skala noch Pfade oder Plotposition. Auch ein Verhältnis von
  500 Ingests zu einem Kontakt liefert exakte Tooltipwerte und Listenlinks.
- Kennzahlen, Kalender, historische Kontakte, undatierte Altbestandsmarker und
  offene Kontaktarbeit stimmen mit ihren Falllisten überein. Versandmarker
  bleiben gespeichert und erzeugen keine Kontaktmarkierung.
- Browserabrufe erhalten den Datenbestand unverändert und erzeugen keine POSTs,
  externen Requests, JavaScript-Fehler oder fehlgeschlagenen Ressourcen.
- `ddev readme-screenshots` lief erfolgreich auf einer isolierten englischen
  Demo-Datenbank; der Statistik-Screenshot wurde aktualisiert und visuell geprüft.
- PHP-/JavaScript-Syntax und `git diff --check` sind erfolgreich.

Der Browserbericht liegt lokal unter
`/tmp/librebugbounty-studio-statistics-combined-report`. Die isolierten Testdaten
und Testserver wurden entfernt. Der normale Speicher wurde für diese Abnahme
nicht verändert.

## Historische Abnahme vom 2026-10-03

Stand: 2026-10-03. Geprüft wurde die lokale Arbeitskopie auf Basis von `bc59865`
mit den noch nicht committeten Dashboard-Änderungen. Auftrag: „setzte das dashboard
um“. [Umfang, Begriffe und Datenvertrag](studio-statistiken.md).

## Geliefertes Verhalten

`/statistics` ist über die untere Navigation von Eingang, Bestand und Falldetail
erreichbar. `/studio/statistics` leitet mit Queryparametern auf die kanonische
Seite weiter. Die Seite bietet Kalenderwochen, Monate, Jahre, Gesamtzeitraum und
freie inklusive Tagesgrenzen, Zurück/Vorwärts und Tages-/Wochen-/Monatsgruppen.

Fünf Kennzahlen einschließlich Kontaktiert, wählbare Aktivitätslinien, datierter Tabellenersatz,
Jahres-Heatmap, TLD-Donut mit Fall-/Hostzählung, heutige manuelle Bewertungen und
Alter offener Kontaktarbeit verwenden die gespeicherten Daten. Ereignis- und
TLD-Falllinks öffnen exakt die gezählte Menge einschließlich ihres Archivumfangs.
Suche, Paging und Fallbearbeitung erhalten die neuen Statistikfilter.
Hostzahlen und zusammengefasste sonstige TLDs erhalten keine irreführenden
Falllistenlinks.

Auf den Folgeauftrag zur dominierenden Ingest-Reihe erhält jede Aktivitätsreihe
eine eigene, ausdrücklich beschriftete Skala bei gemeinsamer Zeitachse. Kontakte
sind standardmäßig sichtbar. Periodensummen und Tooltip geben weiterhin absolute
Fallzahlen an; eine Linie verändert ihre Skala nicht beim Ausblenden anderer
Reihen.

Historische Ingest- und Kontaktzeitpunkte sind in den Rückblicken enthalten.
„Gespeicherte Historie“ zeigt zusätzlich alle erhaltenen datierten Kontakte mit
frühestem Markierungsdatum und drei einzeln verlinkte Altbestandsgruppen:
`confirmed_fixed`, `manually_checked` und roher Status `fixed` bei fehlender
aufgezeichneter neuer manueller Bewertung. Der gewählte Zeitraum begrenzt diese
Bestandszahlen nicht; TLD-Auswahl und Archivumfang stimmen mit den Ziel-Listen
überein. Die Gruppen können sich überschneiden und werden nicht summiert.

„Gemeldet“ zählt neue Ingest-Fälle. „Versendet“ zählt Fälle mit ausdrücklichem
Erstversandmarker; eine schon gespeicherte Kontaktmarkierung wird nicht als
Versand ausgelegt. Die neue CSRF-geschützte Aktion im Studio-Inspector hält eine
bereits versendete Meldung fest und erhält den ersten Zeitpunkt bei Wiederholung.
Sie startet keinen Mailversand und verändert keine Kontakt- oder Bewertungsdaten.
Bestätigte Fälle mit Kontakt- oder Versandmarker gehören nicht zur offenen
Kontaktarbeit.

## Automatisierte Nachweise

| Prüfung | Ergebnis |
| --- | --- |
| Vollständige DDEV-PHPUnit-Suite nach Historien-/Listenänderung | 199 Tests, 2.837 Assertions erfolgreich |
| Darin neue Statistik-Service-Tests | 12 Tests, 128 Assertions erfolgreich |
| Neue HTTP-Abnahme, nach finaler UI-Anpassung erneut geprüft | 6 Tests, 448 Assertions erfolgreich |
| Symfony `lint:container` | Erfolgreich |
| PHP-Syntax der neuen/geänderten Komponenten und Templates | Erfolgreich |
| JavaScript-Syntax `node --check public/js/statistics.js` | Erfolgreich |
| `git diff --check` | Erfolgreich |

Die Datenprüfungen umfassen wiederholte Bewertungen, technische `fixed`-Ergebnisse
ohne manuelle Behebung, Archivfälle, Fall-/Hostzählung, TLD-/IP-/Lokalhost-Grenzen,
Schaltjahr und Tages-/Zeitzonengrenzen, faire laufende Vergleiche, leere und sehr
lange Zeiträume sowie ungültige oder nicht skalare Filter. CSRF- und
Idempotenzprüfungen belegen den unabhängigen Versandmarker. Leseabrufe erhalten
die Tabelleninhalte und starten keine Aufnahme oder technische Prüfung.

## Browser und lokale Laufzeit

Elf Playwright-Szenarien bestanden auf einer frischen isolierten SQLite-Datenbank
mit 510 synthetischen Fällen, ausschließlich lokalen Ziel-URLs und ohne Worker.
Die finalen Fenstergrößen waren 1440×1000, 960×1000, 640×1000 und 390×844.

Geprüft wurden echte SVG-Linienauswahl, datierter Tooltip per Zeiger und Tastatur,
native Zeitraum-/Filterformulare, TLD- und Kalender-Umschaltung, exakte
Kennzahl-/Tages-/TLD-/Bestands-/Alterslinks und Nutzung ohne JavaScript. Hauptfläche
und untere Karten bleiben vertikal erreichbar; die Navigation bleibt sichtbar.
Der schmale Kalender scrollt innerhalb seiner Karte. Home/End machen die
ausgewählte Kalenderzelle sichtbar, ohne horizontalen Dokumentoverflow.

Ein gezielter Fall mit 500 Ingests und einer Kontaktmarkierung am selben Tag
verwendet die eigenen Achsen 0–600 und 0–1. Der Kontaktpunkt liegt klar über der
Nullbasis, ist bereits ohne JavaScript sichtbar und öffnet genau einen Fall;
der Ingest-Link öffnet genau 500. Die gespeicherte Historie zeigt vier datierte
Kontakte ab 2025 sowie überlappende undatierte Markierungen. Alle historischen
Links stimmen mit der Liste überein; `legacy_review` bleibt beim Öffnen eines
Falls und bei der Rückkehr erhalten. Kleine positive TLD-Anteile bleiben als
Segmente sichtbar und werden unterhalb von einem Prozent als „< 1 %“ bezeichnet.
Die Wertanzeige liegt unter dem aktiven Plot. Auf schmalen Fenstern ist ihr Platz
fest reserviert, damit sie die nächste Überschrift nicht überlagert.
Der Browser prüft, dass sich Plotpositionen weder beim Zeigerwechsel zwischen
Reihen noch beim Tastaturfokus verschieben.

Die Browserabnahme erhielt alle sieben Anwendungstabellen unverändert. Sie
verzeichnete keine POSTs, externen Requests, JavaScript-Fehler oder fehlgeschlagenen
Anwendungsressourcen. Testserver, isolierte Datenbank und Cache wurden anschließend
entfernt. Screenshots und `result.json` bleiben lokal unter
`/tmp/librebugbounty-studio-statistics-visual-604222/` erhalten. Reproduzierbare
Einrichtung und Aufruf stehen in `tests/browser/studio-statistics.cjs`.

Die echte lokale Anwendung lieferte `/statistics` und `/js/statistics.js` mit HTTP
200. Der Statistik-GET benötigte beim einzelnen lokalen Abruf ungefähr 0,23 Sekunden;
das ist ein Funktionsnachweis, kein Lasttest. DDEV-PHP verwendet Europe/Berlin.
Es wurde keine Migration oder externe technische Prüfung ausgeführt.

## Grenzen

Historische undatierte manuelle Entscheidungen bekommen keinen erfundenen
Zeitpunkt. Kontaktkurven zeigen das gespeicherte Markierungsdatum, das frühere
erneute Markierungen ersetzen konnten; es ist nicht zuverlässig der Erstkontakt.
Statistiken beschreiben den erhaltenen Datenbestand; Löschungen und
Resets können frühere Summen verändern. Der neue Versandmarker speichert den
Zeitpunkt seiner erstmaligen Betätigung; rückwirkende Datumskorrektur,
Mailanzahl, Follow-ups und Antwortzeiten sind kein Teil dieses Abschnitts.
Sehr lange Zeiträume werden auf höchstens 730 ausdrücklich beschriftete
Diagrammgruppen verdichtet. Ein einzelnes Kalenderjahr bleibt täglich darstellbar.
