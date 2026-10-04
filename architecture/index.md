# LibreBugBounty – Architekturstand

Stand: 2026-10-04. Einstieg für weitere Architekturgespräche und Umsetzung.

## Aktueller Release-Stand 2.0.0

Studio ist die einzige Produktoberfläche. Die kanonischen Seiten liegen unter
`/`, `/findings`, `/review`, `/statistics`, `/export` und `/settings`; es gibt
keine zweite Webdarstellung und keinen klassischen Renderer mehr. Verbleibende
`/legacy`- und `/legacy/findings/{id}`-GET-Adressen dienen ausschließlich als
Weiterleitungen für alte Bookmarks auf die entsprechende Studio-Seite.
`/legacy/settings` rendert vorübergehend dieselbe moderne Settings-Seite, damit
bereits im Browser gespeicherte permanente Weiterleitungen keine Schleife bilden.

Der UI-Abbau ändert weder gespeicherte Fälle und Belege noch die gemeinsamen
POST-, CLI- und Artefaktverträge. Historische Datenwerte und Diagnosefilter
bleiben lesbar, ohne daraus neue manuelle Entscheidungen abzuleiten. Die
nachfolgenden datierten Arbeitsabschnitte und Abnahmen dokumentieren den Weg zu
2.0.0; Aussagen über damals parallele Oberflächen, damalige Zielrouten oder noch
offene UI-Arbeit sind historische Prüfstände und werden durch diesen Nachtrag
ersetzt.

## Historische Zielsetzung vor 2.0.0

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
- Vorhandene Abläufe wurden zuerst in der aktuellen Ansicht stabilisiert. Der
  parallele Studio-Eingang ist umgesetzt; nach dem ausdrücklichen Folgeauftrag
  erhält auch die Fallbearbeitung eine Studio-Ansicht mit großem Belegfeld und
  kompaktem Inspector. Der weitere Resolve-Umbau folgt in eigenen Vorhaben.

## Historische Arbeits- und Prüfstände

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

## Historische Folgeplanung und damalige offene Punkte

Arbeitsabschnitt 2 ist funktional umgesetzt. Stop/Start nach dem zuletzt ergänzten
Warten auf das Queue-Schema war erfolgreich; Worker, Sidecar, echte Bilder,
Serialität und Dateipersistenz wurden danach erneut geprüft. Der zusätzliche
N01-Nachweis aus einer vollständig frischen isolierten DDEV-Projektkopie ist
am 2026-10-03 erbracht: [Betriebsabschluss](abnahme-betriebsabschluss.md).

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

Der API-Umfang ist bewusst auf die klassische und die parallele
Studio-Ansicht begrenzt. Eine allgemeine Drittclient-API, eine neue Queue für
technische Prüfungen und die Studio-Oberfläche selbst gehören nicht zu diesem
Abschnitt. [Vertrag, Nachweise und Grenzen](abnahme-schneller-eingang.md): 155
isolierte Tests mit 1.446 Assertions sowie der End-to-End-Browserlauf mit fünf
Einzeleingaben waren erfolgreich.

