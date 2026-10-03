# Studio-Bestand und kanonische Routen

Stand: 2026-10-03. Der Nutzer hat den vorgeschlagenen Ausbau mit „genau bau das
so“ bestätigt. Die Startseitenwahl ist damit festgelegt: schneller Eingang auf
`/`, eigener Bestand auf `/findings`, klassische Oberfläche unter `/legacy`.
Der Abschnitt ist umgesetzt und geprüft; die frühere Kennzeichnung als Vorschlag
ist ersetzt. [Abnahme und Grenzen](abnahme-studio-bestand.md).

## Festgelegter Umfang und Begründung

Die klassische Gesamtseite war für schnelle einzelne Eingaben zu überladen.
Ein eigener Eingang erhält deshalb den bestätigten Ablauf bei halber Fensterbreite.
Liste, Suche, vorhandene Filter, Archiv und Kennzahlen bilden den zweiten
Studio-Arbeitsbereich. Das bestehende Falldetail bleibt der Bearbeitungsweg.
Die Alternative, Eingang, Statistik, Filter und Liste wieder gemeinsam auf die
Startseite zu legen, wurde für diesen Ablauf nicht gewählt.

Bewertung, letzte technische Beobachtung und Kontakt bleiben unabhängig.
`inconclusive` bedeutet keine Behebung. Historische Diagnosewerte und ihre unklare
Herkunft bleiben erkennbar. Verworfene Fälle sind im aktiven Bestand ausgeblendet;
das Archiv berücksichtigt auch historische Duplikat-/Verwerfungswerte.

## Implementierte GET-Routen

| Zweck | Kanonisches Ziel |
| --- | --- |
| Studio-Eingang | `/` |
| Studio-Bestand | `/findings` |
| Studio-Falldetail | `/findings/{id}` |
| Klassische Übersicht | `/legacy` |
| Klassisches Falldetail | `/legacy/findings/{id}` |
| Einstellungen | `/legacy/settings` |
| Prioritätsexport | `/legacy/operator-priority` |

`/studio`, `/studio/findings` und `/studio/findings/{id}` leiten mit HTTP 308 auf
die Studio-Ziele weiter und erhalten Queryparameter. Symfony normalisiert eine
abweichende abschließende `/` zunächst mit HTTP 301. Alte Filter-/Pagingabfragen
auf `/` leiten zur Studio-Liste weiter; reine Rückmeldungen bleiben im Eingang
sichtbar. Die bisherigen GET-Adressen `/settings` und `/operator-priority` leiten
auf Legacy. `/about` öffnet den klassischen Informationsdialog.

Die funktionalen POST-, API- und Artefakt-URLs bleiben stabil. Der klassische
Controller wurde nicht pauschal mit `/legacy` präfixiert. Native Studio-Eingaben
öffnen nach dauerhafter Speicherung das Studio-Falldetail; klassische Eingaben
kehren nach `/legacy` zurück. Das schnelle JavaScript-Formular verwendet weiter
unverändert den gemeinsamen JSON-Eingang. Exakte Duplikate öffnen den bestehenden
Fall in der jeweiligen Oberfläche.

## Gemeinsamer Lesepfad und Rückkehrkontext

`FindingListService` interpretiert Filter und historische Aliase, erzeugt
Zählerlinks und Paging und liefert `FindingListView` für Studio und Classic.
`FindingReadRepository` liefert weiterhin eine skalare, lesende Projektion.
`q` sucht als wörtlicher Teilstring nach Domain, Titel oder vollständiger Fall-URL;
`%`, `_` und `!` erhalten dabei keine SQL-Wildcardwirkung. Der vorhandene
Domainfilter bleibt zusätzlich verfügbar. Count und Zeilen teilen dieselbe
Filterbedingung. Die ASCII-Groß-/Kleinschreibung folgt SQLite `LOWER`/`LIKE`;
eine zusätzliche Unicode-Suchnormalisierung ist nicht eingeführt.

Suchtext, kombinierte Filter und Seitengröße stehen in der URL. Studio-Listenlinks
tragen zusätzlich den normalisierten Listenpfad als `return_to` ins Falldetail.
Auch Classic-Listenlinks übernehmen ihre Filter und Seite in den entsprechenden
Studio-Listenkontext. `FindingNavigation` akzeptiert ausschließlich den internen
Pfad `/findings` mit validierten Filter-/Pagingwerten. Fremde Ziele, Fragmente,
Backslashes und ungültige Filter werden ignoriert. Arrays in Formularfeldern
werden vor einer Schreiboperation mit HTTP 400 abgewiesen.

Bewertung, Kontakt und Notiz tragen diesen Kontext durch ihre vorhandenen
CSRF-geschützten POSTs. Rückmeldungen werden als weitere Queryparameter angefügt.
Ohne Kontext führt der Rücklink zum Bestand. Globale Zähler setzen andere Filter
zurück und öffnen die gezählte Menge. Archiv-Tabs bewahren die aktuelle Suche und
kombinierte Filter, setzen dabei die Seite zurück.

## Darstellung und praktische Grenze

Breite Fenster zeigen eine kompakte Zeilenliste. Bis 1100 CSS-Pixel werden die
Fälle als Karten dargestellt; bei 640 bis 960 CSS-Pixeln sind Suche, Aktionen und
getrennte Zustände ohne horizontalen Seitenscroll bedienbar. Weitere Filter und
globale Kennzahlen sind eingeklappt. Liste und native Formulare funktionieren ohne
JavaScript. Die Navigation zeigt Eingang, Bestand und vorhandene Einstellungen.

GET startet keine Aufnahme oder technische Prüfung und verändert keine
Anwendungsdaten. Keine Migration, neue Queue, allgemeine Drittclient-API oder
Frontend-Buildkette wurde benötigt. Einstellungen und Prioritätsexport behalten
vorerst ihre klassische Gestaltung. Review-Arbeitsvorrat, Vergleichsbilder und
Meldungen bleiben eigene Vorhaben mit den noch offenen Fachregeln.
