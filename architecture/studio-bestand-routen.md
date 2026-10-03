# Studio-Bestand und Wechsel der Startseite – Diskussionsstand

Stand: 2026-10-03. Dieser Abschnitt ist ein Vorschlag für den nächsten Ausbau,
kein bereits beauftragter Umsetzungsplan. Der Nutzer möchte die vorhandenen
Funktionen für Liste und Suche in der Studio-Gestaltung sehen, Studio zur normalen
Oberfläche unter `/` machen und die bisherigen Ansichten unter `/legacy` behalten.
Für die folgenden Routen wird vorläufig der schnelle Eingang auf `/` angenommen:
Er entspricht dem bestätigten Half-Screen-Ablauf für mehrere einzelne URLs.
Der Nutzer hat diese Startseitenwahl noch nicht ausdrücklich bestätigt.

## Ausgangspunkt

- Studio-Eingang und Studio-Falldetail sind unter `/studio` und
  `/studio/findings/{id}` nutzbar. Auch „Fall öffnen“ im klassischen Eingang und
  in der bisherigen Übersicht führt inzwischen zum Studio-Falldetail.
- Die klassische Übersicht liegt auf `/`, die klassische Einzelseite auf
  `/findings/{id}`. Die Bestandsprojektion `FindingReadRepository` bietet
  kombinierbare Filter für manuelle Bewertung, letzte technische Beobachtung,
  Kontakt, Archiv/Duplikate, Typ, Schwere und historische Diagnosewerte sowie
  Zähler und Paging. Die vorhandene Textsuche erfasst nur die Domain. Auslesen
  der Queryparameter und Erzeugen der Zählerlinks liegen derzeit im klassischen
  `WebController` und sollten für beide Oberflächen gemeinsam nutzbar werden.
- Die bestehenden Browser- und JSON-Schreibwege, `/api/findings`,
  `/artifacts/{path}` und die GET-Seiten liegen auf gemischten Routen. Eine
  pauschale Präfixänderung am klassischen Controller würde auch Schreibwege
  verschieben. Die JSON-Antwort liefert bisher einen klassischen `detailUrl`.

## Begründeter Vorschlag

Zuerst ein eigener Studio-Bestand als benutzbare Seite: eine kompakte,
halbbreitentaugliche Fallliste mit Suche nach Domain, Titel und vollständiger
Fall-URL, den vorhandenen fachlichen Filtern und einem ausdrücklichen Archiv.
Die Liste verwendet dieselbe lesende Projektion und Zählregeln wie Classic.
Ihre Filterinterpretation und Zählerlinks werden aus dem klassischen Controller
in einen gemeinsamen lesenden Anwendungsfall gezogen, statt die Regeln in der
Studio-Seite ein zweites Mal zu schreiben. Ein zusätzliches allgemeines API-
Produkt oder eine neue Frontend-Buildkette ist hierfür nicht erforderlich.
Bewertung, technische Beobachtung und Kontakt erscheinen getrennt; ein
`inconclusive`-Ergebnis wird nicht zur Bewertung „behoben“. Filter, Suchtext und
Seite stehen in der URL, damit ein Fall aus einer gefilterten Liste wieder auffindbar
ist. Auf breiten Fenstern kann die Liste mehr Spalten zeigen; bei halber Breite
bleibt der Fall als gut lesbare Zeile mit wenigen wichtigen Zuständen bedienbar.
Der Klick öffnet das bereits vorhandene Studio-Falldetail. Eine zweite
Beurteilungslogik oder ein neuer Retest gehört nicht in diesen Schritt.

Sobald diese Liste nutzbar ist, die Oberflächenrouten zusammenhängend wechseln:

| Zweck | Vorgeschlagene kanonische GET-Route |
| --- | --- |
| Studio-Start | `/` – vorläufig schneller Eingang |
| Studio-Bestand | `/findings` – falls `/` der Eingang wird |
| Studio-Falldetail | `/findings/{id}` |
| Klassische Übersicht und Details | `/legacy` und `/legacy/findings/{id}` |
| Vorhandene Studio-Bookmarks | `/studio` und `/studio/findings/{id}` leiten auf die neuen kanonischen Routen um |

Klassische Einstellungen und der HTML-Prioritätsexport erhalten bis zu einer
eigenen Studio-Darstellung sichtbare Legacy-Ziele. Bestehende GET-Bookmarks
sollten intern weiterleiten; alte Filterabfragen auf `/` sollten mit ihren
Parametern zur neuen Studio-Liste führen, damit sie nicht stillschweigend beim
Eingang landen. Die funktionalen POST-, API- und
Artefakt-URLs bleiben während des Oberflächenwechsels stabil. Klassische
Formulare und ihre Rücksprünge müssen passend zu `/legacy` führen; Studio-Aktionen
zur Studio-Detailroute. Die Navigation zeigt nur nutzbare Ziele.

Sinnvoll sind zwei prüfbare Schritte: Erst die Studio-Liste übergangsweise unter
`/studio/findings` bereitstellen und mit echten Filtern, Suche und halber
Fensterbreite abnehmen. Dann `/`, `/findings/{id}` und die Legacy-Routen gemeinsam
kanonisieren und interne Links, Filterabfragen, Rücksprünge und Bookmarks prüfen.
So gibt es keinen Zwischenstand, in dem die klassische Übersicht verschwunden
ist, bevor ihr Studio-Ersatz benutzbar ist. Einstellungen und Prioritätsexport
können bis zu einer späteren Studio-Darstellung unter Legacy bleiben; ihre
bisherigen GET-Adressen erhalten Weiterleitungen.

**Alternative:** Die komplette klassische Seite als Studio-Start neu gestalten.
Das spart einen zusätzlichen Arbeitsbereich, lädt bei halbbreitem Fenster aber
gleichzeitig Eingang, Statistik, Filter und lange Liste. Für die bereits
bestätigte schnelle Einzeleingabe ist ein klarer, eigenständiger Eingang die
passendere Voreinstellung. Der Nutzer entscheidet, ob er ihn oder den Bestand
als Startseite bevorzugt.

## Nächster entscheidender Punkt

Die Wahl der Startseite legt fest, wie Eingang und Liste benannt und verlinkt
werden. Für den vorgeschlagenen ersten Schritt gilt als Abnahme: Die aktive Liste
und das Archiv zählen dieselben Fälle wie Classic; Domain-, URL- und Titelsuche
arbeiten zusammen mit den vorhandenen Bewertungs-, Beobachtungs- und
Kontaktfiltern; Filter, Suche und Seite bleiben beim Öffnen und Zurückkehren aus
einem Fall erhalten. Bei 640 bis 960 CSS-Pixeln bleiben Zeilen und Aktionen ohne
horizontalen Seitenscroll bedienbar. Der reine Listenabruf startet keine
technische Arbeit. Beim späteren Routenwechsel müssen alte GET-Links erreichbar
bleiben und die bestehenden POST-, API- und Artefaktverträge weiter funktionieren.
