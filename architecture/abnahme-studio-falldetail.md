# Abnahme – Studio-Falldetail v1

Stand: 2026-10-03. Bezugsstand: ausdrücklich beauftragter Folgeabschnitt nach dem
[Studio-Eingang](abnahme-studio-ingest.md). Der Nutzer benannte den Bruch zu den
noch klassischen Einzelseiten und beauftragte den vorgeschlagenen
[Falldetailumfang](ui-workflow.md#beauftragter-folgeabschnitt-studio-falldetail-v1)
mit „setz es um“. Die Umsetzung ist integriert und in HTTP-, Gesamt- und
isolierter Browserabnahme geprüft.

## Gelieferter Umfang

- GET `/studio/findings/{id}` liefert eine eigene dunkle Studio-Detailseite.
  Studio-Eingang und seine Toasts öffnen diese Seite. Klassische Intake-Links
  bleiben klassisch; beide Detailseiten verlinken denselben Fall in der anderen
  Oberfläche. Ein unbekanntes oder ungültiges Finding liefert 404.
- Große Bildbelegfläche und kompakter Inspector für Bewertung, technische
  Beobachtung, Notiz und Kontakt. Bis 1100 CSS-Pixel sind die Bereiche gestapelt;
  Beleg, Entscheidung und Verlauf sind direkt über echte Anker erreichbar.
- Mehrere Bildbelege sind auswählbar; das Original öffnet als lokales Artefakt.
  Ohne JavaScript sind alle Bilder und die nativen Formulare/Details nutzbar.
  Gemeldete Ziel-URLs werden als Text und bei verfügbarem Clipboard als
  Kopierfunktion dargestellt. Die Seite lädt keine Zielwebsite.
- Aufnahmezeit und Ablagezeit stehen getrennt. Unbekannte Aufnahmezeiten bleiben
  unbekannt. Fehlende oder pfadlose Bilddateien werden sichtbar erklärt und die
  Evidence-Zeilen bleiben erhalten. Aktueller Queue-/Aufnahme-/Fehlerzustand und
  Browser-Schutz-Metadaten bleiben auch neben älteren Bildern sichtbar.
- Die bestehenden manuellen Aktionen bestätigen, behoben, verwerfen und Duplikat
  folgen denselben Regeln wie Classic. Eine Bildauswahl wählt keinen Bezug im
  Bewertungsformular voraus. Beobachtungs- und Beleggrundlage bleiben ohne
  ausdrückliche Auswahl unbekannt.
- Notizen werden ausdrücklich über `/findings/{id}/notes` gespeichert; Kontakt
  hält ausschließlich seinen unabhängigen Zeitpunkt fest. CSRF schützt alle drei
  Schreibwege. Optionales `surface=studio` wählt die interne Rückkehr auf
  dieselbe Fall-ID; es erlaubt keine fremde Weiterleitungsadresse.
- Technik und Historie bleiben eingeklappt: ausdrückliche Bewertungshistorie,
  Screenshot-Aufträge, Evidence und technische Beobachtungen. Alte unklare
  Entscheidungsdaten erhalten keine nachträglich erfundene Grundlage.

`FindingDetailService`, `FindingDetailView` und `FindingAssessmentState` liefern
beiden Oberflächen dieselbe lesende Projektion und Aktionslogik. Screenshot-
Verfügbarkeit und Aufnahmezeit stammen ebenfalls aus dieser gemeinsamen
Projektion. Die Schreibwege verwenden `FindingService`; es gibt keine zusätzliche
Studio-Fachlogik oder Migration. GET startet weder Retest noch Capture und
bereinigt keine Daten.

## Nachweise

| Prüffall | Ergebnis |
| --- | --- |
| Studio-GET, 404, keine Schreib-/Browseraktivität | Fokussierte HTTP-Abnahme erfolgreich. |
| CSRF, erlaubte Aktionen, ausdrückliche Grundlage und sichere Rückkehr | Fokussierte HTTP-Abnahme erfolgreich. |
| Notiz speichern und unabhängiger Kontaktzeitpunkt, gleicher Classic-Zustand | Fokussierte HTTP-Abnahme erfolgreich. |
| Fehlende Bilder, fehlgeschlagener Auftrag und Challenge-Metadaten, Escaping | Fokussierte HTTP-Abnahme erfolgreich. |
| Studio-HTTP-Suite | Zehn Tests, 271 Assertions erfolgreich. |
| Vollständige PHPUnit-Suite | 172 Tests, 1.788 Assertions erfolgreich. |
| Container-Lint dev/test, PHP-/JavaScript-Syntax und `git diff --check` | Erfolgreich. |
| Reale lokale DDEV-Routen | `/studio` und vorhandenes `/studio/findings/{id}` liefern HTTP 200; Detailroute im Router registriert. Detail-GET wurde ausschließlich lesend geprüft. |
| Responsive Browsergeometrie und visuelle Abnahme | 1440/960/720/640 × 900 und 390 × 844 ohne horizontalen Überlauf, auch mit geöffnetem Verlauf. Desktop- und Half-Screen-Belegansichten sowie Entscheidungen bei 720/390 Pixeln und Verlauf bei 720 Pixeln visuell ohne Befund geprüft. |
| Bildauswahl, lokale Originale und fehlende Dateien | Auswahl erhält alle Belege, unbekannte Aufnahmezeiten und unbekannte Bewertungsgrundlage. Original öffnet lokal; Artefaktnamen mit Leerzeichen, `#`, `?` und Unicode sind segmentweise kodiert und laden erfolgreich. |
| Native Bedienung ohne JavaScript | Alle Bilder, Formulare und Bereichsanker verfügbar. |
| Notiz, Kontakt und explizite Bewertung | Notiz wird ausdrücklich gespeichert, Enter erzeugt einen Umbruch. Gleicher gespeicherter Zustand in Classic und Studio; unbekannte und ausdrücklich gewählte Bewertungsgrundlagen korrekt erhalten. |
| Eingang → Falldetail → Eingang | Studio-Falllink und exakte URL-Wiederholung korrekt; ungesendeter nächster URL-Entwurf und Sitzungsverlauf bleiben erhalten. |
| Datenneutralität von GET und Belegdarstellung | Vollständiger Datenbanksnapshot nach rein lesenden Browseraktionen unverändert. Nach den ausdrücklich ausgelösten Schreibaktionen bleiben ScreenshotJobs, RetestRuns und Evidence bytegleich im Tabellensnapshot. |

Die Testwerte stammen aus der fokussierten `StudioFindingTest`-Abnahme und der
anschließend vollständig ausgeführten Suite. Der isolierte Browserlauf bestand
alle 20 Prüfungen. Es gab keine externen Requests, JavaScript-Ausnahmen oder
internen HTTP-Fehler. Seine sieben lokalen Fixturefälle decken verfügbare,
fehlende und leere Belege, wartende/laufende/fehlgeschlagene Aufträge und nicht
beendete Browser-Schutzseiten ab. Eine Zielwebsite wurde nicht aufgerufen.

Die sieben beabsichtigten POSTs bestanden aus einer Notizspeicherung, einem
Kontaktzeitpunkt, vier Bewertungsänderungen und einer exakten URL-Wiederholung
im Eingang. Die Wiederholung erzeugte weder zusätzliches Finding noch weiteren
ScreenshotJob. Nach Abschluss enthielt die Fixture-Datenbank sieben Findings,
fünf ScreenshotJobs, einen RetestRun, sechs Evidence-Zeilen und vier
FindingAssessments; die ursprünglichen Auftrags-, Lauf- und Belegdaten blieben
unverändert. Duplikatkennzeichnung und ausdrückliche Reaktivierung erhielten
Notiz, Kontaktzeitpunkt und vollständige Bewertungshistorie.

## Wiederholbare Browserabnahme

`tests/browser/studio-detail.cjs` verwendet den vorhandenen Playwright-Sidecar.
Der eigene Testserver in `tests/Support/studio_detail_browser_router.php` benötigt
eine neue `/tmp/librebugbounty-studio-detail-*`-Wurzel und verwendet ausschließlich seine
isolierte Datenbank und lokale Fixture-Bilder. Kein Screenshot-Worker wird gegen
sie gestartet; Requests außerhalb des Testorigins werden blockiert. Der Router
gehört nicht zu den Produkt-Routen.

Die dokumentierten Startbefehle stehen im Browser-Skript. Standardmäßig läuft
der Testserver auf Port 8089; der erfolgreiche abschließende Lauf verwendete
Port 8094 mit `STUDIO_BROWSER_BASE=http://web:8094`. Seine isolierte Wurzel und
sein Server wurden danach entfernt. Ergebnis-JSON und zehn Browserbilder liegen
unter dem ignorierten `var/studio-detail-acceptance/` und sind mit demselben
Skript reproduzierbar. Die Bilder ergänzen die automatischen Geometrieprüfungen;
es gibt damit keine pauschale visuelle Prüfung sämtlicher möglichen Altbestände.

## Grenzen und nächste Vorhaben

Die technische Beobachtungshistorie zeigt wie Classic höchstens die 20 jüngsten
gespeicherten Läufe. Evidence, ScreenshotJobs und Bewertungshistorie werden
vollständig eingelesen. Die sichtbare Aufnahmezeit stammt aus einem zugehörigen
ScreenshotJob; ohne diesen Bezug ist sie unbekannt.

Ein laufender Screenshotstand benötigt aktuell Reload der Detailseite. Der
Studio-Eingang behält seinen eigenen rein lesenden Statusabruf. Notizen werden
ausdrücklich gespeichert; Autosave oder tablokale Notizentwürfe sind für diese
Seite nicht zugesagt.

Es gibt keinen Studio-Bestand mit eigener Arbeitsliste, keinen Review-
Arbeitsvorrat, keinen Vorher-/Nachher-Vergleich und keine neue Prüf- oder
Capture-Automatik. Der Bestandlink öffnet die klassische Übersicht, Einstellungen
ihre vorhandene Seite. Ein Studio-Bestand bleibt möglicher nächster Zuschnitt;
Review benötigt weiterhin seine noch offenen Regeln zu Sichtungsgründen,
Erledigen, Zurückstellen und Vergleichsreferenzen.

Das historische Studio-Ingest-Abnahmeprotokoll beschreibt weiterhin den damaligen
ersten Umfang, in dem Fall-Links klassisch öffneten. Dieser Folgeabschnitt ersetzt
gezielt diesen Wechsel; der schnelle Speichervertrag und gemeinsame Intake-
Entwurf bleiben unverändert.
