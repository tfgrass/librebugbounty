# Umsetzungsplan – Studio-Ingest v1 / Half-Screen

Stand: 2026-10-03. Der Nutzer hat den klassischen Gesamtscreen als zu überladen
bewertet und den parallelen Studio-Eingang als nächsten vorzubereitenden Abschnitt
gewählt. Dieser Plan beauftragt noch keinen vollständigen Studio-Umbau. Er schneidet
den bereits funktionierenden schnellen Eingang als ersten eigenständig nutzbaren
Studio-Arbeitsbereich zu.

## Ziel und Nichtumfang

Unter `/studio` entsteht neben der unveränderten klassischen Anwendung eine ruhige,
dunkle Eingangsseite. Ihr primärer Arbeitsfall ist ein halbbreites Browserfenster:
Eine URL aus dem Nachbarfenster kopieren, mit Enter speichern und unmittelbar die
nächste URL übernehmen. Fünf aufeinanderfolgende Eingaben sollen ohne zusätzliche
Tabs und ohne Warten auf Screenshot oder Retest möglich sein.

Zum ersten Abschnitt gehören:

- eine einzelne dominante URL-Eingabe mit sichtbarer Speicherbestätigung;
- das bestehende Kennzeichen und eine optionale Notiz unter kompakten Details;
- ein kompakter Verlauf der aktuellen Tabsitzung mit Fall-Link, Speicher- und
  Screenshotstand;
- eine eigenständige Studio-Gestaltung, die bei halber Fensterbreite funktioniert;
- ein funktionierender Wechsel zur klassischen Oberfläche;
- dieselben Fehler-, Duplikat-, Retry- und Pollingregeln wie im schnellen Eingang.

Nicht zu diesem Abschnitt gehören Bestandsfilter, Statistiken, Screenshotvorschau,
Inspector, Bewertung, Review, Meldungen, Mehrfachimport, neue technische Prüfungen,
neue API-Endpunkte oder ein allgemeiner Frontend-Stack. Der Link eines Verlaufs-
eintrags öffnet zunächst weiterhin die klassische Falldetailseite.

## Tragende Entscheidungen und Vorschläge

Bereits bestätigt sind die parallele Erhaltung der klassischen Oberfläche, eine
URL pro Eingabe, Rückkehr nach bestätigter dauerhafter Speicherung, maximal 50
Einträge im tablokalen Verlauf, kein automatischer Retry eines unbestätigten POSTs
und API-Nutzung zunächst nur durch die beiden Oberflächen.

Für diesen Abschnitt wird folgende konkrete Umsetzung empfohlen:

- `/` bleibt die klassische Anwendung; `/studio` wird der bewusste neue Einstieg.
- Symfony liefert eine eigene Studio-Seite aus. Ein separates SPA-Framework oder
  ein neuer Build-Prozess ist für diesen schmalen Arbeitsbereich nicht nötig.
- `POST /api/findings` und `GET /api/findings/status` bleiben die einzige fachliche
  Schreib- beziehungsweise Lesegrenze des Studio-Eingangs.
- Kennzeichen und Notiz liegen standardmäßig unter „Details hinzufügen“. Das
  konfigurierte Standardkennzeichen bleibt aktiv und ist neben dem Details-Link
  knapp sichtbar.
- Classic und Studio verwenden innerhalb desselben Tabs zunächst denselben
  Intake-Verlauf. Dadurch geht beim bewussten Wechsel der Oberfläche kein Entwurf
  verloren. Verschiedene Tabs bleiben durch `sessionStorage` getrennt. Der
  Speichername wird dennoch konfigurierbar, falls diese Empfehlung später
  revidiert wird.
- Die untere Arbeitsbereichsleiste zeigt im ersten Abschnitt nur echte Ziele:
  „Eingang“ als aktiven Bereich und einen Übergang zum klassischen Bestand. Review
  und Meldungen werden nicht als funktionslose Schaltflächen vorgetäuscht.

Die eingeklappten Details und der gemeinsame Verlauf sind Empfehlungen, keine aus
früherem Schweigen abgeleiteten Nutzerentscheidungen. Beide lassen sich ohne
Änderung des API-Vertrags anpassen.

## Informationshierarchie und Skizze

Die Seite verwendet keine Dashboard-Zahlen, Filter oder große Karten. Eingabe und
Verlauf bilden die gesamte Arbeitsfläche:

```text
┌ LibreBugBounty · STUDIO                         Klassisch ↗ ┐
├─────────────────────────────────────────────────────────────┤
│ EINGANG                              5 in dieser Sitzung    │
│ [ https://…                                  ] [ Erfassen ] │
│ Kennzeichen: OPENBUGBOUNTY · Details hinzufügen            │
│ Screenshot-Auftrag wird separat verarbeitet.                │
├ DIESE SITZUNG ──────────────────────────────────────────────┤
│ ● soxo.de                         gespeichert · wartet      │
│   https://soxo.de/…                         Fall öffnen ↗   │
│ ✓ example.org                     Screenshot verfügbar     │
│   https://example.org/…                    Fall öffnen ↗   │
│ ! example.net                     Speicherung unbestätigt  │
│   Eingabe wieder übernehmen                              ↗ │
│ …                                                           │
├─────────────────────────────────────────────────────────────┤
│                         ● Eingang     Bestand klassisch ↗   │
└─────────────────────────────────────────────────────────────┘
```

Die unmittelbare Speicherantwort erscheint primär in der neuen Verlaufszeile.
Toasts bleiben späteren Statusänderungen vorbehalten und dürfen die Eingabe im
halbbreiten Fenster nicht verdecken.

## Responsive und visuelle Regeln

Der primäre Prüfbereich liegt zwischen 640 und 960 CSS-Pixeln Breite:

- Die Studio-Shell füllt `100dvh` mit schmaler Kopfzeile, Arbeitsfläche und fester
  unterer Bereichsleiste. Der Verlauf scrollt innerhalb der verbleibenden Höhe;
  die Eingabe bleibt sichtbar.
- Zwischen 700 und 1099 Pixeln stehen URL-Feld und „Erfassen“ in einer Zeile.
  Unterhalb davon werden sie gestapelt. Horizontaler Dokument-Scroll ist in
  keiner Zielbreite zulässig.
- Ab etwa 1100 Pixeln darf die Fläche großzügiger werden. Eine künstliche
  Drei-Spalten-Aufteilung gehört nicht zum ersten Studio-Abschnitt.
- Auf sehr schmalen Touch-Ansichten wird regulärer Seitenscroll zugelassen, damit
  Bildschirmtastatur und Fokus nicht mit einer starren Viewporthöhe kollidieren.

Visuelle Richtung: dunkles Graphit ohne Glasverlauf, klare Trennlinien statt
großer Karten, 8-Pixel-Raster, Radien von 5 bis 8 Pixeln, Systemschrift für die
Oberfläche und Monospace für URLs. Vorgeschlagene Grundwerte sind `#121416` für
den Hintergrund, `#1b1e22` und `#24282d` für Arbeitsflächen, `#343a42` für Linien,
`#eef1f4` für Text, `#9aa3ad` für Nebeninformationen und `#4f95ff` für Fokus und
aktive Navigation. Grün, Amber und Rot kennzeichnen Erfolg, Warten/Duplikat und
Fehler zusätzlich zu verständlichem Text.

## Interaktionsvertrag

1. Beim Öffnen liegt der Fokus im URL-Feld.
2. Einfügen und Enter im URL-Feld senden genau einen POST. Der Button bleibt als
   sichtbare Alternative erreichbar; Enter in der Notiz erzeugt weiter einen
   Zeilenumbruch.
3. Erst eine bestätigte Antwort leert unveränderte URL und Notiz. Hat der Nutzer
   bereits weitergetippt, bleiben Werte und aktives Feld erhalten.
4. Die neue Verlaufszeile unterscheidet `stored`, `duplicate`, Validierungsfehler
   und unbekannten Requestausgang. Ein unbekannter Ausgang löst nie automatisch
   einen zweiten POST aus.
5. Retry übernimmt die frühere Eingabe bewusst ins Formular und fokussiert die URL.
6. Unveränderte Statusabrufe ersetzen weder DOM noch Fokus. Nachträgliche Änderungen
   erscheinen kompakt in der zugehörigen Zeile; Toasts nur im sichtbaren,
   fokussierten Tab.
7. Die Liste zeigt den neuesten Eintrag oben, behält bis zu 50 Einträge und bleibt
   per Reload in diesem Tab erhalten.

## Technische Umsetzung

Vorgesehene Struktur ohne neuen Build-Stack:

- `src/Controller/StudioController.php`: ausschließlich GET `/studio`,
  Standardkennzeichen und CSRF-Token; keine Bestands- oder Statistikabfragen.
- `templates/studio/intake.php`: vollständige Studio-Shell mit semantischem
  Intake-Markup.
- `public/css/studio.css`: ausschließlich `.studio-*`- beziehungsweise
  `[data-studio]`-Styles; keine Abhängigkeit vom globalen Classic-CSS.
- `public/js/intake.js`: vorhandene Transport-, Session-, Retry- und Pollinglogik
  einmalig weiterverwenden, aber von festen Classic-Klassen lösen. Root,
  Darstellungsvariante, Endpunkte und Storage-Key werden über `data-*` gelesen.