Weiter offen bleibt **4: Betriebsnachweise abschließen**,
einschließlich des frischen isolierten DDEV-Aufbaus. Die Regeln zum Abarbeiten von
Hinweisen bleiben für ein späteres Review-Folgepaket offen; der erste begrenzte
Sichtungsablauf ist unter [Studio-Review v1](studio-review.md) beschrieben.
[Zuschnitt und spätere offene Fragen](ui-workflow.md#erneutes-sparring-zum-zuschnitt-von-abschnitt-3).
Historische Mehrdeutigkeit bleibt erhalten, statt frühere Entscheidungen zu erfinden.

**Studio-Ingest v1 umgesetzt:** Der Nutzer bewertet die klassische Gesamtseite
für den Eingang als zu überladen. Studio-Ingest v1 stellt deshalb den bereits
funktionierenden schnellen Eingang parallel unter `/studio` bereit: dunkle,
reduzierte Arbeitsfläche, dominante Einzeleingabe und kompakter Sitzungsverlauf,
optimiert für 640 bis 960 CSS-Pixel breite Fenster. Die klassische Oberfläche
bleibt bestehen; Bestand, Inspector, Review und Meldungen gehören nicht in diesen
ersten Studio-Abschnitt.

Beide Oberflächen verwenden dieselbe Fallverwaltung und die vorhandenen schmalen
Intake-/Statusgrenzen. Ein eigenes Symfony-Template und isolierte Styles tragen
die neue Ansicht. Kennzeichen und Notiz liegen unter „Details hinzufügen“; Entwurf
und Verlauf bleiben beim Wechsel im selben Tab gemeinsam erhalten. Auch bei
Zurück-/Vorwärtsnavigation und BFCache-Wiederherstellung liest das Dokument diesen
aktuellen Stand neu ein.
[Prüfstand und Grenzen](abnahme-studio-ingest.md),
[Umsetzungsplan und Abnahmevertrag](plan-studio-ingest.md),
[Entscheidungsweg](ui-workflow.md#nächstes-vorhaben-studio-ingest-v1--half-screen).

**Studio-Falldetail v1 implementiert:** Nach dem Eingang hat der Nutzer den Bruch
beim Öffnen der weiterhin klassischen Einzelseiten benannt und den vorgeschlagenen
Folgeabschnitt ausdrücklich zur Umsetzung beauftragt. Unter
`/studio/findings/{id}` stehen große Belegansicht und ein kompakter Inspector für
Bewertung, Notiz und Kontakt bereit. Bis 1100 CSS-Pixel werden die Bereiche
gestapelt; die Anker Beleg, Entscheidung und Verlauf bleiben direkt erreichbar.
Zum ersten Abnahmezeitpunkt öffnete der Studio-Eingang diese Ansicht, der
klassische Eingang noch die klassische Einzelseite. Beide Detailseiten verlinken
denselben Fall in der anderen Oberfläche.

**Nachtrag:** Auf Wunsch des Nutzers führen jetzt auch „Fall öffnen“ im klassischen
Eingang und die Falllinks in der bisherigen Übersicht zur Studio-Detailseite.
Die früher beschriebene klassische Zielseite dieser Links ist damit ersetzt;
die klassische Detailseite bleibt direkt erreichbar.

Classic und Studio lesen `FindingDetailService` und verwenden dieselben
Bewertungs-/Kontaktregeln. Die vorhandenen Schreibendpunkte erhalten optional
`surface=studio`; Notizen haben einen ausdrücklichen, CSRF-geschützten
Speicherweg. Bildauswahl wählt keine Bewertungsgrundlage voraus. Aktuelle
Queue-/Fehlerzustände bleiben auch neben älteren Bildern sichtbar, Aufnahme- und
Ablagezeit werden getrennt. Fehlende Dateien werden erklärt, die Belege bleiben
erhalten. GET löst keine Prüfung und keine Aufnahme aus.

Die fokussierte HTTP-Abnahme ist mit zehn Tests und 271 Assertions erfolgreich;
die Gesamtsuite mit 172 Tests und 1.788 Assertions. Der isolierte Browserlauf
bestand 20 Prüfungen an fünf Fenstergrößen, einschließlich NoJS und gemeinsamem
Classic-/Studio-Zustand. Nachweise und Grenzen dokumentiert
[Abnahme Studio-Falldetail v1](abnahme-studio-falldetail.md). Eine Studio-Bestandsliste,
ein Review-Arbeitsvorrat und Bildvergleich sind nicht Teil dieses Abschnitts.

**Studio-Bestand und Routenwechsel umgesetzt:** Der Nutzer hat den Vorschlag
mit „genau bau das so“ bestätigt. Der schnelle Eingang liegt jetzt auf `/`, der
Studio-Bestand mit Domain-/Titel-/URL-Suche, kombinierten Filtern, Archiv, Paging
und globalen Kennzahlen auf `/findings`, das Falldetail auf `/findings/{id}`.
Classic bleibt unter `/legacy`, mit Details, Einstellungen und Prioritätsexport
unter diesem Präfix. Alte Studio-/Filter-/Settings-/Export-GET-Links leiten mit
erhaltenen Parametern weiter. POST-, API- und Artefaktwege bleiben stabil.

`FindingListService` teilt die Leseregeln beider Ansichten. Der geprüfte interne
Listenpfad erhält Such-/Filter-/Seitennummern beim Öffnen und bei Bewertung,
Kontakt oder Notiz. Ohne Kontext führt der Rücklink zum Bestand. Auch klassische
Listenlinks öffnen Studio und übernehmen die entsprechende Listenauswahl.
[Festlegung und Datenfluss](studio-bestand-routen.md),
[Abnahme Studio-Bestand und Routen](abnahme-studio-bestand.md).
Die älteren `/studio`-Adressen in vorstehenden Abschnittsprotokollen beschreiben
jeweils den damaligen Abnahmestand.

**Studio-Statistiken v1 ausdrücklich beauftragt und implementiert:** Eine eigene,
unten verlinkte Seite unter `/statistics` bietet Tageslinien,
Wochen-/Monats-/Jahresübersichten, Jahres-Heatmap, TLD-Donut, Bestandsverteilung
und offene Kontaktarbeit. Der Nutzer hat die Begriffe geklärt:
„Gemeldet“ zählt neue Ingest-Fälle; „Versendet“ bezeichnet Meldungen an Betreiber.
[Umfang und Datenvertrag](studio-statistiken.md) sind festgehalten. Für vergleichbare
Kurven zählen erstmals versendete Fälle; „Als versendet markieren“ hält im
Falldetail den ersten Versandzeitpunkt ausdrücklich fest. Kontaktmarker und
Bewertungen bleiben unabhängig. [Abnahme Studio-Statistiken](abnahme-studio-statistiken.md):
197 Tests mit 2.809 Assertions und zehn isolierte Browser-Szenarien erfolgreich;
schmale Kalendernavigation und Nutzung ohne JavaScript sind geprüft.

**Folgeauftrag zur Lesbarkeit und Historie:** Wegen der dominierenden Ingestzahlen
werden die Aktivitätsreihen jetzt auf derselben Zeitachse mit je eigener
beschrifteter Skala dargestellt. Kontakte sind standardmäßig sichtbar und haben
eine eigene Kennzahl. Erhaltene Kontaktmarker werden rückwirkend an ihrem
gespeicherten Datum gezählt; frühere erneute Markierungen konnten dieses Datum
überschreiben. Undatierte alte Review-Marker und rohe `fixed`-Statuswerte erscheinen
als gesonderte, verlinkte Altbestandsgruppen. Die Gruppen sind zeitraumunabhängig,
TLD-gefiltert, schließen das Archiv ein und können sich überschneiden.
Die neue Daten-/Listenabnahme ist mit 199 Tests und 2.837 Assertions erfolgreich;
elf isolierte Browser-Szenarien prüfen historische Rückblicke, genaue Falllinks
und den Extremfall mit 500 neuen Fällen neben einer Kontaktmarkierung.

**Studio-Review v1 am 2026-10-03 beauftragt und umgesetzt:** Nach dem manuellen
Nachholen der Screenshot-Aufträge steht unter `/review` eine eigene
bildzentrierte Review-Seite bereit, verlinkt in der unteren Studio-Navigation.
Der begrenzte erste Umfang bearbeitet aktive Fälle ohne gespeichertes manuelles
Urteil mit letzter Beobachtung `inconclusive`, `error` oder ohne technischen Lauf.
Standardmäßig werden tatsächlich verfügbare Bilder gezeigt. Auf ausdrücklichen
Folgewunsch speichert rechts „Vulnerable“ das Urteil `confirmed`, links
„Not vulnerable“ das Urteil `fixed` mit Schutzmarker `confirmed_fixed`.
Überspringen bleibt als separater Link neutral; Verwerfen ist eine ausdrücklich
beschriftete eigene Aktion. Gespeicherte PoC-Angaben stehen
neben dem Bild; die Aktionsleiste bleibt auch bei langen Angaben erreichbar.
Veraltete Entscheidungen überschreiben keine neueren Daten.
[Umfang, Datenfluss und Abnahme](studio-review.md).

Weitere bekannte Grenzen:

- Standardmäßig werden Dialoge drei Sekunden nach `domcontentloaded` beobachtet.
  Erkannte Browser-Schutzseiten erhalten bis zu 30 Sekunden zum Auflösen und
  danach ein neues vollständiges Dialogfenster; Erkennung und Ausgang stehen in
  den Auftragsmetadaten. Capture und eine kurze 50-ms-Nachlaufphase fangen
  Dialoge an der Grenze ab. Ein bereits während der Navigation sichtbarer erster
  echter Dialog wird sofort aufgenommen; Wiederholungen desselben Dokuments
  werden danach begrenzt, damit Alert-Schleifen den Worker nicht festhalten.
  Ein Navigationsfehler auf `about:blank` gilt als Aufnahmefehler statt als
  verfügbarer leerer Beleg; begrenzter Teardown und ein unabhängiger
  Prozess-Watchdog schützen die Folgeaufträge.
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
- Später bearbeitbare Meldungen und gegebenenfalls Mailentwürfe über Codex CLI
  sowie Gruppierung nach Domain, Betreiber, Entwickler oder begründeter
  Ähnlichkeit. Dieser Ausbau ist auf Wunsch des Nutzers derzeit zurückgestellt.
- Bestand mit Dashboard, Suche und verständlichen Statistiken.

Studio-Eingang und Studio-Falldetail übernehmen bereits nutzbare Anwendungsfälle.
Weitere Funktionen und Arbeitsbereiche werden eigenständig zugeschnitten.
Studio-Bestand, Suche, Routenwechsel und der erste Review-Umfang sind umgesetzt.
Der Review-Ablauf
verwendet die vorhandenen Bewertungen und Belege; dauerhafte Wiedervorlage,
Erledigen einzelner Hinweise und Bildvergleich bleiben spätere Erweiterungen.

**Aktuelle Priorität nach dem Dashboard:** Der Nutzer hat
[Meldungen v1](ui-workflow.md#vorschlag-nach-dem-dashboard-meldungen-v1)
zurückgestellt: Der manuelle E-Mail-Export funktioniert gut, und wichtige
Grundlagen sollen vor einem Kontaktmanagement fertig werden. Er hat nach dem
manuellen Nachholen der Bilder die inzwischen umgesetzte
[Review-Ansicht](studio-review.md) beauftragt:
Screenshot und gespeicherte PoC-Angaben zusammen, schnelles Bewerten und Weitergehen.
Die frühere Empfehlung „N01 vor Review“ ist dadurch als Priorisierung überholt;
N01 wurde als separater Betriebsnachweis weitergeführt und inzwischen abgeschlossen. Die getrennten
Aktionen Vulnerable, Not vulnerable, neutrales Überspringen und bewusstes Verwerfen vermeiden,
dass eine unzureichende Aufnahme allein den Fall archiviert. Neue Bilder aus dem
Backfill ermöglichen nun auch die Sichtung technisch uneindeutiger Fälle.

**Sparring nach der ersten Nutzung:** Der Nutzer hat die Review-Bewertung
ausprobiert; sie scheint zu funktionieren. Als nächste Grundlagen werden
Schutz der übrigen älteren Schreibaktionen (N04), der frische isolierte
Betriebsnachweis (N01) und später Review für neue Hinweise zu bereits bewerteten
Fällen empfohlen. Die fehlenden Formularprüfungen wurden im aktuellen Code
festgestellt; Backup/Restore und Artefakt-Audit existieren bereits. Die Reihenfolge
war zunächst ein Vorschlag. Danach hat der Nutzer den Commit und alle drei
Punkte ausdrücklich beauftragt; Review v1 ist als `7a37dac` committed und ein
aktuelles Backup wurde restore-validiert. Nur neue Widersprüche, Unklarheiten
und Fehler sollen erneut im Review erscheinen. [Befunde und Zuschnitt](ui-workflow.md#nach-review-v1-verbliebene-grundlagen-vor-kontakten),
[Hinweis-Regeln](studio-review.md#neue-hinweise-nach-einem-urteil).

**Korrektur während der Umsetzung:** Der Nutzer hat die zusätzliche
CSRF-Absicherung der vier älteren Aktionen ausdrücklich zurückgestellt; die
Ergänzung wurde wieder entfernt.

**Abschluss der verbleibenden Grundlagen:** Hinweis-Review ist umgesetzt und
live migriert, mit 220 erfolgreichen Tests / 3.205 Assertions sowie 37 isolierten
Review-Browserprüfungen. Der frische N01-Aufbau aus `5f28d2e` besteht mit
73 Harnessprüfungen und 9 realen Bildanzeigeprüfungen; vier lokale Aufnahmen und
Persistenz nach Neustart sind nachgewiesen. Prä- und Post-Migrationsbackups sind
restore-validiert. [Review-Abnahme](studio-review.md#neue-hinweise-nach-einem-urteil),
[N01-Abnahme](abnahme-betriebsabschluss.md), [Sicherung](backup.md).

**Releasekandidat 2.0.0 umgesetzt:** Betreiber-Priorität und der frühere
HTML-Prioritätsexport sind entfernt. Das Studio-Falldetail bietet getrennte
Aktionen zum Einreihen eines Screenshots, zum technischen Recheck und zum
endgültigen Löschen mit expliziter Bestätigung. `/export` bietet eine neutrale
URL-/Typ-Liste, einen konfigurierbaren JSON-Fallstand und ein ZIP-Meldungspaket
mit lokalisiertem Markdown-Bericht, Manifest und bewusst gewählten Bildern.
Request-/PoC-, Bewertungs-/Beobachtungs-, Kontakt-/Versanddaten und private
Notizen lassen sich getrennt einschließen. Fallfremde, veränderte, ungültige,
fehlende oder zu große Dateien werden nicht still beigefügt.

Die Settings-/Info-Seite bearbeitet Standardkennzeichen und Browser-Zeitlimit
und zeigt Version, Autor **Tom Graßmann IT+Media**, `grassmann-it.de`, das
OpenBugBounty-Profil, Repository und Lizenz. `APP_LOCALE=de|en` wählt die Sprache
der gesamten Studio-Oberfläche; Deutsch ist Standard und Fallback. API- und
Sitzungsrückmeldungen verwenden stabile Schlüssel plus Parameter, damit ein
Sprachwechsel erhaltene Entwürfe und Sitzungsverläufe neu darstellen kann.

Version ist `2.0.0`, Lizenz `GPL-3.0-or-later`. Die englische Haupt-README
beschreibt das lokale Produkt und verwendet vier synthetische Studio-Bilder.
`ddev readme-screenshots` erzeugt sie aus einer eigenen temporären Datenbank und
einem eigenen Artefaktbaum, verweigert den normalen Speicher und räumt auch bei
Fehlern auf. Die Classic-Templates, -Dienste, der alternative Intake-Renderer,
alte Oberflächenmarker und zwei frühere Bilder sind entfernt. Nur die oben
beschriebenen GET-Weiterleitungen, alte POST-Eingabeverträglichkeit und
historische Diagnosewerte bleiben erhalten.

Der aktuelle Arbeitsbaum bestand 262 PHP-Tests mit 10.254 Assertions, 12
Node-Worker-Tests, 10 Backup-Tests, Composer-/npm-Audits und alle sieben
isolierten Studio-Browsersuiten einschließlich NoJS, schmalen Ansichten,
DE-/EN-Sitzungsmigration, Exportdownloads und Löschung. Der abschließende N01-
Nachweis auf dem Releasekandidaten `9b0bfa2` bestand in einer separaten frischen
DDEV-Kopie mit 76 Harness- und neun Browserprüfungen: kalter Erststart ohne
Worker-Spawnfehler, vier echte lokale Bilder, Restart sowie unveränderte
Datenbankzeilen und Artefaktbytes. Die verwendete Installation und ihre Daten
wurden nicht berührt.
[Festlegungen und Umsetzungsdetails](ui-workflow.md#release-sparring-studio-vervollständigen-und-legacy-ablösen).

## Dokumente

- [Lastenheft und Arbeitsabschnitte](lastenheft.md)
- [Frischer DDEV-Aufbau und Neustart (N01)](abnahme-betriebsabschluss.md)
- [Arbeitsmodell und Bestandsanalyse](design.md)
- [Abnahme Arbeitsabschnitt 1](abnahme-abschnitt-1.md)
- [Abnahme Arbeitsabschnitt 2](abnahme-abschnitt-2.md)
- [Abnahme Arbeitsabschnitt 3a](abnahme-abschnitt-3a.md)
- [Abnahme Arbeitsabschnitt 3b](abnahme-abschnitt-3b.md)
- [Abnahme schneller Einzeleingang](abnahme-schneller-eingang.md)
- [Umsetzungsplan Studio-Ingest v1 / Half-Screen](plan-studio-ingest.md)
- [Abnahme Studio-Ingest v1](abnahme-studio-ingest.md)
- [Abnahme Studio-Falldetail v1](abnahme-studio-falldetail.md)
- [Festlegung Studio-Bestand und Routen](studio-bestand-routen.md)
- [Abnahme Studio-Bestand und Routen](abnahme-studio-bestand.md)
- [Studio-Statistiken v1 – Umfang und Datenvertrag](studio-statistiken.md)
- [Abnahme Studio-Statistiken v1](abnahme-studio-statistiken.md)
- [Studio-Review v1 – Umfang und Datenfluss](studio-review.md)
- [Backup und Wiederherstellung](backup.md)
- [Arbeitsbereiche und UI](ui-workflow.md)

Die ursprünglichen Befunde in `design.md` beschreiben den Ausgangspunkt und sind
als historische Analyse gekennzeichnet. Tatsächlicher Implementierungsstand und
offene Grenzen stehen in diesem Index und den Abnahmeprotokollen.

Die Architekturunterlagen liegen unter `architecture/`, weil das bestehende
`docs/`-Verzeichnis für den aktuellen Benutzer nicht beschreibbar war. Bestehende
Dateirechte wurden nicht verändert.