- `templates/home.php` und das Classic-Layout behalten Darstellung und Verhalten;
  ergänzt wird nur ein klarer Link zum Studio-Eingang.

Die gemeinsame Logik darf zwei kleine Renderer für ausführliche Classic-Zeilen
und kompakte Studio-Zeilen besitzen. Eine allgemeine Komponentenbibliothek ist
dafür nicht erforderlich. CSRF-Zweck, API-Antworten und Datenmodell bleiben
unverändert; es ist keine Migration nötig.

## Vorgehensreihenfolge

1. Das vorhandene Intake-Skript anhand seiner aktuellen Browserabnahme in
   gemeinsame Zustands-/Transportlogik und eine austauschbare Darstellung teilen.
   Classic bleibt dabei der Regressionstest.
2. StudioController, eigenständiges Template und isolierte Styles hinzufügen.
3. Den kompakten Renderer und den bewusst konfigurierbaren Session-Namespace
   anschließen.
4. Wechsel in beide Richtungen ergänzen und die klassische Seite auf unveränderte
   Funktion und Darstellung prüfen.
5. Half-Screen-, Tastatur-, Fehler- und Fünf-URL-Szenarien im Browser abnehmen;
   Ergebnisse und Grenzen in einem eigenen Abnahmeprotokoll festhalten.

## Abnahme

- Bei 960×900, 720×900 und der unteren Half-Screen-Grenze 640×900 CSS-Pixeln sind
  URL und Aktion ohne Scrollen sichtbar, mindestens fünf kompakte Einträge lassen
  sich überblicken und es gibt keinen horizontalen Scroll. Der 640-Pixel-Test
  enthält eine lange URL ohne günstige Trennstellen. 390×844 dient als zusätzliche
  schmale Touch-Kontrolle.
- Fünf kontrollierte lokale URLs lassen sich nur mit Einfügen und Enter einzeln
  nacheinander speichern. Nach jeder bestätigten Speicherung ist die nächste
  Eingabe möglich, ohne Screenshot oder Retest abzuwarten.
- `stored`, exaktes `duplicate`, HTTP 422, ungültiges CSRF, Transportabbruch und
  bewusster Retry sind sichtbar unterscheidbar. Es entsteht nie ein ungewollter
  zweiter POST.
- Reload erhält Entwurf und Verlauf; ein neuer Tab beginnt leer. Der Wechsel
  Classic ↔ Studio im selben Tab erhält nach der aktuellen Empfehlung Verlauf,
  URL, Kennzeichen und Notiz in beide Richtungen, auch bei einem nur teilweise
  ausgefüllten Entwurf.
- Statuspolling bleibt lesend, unveränderte Polls erhalten Tastaturfokus, und
  Toasts erscheinen nur im sichtbaren, fokussierten Tab.
- Bei 640 Pixeln Breite wird während einer begonnenen Folgeeingabe ein späterer
  Statuswechsel samt Toast ausgelöst. URL-Feld und Aktion bleiben sichtbar und
  bedienbar; der aktuelle Fokus bleibt erhalten.
- Tastaturbedienung, sichtbare Fokusmarkierung, Text neben Statusfarben,
  `prefers-reduced-motion` und ausreichender Kontrast werden geprüft.
- Browserbilder bei 960×900 und 640×900 dokumentieren die visuelle Abnahme:
  ruhige Hierarchie ohne große Classic-Karten, dominante URL-Eingabe, fünf gut
  scanbare Zeilen und keine abgeschnittenen Inhalte.
- Browserabnahmen blockieren externe Requests. Die isolierte Datenbank enthält
  nach fünf neuen Eingaben genau fünf Findings und fünf initiale ScreenshotJobs,
  aber keine durch den Intake erzeugten RetestRuns.
- Bestehende PHPUnit-Suite, Container-Lint, PHP-/JavaScript-Syntax und
  `git diff --check` bleiben erfolgreich.

## Verbleibender Spielraum

Exakter Akzentton, Feintypografie und Zeilenhöhe können beim ersten Browserbild
angepasst werden, solange Hierarchie und Half-Screen-Abnahme erhalten bleiben.
Soll sich beim visuellen Versuch zeigen, dass fünf Zeilen bei der real verwendeten
Fensterhöhe nicht sinnvoll lesbar sind, wird zuerst Dichte und Nebeninformation
reduziert; der Umfang wird nicht durch zusätzliche Paneele vergrößert.

Eine Bestandsliste, ein ausgewählter Fall oder ein Inspector neben dem Eingang
würden eine weitere Lesegrenze, Auswahlzustand und eine andere Half-Screen-
Priorisierung erfordern. Das ist eine Stop-Bedingung für diesen Abschnitt und wird
als eigenes folgendes Vorhaben geplant.
