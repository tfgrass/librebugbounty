# Arbeitsbereiche und UI – Diskussionsstand

Stand: 2026-10-03. Fortgeschriebener Diskussions- und Entscheidungsstand aus dem
Architektur-Sparring. Der schnelle Einzeleingang und der erste parallele,
halbbreitentaugliche Studio-Eingang sind umgesetzt. Studio-Falldetail v1 folgt als
ausdrücklich beauftragter, implementierter und abgenommener Abschnitt.
Andere als solche gekennzeichnete Ideen bleiben Vorschläge. Geltender Stand:
[Index](index.md), [Lastenheft](lastenheft.md).

## Bestätigte Nutzerrichtung

Der Nutzer möchte eine an DaVinci Resolve angelehnte Oberfläche mit eigenen
Arbeitsbereichen (app-interne „Pages“). Ergänzungen vom 2026-10-02:

- Eingang: Duplikate normalerweise überspringen und verständlich melden, etwa
  „URL nicht übernommen: bereits vorhanden“. Bestätigungsdialoge werden nur selten
  gebraucht. Welche Übereinstimmung das Überspringen rechtfertigt, ist noch offen.
- Review mit Notizen. Die Auswertung soll mehrere Anlässe aufnehmen, darunter
  fehlende Bilder und Veränderungen bei späteren Beobachtungen.
- Bei einem Wechsel von `vulnerable` zu `inconclusive` sollen Vorher-/Nachher-
  Screenshots die manuelle Beurteilung unterstützen, ob der Fall behoben ist.
- Meldungen als bearbeitbare Liste; Codex CLI soll mit dem vorhandenen Skill
  Mailentwürfe erstellen können.
- Zusammengehörige Fälle für Meldungen automatisch gruppieren können; zusätzlich
  ist eine KI-Aktion „Cluster finden“ gewünscht. Gemeinsame Entwickler aus
  Impressumsangaben sind ein vom Nutzer genanntes Beziehungskriterium.
- Bestand, Dashboard und Statistiken sind erwünscht.

Genaue Seitenanzahl, Bezeichnungen, Farben, Objektstruktur und Versandfunktion sind
damit noch nicht festgelegt. Bestehende Vorgaben gelten weiter: Symfony/SQLite/DDEV,
Erhalt manueller Bewertungen, Aufnahmen auch ohne Dialog und bei uneindeutigen
Ergebnissen.

**Bestätigte Ausbaureihenfolge:** Der Nutzer möchte zuerst das Vorhandene ordentlich
zum Laufen bringen und danach Funktionen schrittweise in der aktuellen Ansicht
ergänzen. Wenn zwei bis drei brauchbare Arbeitsbereiche entstanden sind, soll die
Resolve-artige Oberfläche diese übernehmen. Der bisherige Vorschlag, zunächst
einen klickbaren Resolve-Prototyp zu bauen, ist damit zurückgestellt. Grund:
Jeder Zwischenschritt soll bereits praktisch nutzbare Funktion liefern.

**Begrenzte Revision vom 2026-10-03:** Nach Umsetzung des schnellen Eingangs hat
der Nutzer die klassische Gesamtseite als zu überladen bewertet und den
Resolve-artigen Eingang als nächsten vorzubereitenden Abschnitt gewählt. Damit
wird kein vollständiger UI-Prototyp vorgezogen. Der bereits funktionierende Intake
wird als erster echter paralleler Studio-Arbeitsbereich angeordnet und für ein
halbbreites Fenster optimiert; Review, Meldungen und ein Studio-Bestand bleiben
eigenständige spätere Vorhaben.

**Folgeauftrag vom 2026-10-03:** Nach dem Studio-Eingang benennt der Nutzer den
Gestaltungsbruch zu den noch klassischen Einzelseiten. Der vorgeschlagene nächste
Abschnitt Studio-Falldetail v1 wird ausdrücklich zur Umsetzung beauftragt: große
Belegfläche, kompakter Inspector für die vorhandene Fallbearbeitung und parallele
klassische Detailseite. Dies erweitert den tatsächlich nutzbaren Studio-Umfang,
ohne die noch offenen Review-Regeln oder einen Studio-Bestand vorwegzunehmen.

## Beobachteter Ausgangspunkt

- FindingService sucht vorhandene Fälle nach Domain und vollständiger URL; Finding
  hat dafür einen Unique Constraint. Der Web-Eingang erkennt inzwischen eine
  exakt vorhandene URL vor der Folgearbeit, meldet „nicht übernommen“ und leitet
  zum vorhandenen Fall. Hat ein historischer Fall noch überhaupt keinen
  Screenshot-Auftrag, ergänzt diese erneute Eingabe genau einen; vorhandene
  Nutzerdaten und terminale Auftragshistorie bleiben unverändert. Ähnliche URLs
  werden noch nicht erkannt.
- Domain und Finding existieren als Entitäten; eine eigenständige Meldung/Mail gibt
  es nicht. Kontakt-/Meldezeitpunkte hängen derzeit am Finding.
- Die Betreiber-Prioritätsansicht gruppiert nach Hostname. Eine sichere Zuordnung
  mehrerer Hosts zu demselben Betreiber ist damit nicht gegeben.
- Die Belegablage/Detailansicht ist in Abschnitt 1 abgesichert. Abschnitt 2 stellt
  echte Aufnahmen mit und ohne Dialog über persistente serielle Aufträge bereit.
  Die Detailansicht zeigt Queue-Zustände, Fehler und tatsächliche Aufnahmezeiten.
  Ein gezielter Review-Arbeitsvorrat und Bildvergleich fehlen weiterhin.

## Vorschlag: feste Arbeitsbereiche mit gemeinsamem Kontext

Dieser Abschnitt beschreibt das spätere Zielbild. Die dafür nötigen Abläufe können
zunächst als Erweiterungen vorhandener Formulare, Listen und Detailansichten entstehen.

Resolve liefert das Vorbild für dauerhaft erreichbare Bereiche, große Arbeitsfläche
und einen Inspector mit kontextbezogenen Aktionen. Die Navigation könnte unten
liegen. Bereiche dürfen jederzeit gewechselt werden; sie sind kein Pflicht-Wizard.
Ausgewählter Fall, Filter und Position in der Arbeitsliste bleiben beim Wechsel
erhalten, soweit sie im Zielbereich sinnvoll sind.

| Bereich | Arbeitsfrage und Inhalt |
| --- | --- |
| Eingang / Ingest | URL aufnehmen, Ergebnis unmittelbar sehen, vorhandene Fälle und Meldungen einordnen. Formular plus Verlauf der gerade bearbeiteten Eingaben. |
| Sichten / Review | Arbeitsliste links, große Einzelaufnahme oder Bildvergleich mittig, Kontext, Notizen und Bewertung rechts. |
| Meldungen | Bearbeitbare Liste von Meldungsvorhaben und Gruppen; ausgewählte Fälle, Empfängerrecherche und Mailentwurf im Detailbereich. |
| Bestand | Dashboard als Einstieg, durchsuchbare Fallliste und Historie; Kennzahlen führen zu den zugehörigen Fällen. |

Empfehlung nach der Rückmeldung: Bestand als eigenen Bereich vorsehen und das
Dashboard darin unterbringen. Eine dunkle, kompakte Darstellung ist ein möglicher
Stil, aber bisher keine bestätigte Farbvorgabe. Globale Suche bleibt eine sinnvolle
Ergänzung. Navigation und Kontext sollten auch bei kleineren Fenstern benutzbar bleiben.

## Umgesetzte exakte Duplikate und weiterer Vorschlag

Automatisches Überspringen braucht stärkere Übereinstimmung als ein
Gruppierungshinweis. Der konservative erste Umfang ist für die exakte URL
umgesetzt; die weiteren Beziehungen bleiben Vorschläge:

- **Dieselbe vollständige URL (umgesetzt):** überspringen, begründen und zum
  vorhandenen Fall verlinken. Kein Bestätigungsdialog und keine scheinbare
  Neuanlage. Ein historisch vollständig fehlender Screenshot-Auftrag wird ergänzt;
  vorhandene Nutzerdaten und Auftragshistorie bleiben bestehen.
- **Ähnliche URL / möglicherweise derselbe Sachverhalt:** als Kandidaten anzeigen,
  ohne allein deswegen eine Eingabe automatisch zu verwerfen.
- **Gleiche Domain, gleicher Betreiber oder Entwickler:** Kontext und passende
  bestehende Meldungen anzeigen. Diese Beziehungen sind keine Duplikatnachweise.

Ein selten genutzter abweichender Weg gehört in eine nachrangige Aktion. Bei einer
exakt identischen URL bietet sich das Weiterarbeiten am bestehenden Fall an.
Eine zusätzliche Beobachtung und ein neuer eigenständiger Fall sind fachlich
verschieden; ein „trotzdem“-Knopf darf diese Unterscheidung nicht verdecken.
Der bestehende Unique Constraint lässt identische URL/Domain-Datensätze nicht zu.

**Offen:** Soll künftig auch dieselbe Seite bei abweichenden Parametern automatisch
übersprungen werden? Bis zu einer Entscheidung bleibt es beim exakten Vergleich.
Konkrete weitere Vergleichsregeln müssen Original-URLs erhalten;
Parameter, Pfade und Fragmente nicht pauschal entfernen. Zusätzliche URLs an einem
Fall und eine Übernahme trotz vermutetem Duplikat sind noch auszugestalten.

## Vorschlag für die Sichtung

Direkt sichtbar: Domain/URL, Bildzeitpunkt, technische Beobachtung, bisherige
manuelle Bewertung und Notiz. Weitere Belege sind auswählbar; eine ältere Aufnahme
ist erkennbar älter und darf nicht als aktuelles Bild erscheinen.

Empfehlung: **ein Eintrag pro Fall, mehrere sichtbare Sichtungsgründe**. Zum Beispiel:

- Neu / noch nicht gesichtet.
- Technisches Ergebnis uneindeutig.
- Neue Beobachtung nach der letzten manuellen Sichtung.
- Technisches Ergebnis geändert, insbesondere `vulnerable → inconclusive`.
- Aktueller Screenshot fehlt oder Aufnahme fehlgeschlagen.

Diese Gründe beschreiben Arbeitsbedarf; sie ersetzen keine manuelle Fallbewertung.
Fehlende Aufnahme und uneindeutiges technisches Ergebnis sind voneinander unabhängig.
Ergebnisänderungen können einen Fall wieder in die Arbeitsliste bringen.

Für einen Vergleich standardmäßig die **zuletzt ausdrücklich beurteilte Beobachtung**
der neuen gegenüberstellen. Mehrere dazwischenliegende unbeurteilte Ergebnisse
dürfen diese Referenz nicht verdrängen. Beide Seiten zeigen Zeitpunkt, Ergebnis
und zugehörige Aufnahme; weitere Beobachtungen sind auswählbar. Fehlt eine Seite,
bleibt dies sichtbar. Bei Altdaten ohne verknüpfte Bewertung wird eine gewählte
Referenz als solche bezeichnet, nicht nachträglich als früher beurteilt ausgegeben.

`inconclusive` und reine Bildunterschiede belegen für sich keine Behebung.
Die bisherige manuelle Bewertung bleibt sichtbar, bis der Nutzer sie ändert.
Ein pixelbasierter Vergleich ist wegen Layout-/Inhaltsänderungen vorerst keine
Voraussetzung; der direkte Bildvergleich liefert bereits einen nutzbaren Ablauf.

Bewerten und einen Hinweis abarbeiten brauchen unterschiedliche Aktionen:
„Bewertung speichern“, „Hinweis geprüft, Bewertung behalten“ und „Zurückstellen“.
Bloßes Öffnen erledigt nichts. Das Abarbeiten bezieht sich auf die gesehenen
Beobachtungen; später eintreffende Ergebnisse bleiben als neuer Anlass erkennbar.
Notizen gehören zum sichtbaren Kontext. Tastaturbedienung und „speichern und weiter“
sind sinnvolle Detailoptionen.

## Vorschlag für Meldungen und Gruppierung

Die gewünschte Gruppierung klärt die frühere Frage nach Bündelung: Mehrere Fälle
sollen gemeinsam für eine Meldung bearbeitbar sein. Offen bleiben die genauen
Zuordnungs- und Empfängerregeln. Empfehlung: Fälle einzeln erhalten und bewerten;
eine Meldung enthält eine bewusst zusammengestellte Auswahl mit ihren Belegen.

Die Meldungsliste zeigt etwa Empfänger/Organisation, enthaltene Fälle, Arbeitsstand
und letzte Bearbeitung. Nach Auswahl werden Fallauswahl, Kontakte, Quellen und
Mailentwurf bearbeitbar. Vorläufige Arbeitsstände: „in Vorbereitung“, „Entwurf
wird erstellt“, „Entwurf vorhanden“, „überarbeiten“, „als versendet dokumentiert“.
Ein Entwurf erzeugt keinen Kontaktzeitpunkt. Historische Kontaktzeitpunkte bleiben
erhalten, auch wenn keine frühere E-Mail rekonstruierbar ist.

Gruppierung unterscheidet mindestens diese Beziehungsarten:

| Beziehung | Bedeutung für die Meldung |
| --- | --- |
| Gleiche Domain | Bestehenden Fall- und Kontaktkontext gemeinsam anzeigen. |
| Gleicher Betreiber | Kandidat für eine gemeinsame Meldung an diese Organisation. |
| Gleicher Entwickler / Dienstleister | Zusammenhang zur Recherche; eigene mögliche Ansprache des Entwicklers, ohne die Betreiber gleichzusetzen. |
| Ähnlichkeit / KI-Vermutung | Vorschlag mit Begründung; noch keine bestätigte Zuordnung. |

Bekannte bestätigte Beziehungen können automatisch gruppierte Ansichten liefern.
„Cluster finden“ ergänzt Vorschläge mit Beziehungstyp, nachvollziehbarer Begründung
und Quellen, z. B. einer konkreten Impressumsangabe. Website-Anbieter und Betreiber
nicht allein anhand eines gemeinsamen Namens gleichsetzen. Ein Entwicklerhinweis
belegt auch keine gemeinsame Fehlerursache.

Der Nutzer kann Vorschläge übernehmen, trennen oder einzelne Fälle ausschließen.
Ein Fall darf mehreren Beziehungen angehören. Gruppierung verschmilzt keine
Fälle und legt nicht automatisch den Empfänger oder Kontaktstatus fest. Insbesondere
ist eine Gruppe verschiedener Kunden eines Entwicklers noch keine gemeinsame
Empfängerliste. Der Umfang einer konkreten Meldung wird separat zusammengestellt.

## Vorschlag zur Codex-CLI-Anbindung

Der vorhandene [report-gen-Skill](/home/tomka/.codex/skills/report-gen/SKILL.md)
passt zu Kontaktrecherche und Mailentwürfen. Er versendet keine Nachrichten und
trennt Quellen von Empfängertext; interne Fall-/Toolkennungen gehören nicht in
die ausgehende Mail. Seine Vorlagen und Referenzen bleiben Grundlage der Erstellung.

Vorschlag für den Ablauf: ausgewählte Fälle und vorhandene Belege zusammenstellen,
„Entwurf erstellen“ auslösen, Fortschritt anzeigen und das Ergebnis als bearbeitbaren
Entwurf samt Kontakten/Quellen übernehmen. „Cluster finden“ bleibt eine separate
Aktion, damit eine Gruppierung nicht schon eine Meldung erzeugt.

Die offizielle [Dokumentation zu codex exec](https://developers.openai.com/codex/noninteractive/)
beschreibt nichtinteraktive Aufrufe, JSONL-Fortschritt über `--json` und strukturierte
Endergebnisse über `--output-schema`. Das lokale Programm liegt unter
`/home/tomka/.local/bin/codex`. Eine vollständige Anbindung oder Authentifizierung
aus DDEV wurde bisher nicht geprüft.

Empfehlung: ein Hintergrundauftrag mit einem festgehaltenen Eingabestand. Die App
übernimmt das validierte Ergebnis; der CLI-Prozess bearbeitet nicht selbst die
Anwendungsdatenbank. Fehler sind im Meldungsvorhaben sichtbar. Eine erneute Erstellung
liefert eine neue Version, damit manuell bearbeiteter Text erhalten bleibt. Recherche
nutzt öffentliche Quellen und vorhandene Falldaten; sie erzeugt keine neuen
technischen Tests oder erfundenen Befunde.

**Vor produktiver Anbindung klären:** CLI läuft derzeit auf dem Host, die Anwendung
in DDEV. Host-Runner oder eigener Dienst sowie Skill-Verfügbarkeit und Berechtigungen
sind noch zu wählen. Die [offiziellen Skill-Suchpfade](https://developers.openai.com/codex/skills/)
umfassen `.agents/skills`; der hier verwendete `.codex/skills`-Katalog darf nicht
ungeprüft als in einem separaten CLI-Aufruf verfügbar vorausgesetzt werden.

## Vorschlag für Bestand und Dashboard

Das Dashboard zeigt Arbeitsbedarf und Bestand mit direkten Sprüngen in passende
Listen: offene Sichtungen, Fälle mit fehlendem Bild, Meldungsentwürfe und erfasste
Fälle. Verlauf und Suchfilter ermöglichen das Wiederfinden unabhängig vom aktuellen
Arbeitsbedarf. Fachliche Bewertungen, technische Ergebnisse und Meldefortschritt
werden getrennt gezählt und verständlich benannt.

Ein Fall mit drei Sichtungsgründen zählt in „zu sichten“ einmal; Filter nach Gründen
können sich überschneiden. Fälle, Domains, Betreiber und Meldungen sind verschiedene
Zählgrößen. Gruppenvorschläge dürfen keine scheinbar bestätigten Betreiberzahlen
erzeugen. Welche Zeitverläufe und Kennzahlen dauerhaft hilfreich sind, ist im
UI-Entwurf zu bewerten.

## Bestätigter Ausbauweg und vorgeschlagene Zwischenpakete

Die bestehende Anwendung wird zum Träger der neuen Funktionen. Die bestätigte
Reihenfolge lautet: stabilisieren, funktional erweitern, später in Resolve-artige
Arbeitsbereiche überführen. Die folgende konkrete Aufteilung ist eine Empfehlung;
sie beauftragt noch keinen weiteren Abschnitt.

| Etappe | Bereits in der aktuellen Anwendung nutzbares Ergebnis |
| --- | --- |
| Stabilisierung, Abschnitt 2 | **Umgesetzt:** persistente serielle Aufträge, tatsächliche Screenshots mit und ohne Dialog, sichtbare Aufnahmefehler und erhaltene frühere Belege; funktionale Abnahme in DDEV. |
| Stabilisierung, Abschnitt 3 | Verlässliche Trennung von Beobachtung und manueller Bewertung, konsistente Filter und verständliche Darstellung. |
| Betriebsabschluss, Abschnitt 4 | Übernahme und Wiederherstellung auf Kopien geprüft, DDEV-Aufbau und Betriebsdokumentation nachvollziehbar. |
| Eingang verbessern | **Für exakte URL umgesetzt:** Formular meldet Übernahme oder Duplikat verständlich und verlinkt vorhandenen Kontext. Ähnlichkeits- und Domainhinweise bleiben offen. |
| Review erweitern | Bestehende Liste/Detailansicht erhält Sichtungsgründe, Notizen, Bildvergleich und gezieltes Abarbeiten von Hinweisen. |
| Meldungen aufbauen | Einfache bearbeitbare Meldungsliste mit ausgewählten Fällen und Entwürfen; Codex-Anbindung und Gruppierung als gesondert zu konkretisierende Erweiterungen. |
| Resolve-Oberfläche | Bereits brauchbare Abläufe in neue Navigation, Anordnung und gemeinsamen Auswahlkontext übernehmen. |

Abschnittsnummern 1–4 bleiben gültig. Sicherung und Wiederherstellungsnachweis
gelten vor jeder schreibenden Datenmigration; sie werden nicht bis zum
Betriebsabschluss oder Designwechsel aufgeschoben. Dashboard und Statistiken können
schrittweise auf den vorhandenen Listen und geklärten Zählregeln aufbauen.

Eingang, Review und Meldungen sind mögliche erste Arbeitsbereiche. Der Designwechsel
setzt nicht sämtliche Gruppierungs-, KI- und Statistikideen voraus. Ob zwei Bereiche
bereits genügen oder ein dritter hinzukommt, richtet sich nach den tatsächlich
brauchbaren Abläufen. Die genaue Auswahl und Reihenfolge der Erweiterungen bleibt
offen. Eine Grundfunktion für Meldungen hängt nicht zwingend schon von der fertigen
Codex-CLI-Anbindung ab.

## Erneutes Sparring zum Zuschnitt von Abschnitt 3

Der Nutzer hat am 2026-10-02 den Umfang von Paket 3 infrage gestellt und möchte
Arbeitsweise und Anforderungen erneut besprechen. Eine neue Paketaufteilung ist
anschließend konkretisiert worden; der Nutzer hat die Umsetzung von 3a ausdrücklich
beauftragt. [Umsetzung und Abnahme](abnahme-abschnitt-3a.md). Weitere Pakete bleiben
Vorschläge; die vereinbarten Aktionen stehen unten.

**Beobachtung:** Abschnitt 3 im Lastenheft bündelt die F06-Regel, gemeinsame
Begriffe/Filter, die Kennzeichnung mehrdeutiger Altdaten und Controller-/Template-
Trennung. Die vorhandenen Felder `status` und `reviewState` unterscheiden manuelle
Bewertung, technische Einordnung und Sichtungsbedarf nicht eindeutig. Der fachliche
Vertrag für den begrenzten Abschnitt 3a ist inzwischen wie folgt geklärt.

**Bestätigte Anforderungen vom 2026-10-02:**

- „Bestätigen“ wird bei `inconclusive` benötigt. Das Ergebnis verlangt manuelle
  Beurteilung; es ist keine Aussage, dass der Fall behoben ist. Technische Treffer
  und eine ausdrückliche manuelle Bestätigung bleiben unterscheidbar.
- „Behoben“ markiert einen noch nicht so geführten Fall ausdrücklich als behoben.
- „Verwerfen“ bedeutet im normalen Gebrauch vollständig ignorieren. Fall und
  Belege bleiben erhalten; neue technische Beobachtungen reaktivieren ihn nicht
  automatisch. Der Nutzer nennt Duplikate als möglichen Grund und möchte sie
  entsprechend kennzeichnen können.
- „Kontaktiert“ enthält zunächst nur einen unabhängig gespeicherten Zeitpunkt.
  Empfänger, Mailzuordnung und mehrere Kontaktvorgänge sind spätere Erweiterungen.
- Die unten beschriebene einfache Historie neuer Bewertungsänderungen ist akzeptiert.

**Nutzerbeobachtung:** `inconclusive` tritt nach seiner Erfahrung häufig bei sehr
vielen `alert()`-Aufrufen auf, wenn die Seite nicht fertig lädt. Diese Ursache
wurde hier nicht technisch geprüft; sie begründet den manuellen Klärungsbedarf.

**Empfehlung für Duplikate:** „Verworfen – Duplikat“ als ausdrücklich gesetzte
Kennzeichnung beziehungsweise Verwerfungsgrund führen. Der Fall bleibt als
verworfen erkennbar; seine Zuordnung als Duplikat wird nicht aus bloßer Domain-
oder Betreiberübereinstimmung abgeleitet. Explizites Wiederfinden und Neubewerten
verworfener Fälle bleibt ein möglicher gesonderter Bedienweg.

**Vorläufiger Paketzuschnitt auf Nachfrage des Nutzers:** Abschnitt 3 in zwei
einzeln bewertbare Ergebnisse teilen. 3a wurde anschließend beauftragt und umgesetzt;
3b wurde danach ebenfalls ausdrücklich beauftragt. Weitere Erweiterungen bleiben
Vorschläge.

| Paket | Nutzbares Ergebnis und Grenze |
| --- | --- |
| 3a – Einen Fall verlässlich bewerten | **Umgesetzt und abgenommen:** In der bestehenden Detailansicht bei `inconclusive` bestätigen, als behoben markieren oder verwerfen; Duplikatkennzeichnung und unabhängiger Kontaktzeitpunkt. Verworfene Fälle werden im normalen Arbeiten ignoriert. Spätere technische Beobachtungen erhalten das manuelle Urteil. Unklare Altwerte bleiben erkennbar. |
| 3b – Bestand konsistent lesen und filtern | **Beauftragt und umgesetzt:** Übersicht, Filter und Zähler unterscheiden aufgezeichnetes manuelles Urteil, letzte gespeicherte technische Beobachtung und Kontaktstand; Web und CLI verwenden dieselben Leseregeln. Altwerte und Archiv bleiben ausdrücklich auffindbar. Neue Sichtungsgründe und deren Zähler entstehen erst im Review-Paket. [Abnahme](abnahme-abschnitt-3b.md). |
| Abschnitt 4 – Betriebsnachweise abschließen | Frischen isolierten DDEV-Aufbau und die verbleibende Betriebs-/Übernahmeabnahme nachweisen. Sicherung, Restore-Prüfung und Übernahmeprüfung vor schreibenden Änderungen gelten bereits für jedes betroffene Paket. |
| Späteres Review-Paket | Sichtungsarbeitsliste, bearbeitbare Notizen, Bildvergleich und gezieltes Erledigen beziehungsweise Zurückstellen von Hinweisen. Die genauen Arbeitsregeln werden vor diesem Paket geklärt. |
| Spätere Meldungspakete | Zuerst eine bearbeitbare Meldung aus ausgewählten Fällen; Codex-Entwürfe und Gruppierung bleiben gesonderte Erweiterungen. |

Controller-/Template-Trennung folgt dem jeweils bearbeiteten Ablauf. Der Wechsel
zur Resolve-artigen Oberfläche bleibt an zwei bis drei brauchbare Arbeitsbereiche
gebunden; er ist kein zusätzlicher Bestandteil von 3a oder 3b.

**Akzeptierte minimale Historie für 3a:** Neue Urteilsänderungen mit Zeitpunkt
und Herkunft nachvollziehen. Soweit eine konkrete Beobachtung oder ein Beleg
beurteilt wurde, den Bezug festhalten, damit der spätere Bildvergleich eine
verlässliche Referenz haben kann. Ohne solche Grundlage bleibt der Bezug unbekannt.
Historische Werte erhalten, ohne nachträglich Datum oder manuelle Herkunft zu erfinden.

**Planreife für 3a:** Die vier tragenden Fragen zu Bestätigungsbedarf, Verwerfen,
Kontaktzeitpunkt und Historientiefe sind beantwortet. Für einen auf diese Ergebnisse
begrenzten Übergabeplan ist derzeit keine weitere grundlegende Produktantwort nötig.
Der Nutzer hat 3a danach ausdrücklich beauftragt; der begrenzte Umfang ist
umgesetzt und in DDEV abgenommen. Die nachfolgenden Review-Regeln bleiben offen.

**Erst vor dem Review-Paket klären:** Welche neuen Beobachtungen erzeugen wieder
Sichtungsbedarf bei aktiven oder behobenen Fällen? Verworfene Fälle bleiben im
normalen Arbeiten ignoriert. Wie werden Hinweise
erledigt, während die Bewertung erhalten bleibt, und was bedeutet Zurückstellen?
Die genaue Auswahl der Vergleichsreferenz ist ebenfalls noch ein Vorschlag.

## Empfehlung für wiederverwendbare Umsetzung

- Anwendungsregeln und Datenzugriffe beim jeweiligen Funktionsumbau von HTML und
  Navigation trennen. Die bestehende Oberfläche verwendet diese Regeln bereits.
- Controller-/Template-Trennung dort durchführen, wo eine Funktion bearbeitet wird;
  nutzbare Twig-Bausteine erhalten. Keine vollständige Komponentenbibliothek oder
  separate Frontend-API als Voraussetzung für den Ausbau einführen.
- Jeden Abschnitt anhand seines sichtbaren Verhaltens in DDEV abnehmen. Synthetische
  Fälle dienen als Prüfdaten; die gelieferte Funktion arbeitet in der echten Anwendung.
- Beim späteren Designwechsel Daten, fachliche Regeln und bewährte Abläufe übernehmen.
  Neue Navigation, Auswahlkontext und Bedienung benötigen trotzdem eigene Prüfung;
  der Umbau ist nicht automatisch auf CSS beschränkt.

## Schneller Eingang und parallele Resolve-Ansicht – Sparring nach 3b

**Nutzerbedarf:** Häufig fünf URLs nacheinander erfassen, ohne wegen wartender
Rückmeldungen mehrere Tabs zu benötigen. Auf konkrete Nachfrage bestätigt:
einzelne Eingaben, Formular anschließend sofort wieder nutzbar. Ein gemeinsamer
Mehrfachimport ist damit kein Bestandteil des zunächst gewünschten Ergebnisses.
Die bestehende Gestaltung erhalten und daneben eine schönere Resolve-artige
Ansicht anbieten ist die neu eingebrachte Ausbaumöglichkeit. Auf weitere Nachfrage
bestätigt: Die API soll zunächst die beiden Oberflächen unterstützen; weitere
eigenständige Clients sind kein aktuelles Ziel. Der Nutzer hat die vorgeschlagene
Richtung aus schnellem Eingang, späteren Rückmeldungen und paralleler Oberfläche
anschließend befürwortet.

**Historischer Stand bei `a8ae19d`:** Der Screenshot-Auftrag wurde bereits mit dem
neuen Finding gespeichert. Der Eingangscontroller wartet danach auf den synchronen
technischen Vorgang und antwortet erst mit dessen Ergebnis. Die Bildaufnahme selbst
ist bereits entkoppelt. Eine neue Darstellung oder eine JSON-Antwort allein ändern
diese Antwortgrenze nicht. Ein laufender technischer Vorgang ist im derzeitigen
Lesemodell zudem nicht als dauerhafter Zwischenstand abrufbar; sein `pending`-Objekt
wird erst bei Ergebnisübernahme gespeichert.

**Ausdrücklich revidierte Entscheidung vom 2026-10-03:** Jeder neue UI-Eingang
wird nicht mehr unmittelbar und synchron headless geprüft. Der technische Pfad
konnte bis zum 120-Sekunden-Timeout warten; bei Seiten mit sehr vielen
`alert()`-Aufrufen konnte der Browservorgang praktisch nicht zu einem rechtzeitigen
Abschluss kommen. Ein realer Eingabeversuch des Nutzers zeigte dieses blockierende
Verhalten erneut. Bestätigte dauerhafte Speicherung hat deshalb im Eingang Vorrang:
Finding und erster ScreenshotJob werden weiterhin atomar committed, anschließend
kehrt der Request zurück. Der Intake startet weder synchron noch automatisch einen
Retest. Der persistente Screenshot-Worker arbeitet unabhängig weiter; manuelle und
andere ausdrücklich ausgelöste Retests bleiben eigene Vorgänge.

Diese Revision ersetzt die Festlegung aus Abschnitt 2, wonach jeder neue
UI-Eingang sofort headless geprüft werde. Sie führt bewusst keine zweite
Hintergrund-Queue für technische Prüfungen ein. Ein Screenshotzustand oder eine
bereits anderweitig gespeicherte Beobachtung darf später lesend angezeigt werden;
das Status-Polling selbst erzeugt keine Arbeit und täuscht keine neue technische
Beobachtung vor.

**Beauftragter Umsetzungsvertrag:** Der klassische Formular-POST speichert und
leitet unmittelbar weiter. Für den schnellen Eingang nimmt `POST /api/findings`
dieselben Formulardaten und denselben CSRF-Zweck entgegen. Eine neue Speicherung
antwortet mit HTTP 201, ein exaktes Duplikat mit HTTP 200; beide Antworten enthalten
Finding-ID, Detail-Link und den aktuellen persistierten Zustand. Ungültige Eingaben
und ein ungültiges CSRF-Token bleiben unterscheidbar. `GET /api/findings/status`
liest den Zustand von höchstens 50 gültigen Finding-IDs und startet weder Retest
noch Screenshot. Diese Schnittstellen sind schmale gemeinsame Grenzen für die
klassische und die spätere Studio-Oberfläche, keine allgemeine öffentliche API.

Nach bestätigter Speicherung werden unveränderte URL und Notiz geleert und das
URL-Feld für die nächste Eingabe fokussiert. Wurde der nächste Entwurf bereits
bearbeitet, bleiben seine Werte und das aktive Feld erhalten. Eine sichtbare
Verlaufszeile je Eingabe unterscheidet Speichern, gespeichert, exaktes Duplikat,
Eingabefehler, unbekannten Requestausgang und den persistierten Screenshotzustand.
Ein Transportabbruch gilt
nicht als Beleg, dass der Server nicht gespeichert hat: Die Eingabe bleibt als
„nicht bestätigt“ erhalten und wird nur nach einem bewussten Klick wieder ins
Formular übernommen. Dadurch verursacht ein Timeout keinen automatischen Doppel-POST.

**Bestätigte Bedienungsdetails:** Der Verlauf der aktuellen Tabsitzung wird mit
Entwurf in `sessionStorage` gehalten und auf höchstens 50 Einträge begrenzt. Er
bleibt nach einem Reload dieses Tabs sichtbar; ein zwischen Tabs geteilter oder
dauerhafter serverseitiger Eingangsverlauf gehört nicht zum Abschnitt. Toasts
erscheinen nur im sichtbaren, fokussierten Tab. Nach einem Reload lösen bereits
bekannte Zustände keine erneuten Toasts aus. Der Verlauf ist Bedienzustand; Fälle,
ScreenshotJobs, Bewertungen und Beobachtungen bleiben serverseitige Wahrheit.

Für parallele Oberflächen bietet sich dasselbe Symfony-Backend mit derselben
Datenbasis, denselben Anwendungsfällen und Leseregeln an. Klassische Ansicht und
eine mögliche Studio-Ansicht hätten eigene Layouts und Navigation. Der tablokale
Intake-Verlauf kann beim Wechsel derselben Tabsitzung gemeinsam bleiben;
arbeitsbereichsspezifische Auswahlzustände bleiben getrennt. Änderungen eines
Falls wären anschließend in beiden sichtbar. Eine separate
Frontend-Anwendung ist eine mögliche Ausbaustufe; Symfony-Templates mit kleinen
JavaScript-Komponenten und passenden JSON-Schnittstellen bleiben als einfachere
Alternative erhalten. "Headless" bezeichnet hier die Trennung von Backend/API und
Darstellung; der bereits vorhandene Browsermodus ist davon unabhängig.

**Festgelegter Zuschnitt:** Zuerst wird der schnelle Eingang in der vorhandenen
Ansicht praktisch nutzbar gemacht; anschließend können Eingang und Bestand in
einer parallelen Resolve-Ansicht dargestellt werden. Vorläufiges Layout: Fallliste links,
Arbeitsfläche beziehungsweise vorhandener Bildbeleg in der Mitte, ausgewählter
Fall mit Notizen/Bewertung rechts; Arbeitsbereichwechsel unten. Review und Meldungen
folgen mit ihren eigenen noch offenen Fachregeln. Der schnelle Eingang wurde nach
einer erneut beobachteten Blockade ausdrücklich zur Umsetzung beauftragt. Zu diesem
Zeitpunkt blieb die Studio-Ansicht noch ein folgendes Vorhaben; die anschließende
Vorbereitung des schmalen ersten Studio-Abschnitts ist unten fortgeschrieben.

**Geklärter API-Umfang:** Zunächst zwei Oberflächen. Eine umfassende API für weitere
Clients ist deshalb keine Voraussetzung. Die konkrete Frameworkwahl der
Studio-Ansicht folgt ihrem benötigten Auswahl-/Bearbeitungszustand. Für die
Grundrichtung reichen Fallverwaltung und lesende Rückmeldungen; eine neue
automatische Meldungs-/Versandfunktion ist damit nicht beschlossen.

**Beauftragtes Vorhaben: schneller Einzeleingang.** Die vorhandene Ansicht bleibt
der erste nutzbare Arbeitsbereich. Zielabnahme: fünf kontrollierte
lokale Beispiel-URLs nacheinander in einem Tab erfassen, ohne auf die späteren
Screenshotzustände zu warten. Jede Eingabe erhält ihren eigenen Fallbezug;
gespeicherte Fälle, exakte Duplikate, Eingabefehler und nicht bestätigte Requests
bleiben unterscheidbar. Die Eingabe darf bei fehlgeschlagener oder unbestätigter
Speicherung nicht verloren gehen. Die fokussierte Abnahme steht in
[Abnahme schneller Einzeleingang](abnahme-schneller-eingang.md).

## Nächstes Vorhaben: Studio-Ingest v1 / Half-Screen

**Neue Nutzerpriorität vom 2026-10-03:** Die klassische Gesamtseite ist für den
alltäglichen Eingang zu dicht. Als nächster Abschnitt soll eine einfache,
Resolve-artige Eingabemaske vorbereitet werden, die besonders im halbbreiten
Fenster neben einer Liste mit etwa fünf zu kopierenden URLs funktioniert. Die
klassische Oberfläche bleibt parallel erhalten.

Empfohlen ist deshalb kein früher Nachbau des späteren Drei-Paneel-Reviews, sondern
eine eigene Route `/studio` mit dunkler Studio-Shell, dominantem URL-Feld und
kompaktem Sitzungsverlauf. Kennzeichen und Notiz liegen standardmäßig unter
„Details hinzufügen“. Bei 640 bis 960 CSS-Pixeln bleibt die Arbeitsfläche
einspaltig; URL-Eingabe und Verlauf beanspruchen nicht gemeinsam dieselbe schmale
Zeile. Die untere Navigation enthält zunächst nur funktionierende Ziele.

Die vorhandenen Intake- und Statusendpunkte reichen aus. Eine separate SPA oder
ein neuer Build-Stack wird für diesen ersten Arbeitsbereich nicht empfohlen.
Stattdessen erhält Symfony ein eigenes Studio-Template und isolierte Styles; die
bewährte Session-, Transport-, Retry- und Pollinglogik wird aus ihrer Bindung an
die klassische Kartendarstellung gelöst und von beiden Oberflächen verwendet.

Der vollständige Zuschnitt, die Half-Screen-Skizze, technische Reihenfolge und
prüfbare Abnahme stehen im [Umsetzungsplan Studio-Ingest v1](plan-studio-ingest.md).
Nach dem anschließenden tatsächlichen Aufruf von `/studio` mit 404 wurde der
vorbereitete Abschnitt implementiert: eigene Symfony-Route, dunkle Studio-Shell
und kompakte Verlaufszeilen bei unverändertem API-Vertrag. Beide Oberflächen
verwenden denselben Verlauf und vollständigen Entwurf im jeweiligen Tab;
Wiederherstellung aus dem BFCache liest neuere Änderungen vor dem nächsten
Schreiben neu ein. [Abnahme und Grenzen](abnahme-studio-ingest.md).
Bestand, Inspector, Review und Meldungen erweitern diesen ersten Arbeitsbereich
nicht stillschweigend.

## Beauftragter Folgeabschnitt: Studio-Falldetail v1

**Begründung und Festlegung:** „Fall öffnen“ führte aus dem Studio-Eingang zurück
in die dichter dargestellte klassische Einzelseite. Der Nutzer hat den konkreten
Vorschlag zur Angleichung dieser Fallbearbeitung mit „setz es um“ beauftragt. Unter
`/studio/findings/{id}` ist deshalb eine eigene Studio-Einzelseite implementiert;
die bestehende Seite `/findings/{id}` bleibt parallel und beide verlinken denselben
Fall in der anderen Oberfläche. Studio-Eingang und seine Toasts führen zur neuen
Detailseite, klassische Intake-Links behalten ihre bisherige Zieloberfläche.

**Spätere Richtungsänderung:** Der Nutzer möchte beim Öffnen eines Falls auch aus
dem klassischen Eingang und der bisherigen Übersicht die Studio-Detailseite
sehen. Diese Links wurden umgestellt. Die vorstehende Beschreibung hält den
Abnahmestand des ersten Studio-Falldetails fest und ist für die aktuelle
Navigation an dieser Stelle ersetzt. Die geplante Verlegung von Studio nach
`/` und Classic nach `/legacy` ist ein eigener nächster Routenschritt.

Die Belegfläche dominiert den breiten Arbeitsplatz; rechts liegt ein kompakter
Inspector für manuelle Bewertung, technische Beobachtung, Notiz und Kontakt.
Bis 1100 CSS-Pixel werden die Bereiche gestapelt. Beleg, Entscheidung und Verlauf
sind echte Anker für Tastatur und schmale Fenster. Die untere Navigation zeigt
den aktuellen Fallkontext und nur funktionierende Ziele; sie führt weiterhin zum
klassischen Bestand und zu den vorhandenen Einstellungen.

Mehrere Bildbelege sind auswählbar und ihr Original ist lokal erreichbar. Die
Bildauswahl setzt keine Bewertungsgrundlage voraus. Aufnahme- und Ablagezeit sind
getrennt; fehlt die Aufnahmezeit, bleibt sie unbekannt. Der letzte Screenshot-
Auftrag mit `queued`, `running`, `available` oder `failed` bleibt sichtbar, auch
wenn ein älterer Bildbeleg vorhanden ist. Fehlende Bilddateien werden erklärt,
die Evidence-Zeilen bleiben erhalten. Browser-Schutz-Erkennung und ihre Details
erscheinen als Aufnahmemetadaten und ändern keine manuelle Bewertung.

`FindingDetailService` liefert für Classic und Studio denselben lesenden
Fallkontext und die gleichen Bewertungsregeln. Die vorhandenen
`/findings/{id}/assessment`- und `/mark-contacted`-Endpunkte speichern die
Entscheidung beziehungsweise den Kontaktzeitpunkt. Optionales `surface=studio`
bestimmt die interne Rückkehr; das ist kein frei wählbares Weiterleitungsziel.
Notizen werden über `/findings/{id}/notes` mit eigenem CSRF-Zweck ausdrücklich
gespeichert. Ohne ausdrückliche Auswahl bleibt eine Bewertungsgrundlage unbekannt.
Es gibt weder automatische technische Prüfung beim Öffnen noch Autosave.

Technik und Historie sind standardmäßig eingeklappt: Bewertungshistorie mit
ausdrücklicher oder unbekannter Grundlage, Screenshot-Aufträge mit Fehlern und
Challenges, alle Evidence und die 20 jüngsten gespeicherten technischen Läufe.
Native Formulare und Details sind ohne JavaScript bedienbar; dann werden die
Bildbelege gemeinsam dargestellt. Ein laufender Screenshotstand braucht in der
Detailseite aktuell Reload. Der Studio-Eingang behält seinen lesenden Statusabruf.

Der Abschnitt ist implementiert und abgenommen. Zehn fokussierte HTTP-Tests mit
271 Assertions, die Gesamtsuite mit 172 Tests und 1.788 Assertions sowie 20
isolierte Browserprüfungen an fünf Fenstergrößen sind erfolgreich. Nachweise und
praktische Grenzen:
[Abnahme Studio-Falldetail v1](abnahme-studio-falldetail.md).

## Bestätigter und umgesetzter Ausbau: Studio-Bestand und Routen

Der Nutzer wollte die vorhandene Liste, Suche und Filter nun ebenfalls in Studio
verwenden und Studio auf `/` zur normalen Oberfläche machen. Den dokumentierten
Vorschlag mit eigenständigem schnellen Eingang auf `/` und Bestand auf `/findings`
hat er mit „genau bau das so“ bestätigt. Dieser Abschnitt ist umgesetzt.

Studio-Liste und Falldetail `/findings/{id}` bilden den zusammenhängenden
Bearbeitungsweg. Die klassische Oberfläche bleibt unter `/legacy` mit den
entsprechenden Detail-, Einstellungs- und Exportseiten. Alte Studio- und gefilterte
Root-GET-Links bleiben über Weiterleitungen mit ihren Parametern erreichbar.
Schreib-, API- und Artefaktadressen ändern sich nicht.

Suchtext, Filter und Seite stehen in der URL und werden über einen validierten
internen Listenpfad bis nach der Fallbearbeitung erhalten. Bewertungs-, Notiz-
und Kontaktformulare tragen diesen Kontext. Auch Classic-Listenlinks übernehmen
die entsprechende Studio-Auswahl. Die globale Statistik ist einklappbar;
ihre Links öffnen die gezählte Menge mit zurückgesetzten anderen Filtern.

Beide Oberflächen verwenden `FindingListService` und `FindingReadRepository`
für dieselben lesenden Filter-, Count- und Pagingregeln. Wörtliche Textsuche
berücksichtigt Domain, Titel und vollständige URL. Aktiver Bestand und Archiv
behalten ihre bestehenden fachlichen Regeln. Die responsive Zeilenliste zeigt
bei halber Breite kompakte Karten mit getrennten Zustandsdimensionen.

[Festlegung und Umsetzung](studio-bestand-routen.md),
[Nachweise und Grenzen](abnahme-studio-bestand.md).

Review benötigt weiterhin Antworten zu Sichtungsgründen, Erledigen,
Zurückstellen und Vergleichsreferenzen. Bildvergleich, Mailvorbereitung und
Meldungsgruppen sind damit noch nicht umgesetzt. Einstellungen und Export haben
vorerst die klassische Gestaltung; ihre Funktionen bleiben erreichbar.

## Vorschlag nach dem Dashboard: Meldungen v1

**Ersetzt am 2026-10-03:** Dieser Vorschlag wurde nach der folgenden Nutzerantwort
zurückgestellt. Der manuelle E-Mail-Export funktioniert für den Nutzer bereits gut;
E-Mail- und Kontaktmanagement soll erst nach den vorher wichtigen Grundlagen
erneut betrachtet werden. Die nachfolgenden Details bewahren die damalige Idee,
sind aber keine aktuelle Paketempfehlung.

**Ursprünglicher Vorschlag ohne Umsetzungsauftrag:** Nach Eingang, Falldetail,
Bestand und Statistiken fragt der Nutzer nach dem nächsten sinnvollen Schritt.
Die damalige Empfehlung war eine kleine, lokal bearbeitbare Meldungsverwaltung.
Sie ergänzt den Weg von bewusst ausgewählten Fällen zu einer dokumentierten
Betreibermeldung und schafft künftig die Grundlage für Antwort- und
Versandauswertungen.

Beobachtet ist viel erhaltene Kontaktaktivität, aber keine eigenständige
Meldungsverwaltung. Kontaktmarker allein liefern weder Empfänger, Mailtext noch
eine verlässliche Versandhistorie. Ob Mailvorbereitung tatsächlich der größte
Zeitaufwand des Nutzers ist, bleibt eine zu klärende Annahme.

Der vorgeschlagene erste Zuschnitt:

- Im Bestand Fälle auswählen und daraus eine Meldung vorbereiten. Die Fälle
  behalten ihre eigenen Bewertungen und Belege.
- Unter einem eigenen Arbeitsbereich „Meldungen“ gespeicherte Entwürfe und
  dokumentierte Versände wiederfinden.
- Empfänger, Betreff, Text und enthaltene Fälle bearbeiten und den Entwurf später
  mit erhaltenen Änderungen weiterführen.
- Den tatsächlichen Versand mit ausdrücklich angegebenem Datum dokumentieren.
  Das genaue Verhältnis zu vorhandenen Fall-Versandmarkern und spätere
  Datumskorrekturen müssen vor der Umsetzung geklärt werden.

Ein prüfbares Ergebnis wäre: Drei ausgewählte Fälle gemeinsam vorbereiten,
Empfänger und Text bearbeiten, die Meldung später unverändert wieder öffnen und
ihren Versand dokumentieren. Meldungszahl und Zahl der darin enthaltenen Fälle
bleiben unterschiedliche Zähleinheiten. Historische Kontakte und undatierte
Bewertungen behalten ihre bisherige Aussage; daraus werden keine alten Mails
rekonstruiert.

Kontaktrecherche, generierte Entwürfe, Gruppierung nach Betreiber oder Entwickler
sowie Antworten und Wiedervorlagen sind mögliche Folgeabschnitte. Die bisherigen
Vorschläge zur Codex-Anbindung und zu Beziehungen bleiben dafür erhalten.

**Alternative:** Wenn Sichten und Bewerten derzeit mehr Zeit kostet, zuerst einen
Studio-Review-Arbeitsvorrat mit vorhandenen Belegen, gezieltem Abarbeiten und
„Speichern und weiter“ zuschneiden. Die offenen Regeln zu neuen Sichtungsanlässen,
Erledigen und Zurückstellen bleiben hierfür entscheidend. Fehlende neue manuelle
Bewertung im Altbestand darf nicht ungeprüft sämtliche alten Fälle als neue
Sichtungsarbeit einordnen.

**Damals offene Prioritätsfrage:** Wo geht heute mehr Zeit verloren: beim Sichten
und Beurteilen oder beim Zusammenstellen, Schreiben und Nachhalten von Meldungen?
Der Nutzer hat die Priorität anschließend mit dem gut funktionierenden manuellen
Export und dem Vorrang der Grundlagen geklärt. Der noch offene Betriebsnachweis
aus einer frischen DDEV-Kopie bleibt separat erhalten; Sicherungs- und
Übernahmeprüfungen gelten weiterhin für schreibende Änderungen.

## Aktuelle Richtung: Grundlagen und Review vor Kontaktmanagement

**Vom Nutzer am 2026-10-03 entschieden:** Der E-Mail-Teil funktioniert über den
manuellen Export gut genug. E-Mail- und Kontaktmanagement werden vorerst
zurückgestellt. Zuerst sollen die übrigen wichtigen Grundlagen fertig werden.
Das ist eine Priorität, keine Aussage, dass Meldungsverwaltung dauerhaft entfällt.

**Beobachteter offener Stand vor Studio-Review v1:** Beim Betriebsabschluss fehlt noch der strenge
N01-Nachweis aus einer vollständig frischen, isolierten DDEV-Projektkopie.
Studio-Eingang, Bestand, Detail und Statistiken sind umgesetzt. Ein eigener
Review-Arbeitsvorrat mit Sichtungsgründen und gezieltem Abarbeiten fehlt; die
vorhandene manuelle Bewertung darf durch technische Beobachtungen nicht ersetzt
werden. Alte Fälle ohne neue Bewertungshistorie sind nicht automatisch neue
Sichtungsaufgaben.

**Frühere Empfehlung für die Reihenfolge, durch den neuen Review-Wunsch als
Priorisierung überholt:**

1. **Betriebsabschluss N01:** Eine frische isolierte DDEV-Kopie starten,
   Abhängigkeiten und Migrationen aus der Projektkonfiguration aufbauen,
   die lokale Aufnahme mit und ohne Dialog prüfen und den Ablauf nach Neustart
   wiederholen. Ergebnis ist ein belegter reproduzierbarer Aufbau oder ein
   konkreter, anschließend behobener Konfigurationsfehler. Die bestehende
   Nutzerdatenbank bleibt außerhalb dieses Versuchs.
2. **Studio-Review v1:** Einen paginierten Arbeitsvorrat mit getrennten
   Sichtungsgründen und einem Eintrag pro Fall zuschneiden. Aus ihm einen Fall
   öffnen, vorhandene Belege und Beobachtungen vergleichen, die Bewertung
   bewusst ändern oder erhalten, einen Hinweis ausdrücklich erledigen und zum
   nächsten Fall wechseln. Spätere Beobachtungen können einen neuen Anlass
   erzeugen, ohne die frühere Entscheidung zu überschreiben.

Vor einem Review-Umsetzungsplan sind noch die in diesem Dokument genannten
Fachregeln zu klären: Welche Ereignisse erzeugen einen Sichtungsgrund? Welche
gesehenen Beobachtungen erledigt „geprüft“? Wie und bis wann wirkt
„zurückstellen“? Welche frühere Beurteilung dient als Vergleichsreferenz?
Die bestehende Bild- und Bewertungshistorie bietet dafür eine Grundlage, aber
Altwerte erhalten keine erfundene frühere manuelle Entscheidung. E-Mail-Export
und bestehende Kontaktmarkierungen laufen im bisherigen manuellen Ablauf weiter.
Der vorhandene Artefakt-Audit macht historische fehlende Bilddateien bereits
sichtbar. Wenn sie den Review-Ablauf konkret behindern, ist eine gezielte
Belegpflege ein eigenes Folgepaket; eine neue Aufnahme wäre ein neuer Beleg zum
neuen Zeitpunkt und keine Wiederherstellung des historischen Bilds.

## Neuer Vorschlag: bildzentrierte Review-Seite

**Nutzerwunsch vom 2026-10-03:** Vor dem Kontaktmanagement und nach den bereits
gebauten Studio-Seiten soll zuerst eine eigene Seite für Fälle entstehen, die
technisch nicht eindeutig bestätigt werden konnten. Screenshot und PoC sollen
nebeneinander eine manuelle Verifikation ermöglichen. Die Fälle sollen sich
schnell wie ein Kartenstapel mit Links/Rechts-Aktion bestätigen oder „rejecten“
lassen. Der Nutzer zieht damit Review gegenüber der früher empfohlenen
N01-zuerst-Reihenfolge vor. N01 blieb damals ein eigener offener Betriebsnachweis; die
Sicherungs- und Übernahmeprüfung vor schreibenden Änderungen gilt weiter.

**Beobachteter Stand:** Das Studio-Falldetail zeigt gespeicherte Screenshots,
Aufnahmezustand und manuelle Bewertung. URL, Methode, Parameter, Payload und
erwartetes Kennzeichen sind vorhanden, aber teils eingeklappt. Ein eigener
Review-Vorrat oder ein Erledigungszustand für einzelne Sichtungsanlässe fehlt.
`confirmed`, `fixed` und `discarded` sind bestehende manuelle Urteile;
`discarded` entfernt den Fall aus der normalen Arbeit. Ein neuer Eingang startet
keinen automatischen Retest. Die aktuelle Anzeige bietet „Bestätigen“ zudem
nicht allgemein für jeden Fall ohne technischen Lauf an, obwohl der bestehende
Schreibweg das manuelle Bestätigen bereits unterstützt. Eine bloße neue
Seitengestaltung bildet den gewünschten Ablauf deshalb noch nicht ab. In den
aktuellen Bestandsdaten sind Payload und Request-Parameter selten beziehungsweise
nicht aufgezeichnet; URL und erwartetes Kennzeichen sind deshalb häufig der
gesamte vorhandene PoC-Kontext und dürfen nicht als vollständige Reproduktions-
anleitung bezeichnet werden.

**Bestandsprüfung vom 2026-10-03, rein lesend:** Von 5.538 aktiven Fällen haben
218 als letzte technische Beobachtung `inconclusive` (0 mit lesbarer Bilddatei),
78 `error` (1 mit lesbarer Bilddatei) und 196 keinen Retest (188 mit lesbarer
Bilddatei). Diese Einmalaufnahme prüfte 501 referenzierte Dateipfade; sie ist
keine Aussage über künftige Bildaufträge. Der zunächst erwogene Standardvorrat
nur aus `inconclusive`/`error` würde das gewünschte bildgestützte Review heute
praktisch leer lassen.

**Revidierter vorläufiger Zuschnitt für Review v1:** Ein eigener Studio-
Arbeitsbereich zeigt zuerst aktive Fälle **ohne technischen Lauf und mit
verfügbarem Bild**, soweit kein manuelles Urteil gespeichert ist.
Uneindeutige und fehlgeschlagene technische Läufe sowie Fälle ohne Bild sind
getrennt auswählbar; sie dürfen nicht wie bildbereite Karten aussehen. Der
fehlende Lauf bedeutet nicht, dass ein alter Fall sicher noch nie von einem
Menschen gesichtet wurde; Altmarker bleiben als solche sichtbar. Der Vorrat
ist paginiert und stabil sortiert. Jeder Fall erscheint einmal mit seinen
Sichtungsgründen. Die Karte zeigt ein großes, auswählbares Bild, dessen bekannten
Aufnahmezeitpunkt und Dateistand sowie daneben alle gespeicherten PoC-Angaben und
den bisherigen manuellen/technischen Status. Ein fehlendes Bild, eine laufende
Aufnahme oder unbekannte historische Herkunft werden offen angezeigt. Die
Bildauswahl behauptet keinen Bezug zu einem Retest, wenn keiner gespeichert ist.
Auf großen Fenstern stehen Bild und PoC nebeneinander; bei halbbreiten Fenstern
bleibt das Bild dominant und der PoC unmittelbar erreichbar. Die Seite dockt als
eigener Bereich in der unteren Studio-Navigation an; das Speichern muss einen
validierten Rückweg in den Review-Vorrat erhalten.

Sichtbare Schaltflächen sind der primäre Bedienweg; Pfeiltasten und Wischgesten
lösen dieselben bewusst beschrifteten Aktionen aus. Nach einer **erfolgreich
gespeicherten** Entscheidung wird zum nächsten Fall gewechselt. Fehler halten
den aktuellen Fall mit Eingaben und Fehlermeldung offen. Die Bewertungshistorie
bleibt erhalten; ein späteres technisches Ergebnis überschreibt ein manuelles
Urteil nicht. Der Fall ist weiterhin im vollständigen Falldetail erreichbar.

**Damals vor Umsetzung offen:** Bedeutet „Reject“ einen fachlichen
Fehlalarm (`discarded`, aus dem normalen Bestand entfernt), oder nur „dieser
Nachweis reicht nicht“ (Fall behalten und zur erneuten Prüfung zurückstellen)?
Ein undeutliches Bild allein belegt keinen Fehlalarm. Davon hängen die linke
Wischaktion, der nötige Sichtungszustand und die Beschriftung ab. Ebenso muss
feststehen, ob der Nutzer im ersten Arbeitsvorrat die bildbereiten Fälle ohne
Retest und ältere Fälle ohne protokollierte Bewertung gemeinsam bearbeiten
möchte. Die genauen Regeln für Erledigen, Wiedervorlage und erneute Beobachtungen
werden am gewählten Anfangsumfang festgemacht. Dieser Abschnitt dokumentiert den
damaligen Vorschlag; der anschließend beauftragte Umfang folgt unten.

**Nach dem Screenshot-Backfill ausdrücklich zur Umsetzung beauftragt:** Der
Nutzer hat die nötigen Aufträge selbst eingereiht und danach die Review-Ansicht
beauftragt. Für v1 wird die Menge dieses Backfills als Vorrat verwendet:
aktive Fälle ohne protokolliertes manuelles Urteil mit `inconclusive`, `error`
oder ohne Lauf. Die vorhandene Bildverfügbarkeit entscheidet über den
Standardfilter; alle drei Anlässe sind nun wählbar. Das ersetzt den nur wegen
fehlender Bilder erwogenen Einstieg allein mit Fällen ohne Retest.

**Zunächst umgesetzter Zuschnitt, linke Aktion durch den Folgewunsch ersetzt:**
Bestätigen und bewusstes Verwerfen verwenden vorhandene
Urteile. Neutrales Überspringen verändert keinen Fall; eine dauerhafte
Wiedervorlage wird damit nicht vorgetäuscht. Die linke Geste überspringt, die
rechte bestätigt; Verwerfen ist eine separate ausdrückliche Aktion. Dies löst
den ersten Anwendungsfall, ohne die offenen Regeln für späteres Hinweis-
Management vorwegzunehmen. [Vertrag und Grenzen](studio-review.md).

**Ausdrückliche Präzisierung nach der ersten Umsetzung:** Der Nutzer möchte
zwei bewertende Hauptaktionen: rechts „Vulnerable“ = `confirmed`, links
„Not vulnerable“ = `fixed` mit dem vorhandenen Marker `confirmed_fixed`.
Beide Aktionen speichern die vorhandene manuelle Bewertung und Historie und
wechseln erst bei Erfolg zum nächsten Fall. Pfeiltasten und Wischgesten erhalten
dieselbe Bedeutung. Neutrales Überspringen bleibt als kleiner separater Link;
bewusstes Verwerfen bleibt eine eigene Aktion. Damit ist die offene Bedeutung
der linken Hauptaktion für dieses Vorhaben geklärt.

## Nach Review v1: verbliebene Grundlagen vor Kontakten

**Nutzerrückmeldung:** Die Links-/Rechts-Bewertung wurde ausprobiert und scheint
zu funktionieren. Kontakte bleiben zurückgestellt; gefragt ist jetzt eine
Einordnung der noch unfertigen Grundlagen. Die folgende Reihenfolge war zunächst
eine Empfehlung. Der Nutzer hat danach den Commit und alle drei Punkte ausdrücklich
beauftragt. Kontakte bleiben zurückgestellt.

**Prüfgrundlage:** Lesender Abgleich der Architektur mit den aktuellen
Controller-, Review-, Detail-, Export- und Artefaktpfaden einschließlich der
uncommitteten Review-Dateien. Keine neuen Tests, Datenbankprüfungen, Aufnahmen
oder Dienstaktionen für dieses Sparring. Die zuletzt ausgeführte Review-Abnahme
ist in [Studio-Review v1](studio-review.md) dokumentiert.

Als Abschluss des gerade nutzbaren Abschnitts empfiehlt sich ein Commit des
Review-Stands und eine aktuelle konsistente Sicherung. Beim reinen Sparring wurde
beides nicht ausgeführt; im anschließenden Umsetzungsauftrag wurde Review v1 als
`7a37dac` committed und ein aktuelles Backup restore-validiert. Backup und getrennte Restore-Prüfung
sind bereits implementiert und nachgewiesen; sie müssen nicht neu gebaut werden.

1. **Schreibaktionen absichern (N04):** Die neuen Bewertungswege prüften
   bereits Formular-Tokens. Beim Sparring fehlte diese Prüfung in `WebController`
   bei Einstellungen, Löschen und den älteren Screenshot-/Retest-Aktionen.
   `framework.csrf_protection: true` allein prüft handgebaute Formulare nicht.
   Das ließ die bestätigte lokale Anwendungsgrenze aus N04 unvollständig.
   Ein begrenztes Folgepaket sollte diese übrigen Wege mit dem bestehenden
   Schutz versehen. Abnahme: Fehlende oder ungültige Tokens ändern keine Daten
   und erzeugen keine Aufträge; gültige eigene Formulare erhalten die bisherigen
   Abläufe. **Spätere Nutzerkorrektur:** Die zusätzliche CSRF-Ergänzung soll
   vorerst entfallen. Die vier neuen Formularprüfungen wurden zurückgenommen;
   dieser Teil von N04 bleibt ausdrücklich zurückgestellt.
2. **Betriebsabschluss N01:** Versionierte Konfiguration und Neustart der
   bestehenden Installation waren belegt. Der Aufbau aus einer vollständig
   frischen isolierten Kopie war beim Sparring offen. Beauftragt wurde ein dokumentierter
   Start aus Projektkonfiguration, funktionierender lokaler Belegablage und
   erneuter Funktion nach Neustart. Die vorhandene Installation und ihre
   Nutzerdaten bleiben außerhalb dieses Nachweises. **Jetzt bestanden:**
   Aufbau aus `5f28d2e` mit 73 Harnessprüfungen und 9 realen Bildanzeigeprüfungen,
   vier lokalen Queue-Aufnahmen und vollständigem Datenerhalt nach Neustart.
   [Abnahme und Grenzen](abnahme-betriebsabschluss.md).
3. **Review für neue gespeicherte Hinweise:** Die frühere Detailprojektion erkannte
   Beobachtungen nach einer manuellen Bewertung und zeigte einen Hinweis.
   Der erste `/review`-Vorrat verlangte dagegen `manual_assessment IS NULL` und
   erfasste solche Fälle nicht. Vorgeschlagen wurde ein Arbeitsvorrat für neue Hinweise samt
   ausdrücklichem Erledigen bei unverändertem Urteil. Empfehlung: Eine
   Sichtung kann das Urteil ändern oder den konkreten Hinweis als geprüft
   markieren, während Urteil und dessen Datum erhalten bleiben. Welche neuen
   Beobachtungen einen Anlass erzeugen und welcher Stand als erledigt gilt,
   ist vor der Umsetzung zu klären. Dieses Paket gewinnt an Bedeutung, sobald
   neue technische Beobachtungen zu bereits bewerteten Fällen bearbeitet werden.

**Festlegung im Umsetzungsauftrag:** Erneut erscheinen ausschließlich
widersprüchliche Ergebnisse, Unklarheiten und Fehler. Passende Ergebnisse lösen
keinen Hinweis aus. Die ausdrückliche Sichtung kann das Urteil ändern oder
„Geprüft · Bewertung behalten“ speichern. Der vollständige Datenfluss ist in
[Studio-Review](studio-review.md#neue-hinweise-nach-einem-urteil) fortgeführt.

**Aktueller Prüfstand:** Der Hinweis-Vorrat und die Sichtung bei unverändertem
Urteil sind implementiert. 14 isolierte Notice-Browserprüfungen und alle
23 bisherigen Review-Browserprüfungen bestanden, einschließlich nativer
Formulare ohne JavaScript und vier Fensterbreiten. Die additive Migration auf
einer restore-validierten Datenkopie erhielt alle bisherigen Spalten und
Tabelleninhalte; alle 661 Belegdateien blieben bytegleich.

**Hinweis-Ausbau abgeschlossen:** Die vollständige Suite besteht mit 220 Tests
und 3.205 Assertions; darin 10 neue Backendprüfungen mit 178 Assertions zu Policy,
Rollback, Konflikten, Altbestand und Migration. Die live angewendete Migration
bewahrte sämtliche vorhandenen Inhalte unter einem kurzen Schreiblock; die
lesenden Live-Abrufe lieferten HTTP 200. [Vollständige Review-Abnahme](studio-review.md#neue-hinweise-nach-einem-urteil).
Auch N01 ist mit dem frischen isolierten Lauf abgeschlossen; seine
Schema-Diagnose und die tatsächlichen Bildzustände sind im eigenen Bericht
festgehalten. Die zusätzliche CSRF-Ergänzung bleibt auf Nutzerwunsch zurückgestellt.

**Bedingtes Folgepaket Belegpflege:** Fehlende Dateien und fehlgeschlagene Aufträge
sind sichtbar, und der lesende Artefakt-Audit existiert. Nach dem manuellen
Backfill fehlt in diesem Sparring eine aktuelle Bestandsaufnahme. Historische
Zahlen wie 382 Fehlverweise oder die frühere Bildverfügbarkeit sind kein Beleg
für heutige Lücken. Erst eine aktuelle Sichtung sollte den Umfang einer
Belegpflege begründen. Historische Metadaten bleiben erhalten; ein späterer
Beleg dokumentiert seinen tatsächlichen neuen Zeitpunkt.

Dauerhafte Wiedervorlage, bequemer Bildvergleich, eine schnelle Rücknahme im
Review, ähnliche URLs und automatische Gruppierung bleiben mögliche spätere
Erweiterungen. Korrektur im Falldetail, Notizen, Suche, getrennte Bewertungen,
Belegzustände und der manuelle Export sind bereits vorhanden. Ein neues
Kontaktpaket braucht nicht sämtliche dieser Komforterweiterungen als Vorlauf.

## Release-Sparring: Studio vervollständigen und Legacy ablösen

Stand: 2026-10-03, lesender Codeabgleich auf `e46ea40` mit anschließender
Nutzerpräzisierung. Der kommende Release soll den ersten vollständigen MVP
darstellen; der bisherige Stand wird fachlich als wenig genutzter Prototyp
eingeordnet. Die Anforderungen für diesen Release sind konkretisiert, die
Produktumsetzung war zu diesem Zeitpunkt noch nicht beauftragt. Anschließend
hat der Nutzer die Fallaktionen und den schlichten Export mit „1. 2. umsetzen“
beauftragt; zum Polish aus Punkt 3 sollen danach Rückfragen folgen.

**Bestätigte Richtung:**

- Betreiber-Priorität und bisheriger HTML-Prioritätsexport sollen entfallen.
  Das war eine nebenbei entstandene Hilfe zur Kontaktpriorisierung und gehört
  nicht in den neuen Release. Die frühere Empfehlung, diese Seite in Studio zu
  übernehmen, ist damit ersetzt. Der bestehende CLI-Domain-/JSON-Export ist
  davon unabhängig; sein Entfernen wurde nicht festgelegt.
- Manuelles Screenshot-Einreihen, erneute technische Prüfung und endgültiges
  Löschen sollen in die moderne Fallansicht übernommen werden.
- Einstellungen, Credits und ähnliche Details sollen ein abschließender
  Polish-Schritt vor dem Release sein.
- Eine Upgrade-Anleitung ist für diesen MVP ausdrücklich keine Anforderung.
  Stattdessen soll Init/Start aus frischer Kopie einschließlich Datenerhalt
  geprüft werden, ohne die tatsächlich verwendete Installation oder deren
  Nutzerdaten zu überschreiben.
- README-Bilder und die vorhandene Zensierung sollen für Studio aktualisiert
  werden.
- **Lizenz für den MVP-Release: `GPL-3.0-or-later`.** Der Nutzer hat diese
  Variante nach Hinweis auf die bestehende BSD-Lizenz ausdrücklich ausgewählt.

Die zunächst offene Export-Idee wurde durch den anschließenden Auftrag als
schlichter JSON-Arbeitsbereich angenommen. Der Nutzer verwendet sie selbst
zunächst nicht. Kontaktmanagement und Mailanbindung bleiben zurückgestellt.

### Beobachtete Funktionsabdeckung

Eingang, Bestand mit Suche/Filtern/Archiv, Bewertung, Belege und technische
Historie sind in Studio vorhanden. Notizenbearbeitung, Versandmarkierung,
Statistiken und der eigene Review-Vorrat gehen über die klassische Ansicht
hinaus. Es bleiben diese konkreten Abhängigkeiten:

| Funktion | Stand auf `e46ea40` | Aktuelle Richtung |
| --- | --- | --- |
| Einstellungen | Studio-Zahnrad öffnet `/legacy/settings`; `GET /settings` leitet dorthin. Zwei Werte sind editierbar: Standardkennzeichen und Prüfzeitlimit. | Im abschließenden Polish als kleine Studio-Seite übernehmen. |
| Betreiber-Priorität / HTML-Export | Eigener Controller unter `/legacy/operator-priority`; `/operator-priority` ist nur Weiterleitung. | Auf Nutzerwunsch entfernen; keine Übernahme von Gruppierung, Research-Ledger oder Kontaktpriorisierung in den neuen Export. |
| Manuelle Wartungsaktionen | Screenshot einreihen, Recheck mit Screenshot und endgültiges Löschen haben nur klassische Formulare. | Ausdrückliche Aktionen im Studio-Falldetail übernehmen; Löschung getrennt und klar beschriften. |
| Info / Version | `/about` öffnet ein klassisches Modal; dessen Inhalt nennt noch `v1.0.1`. | Credits/Info und gemeinsame Versionsangabe im abschließenden Polish. |

Belege: `templates/studio/navigation.php`, `templates/studio/finding.php`,
`src/Controller/StudioController.php`, `src/Controller/WebController.php` und
`src/Controller/PriorityExportController.php`.

### Technische Grenzen beim Entfernen

`WebController` enthält neben den klassischen Renderern auch den nativen Intake,
Bewertungen, Notizen, Kontakt-/Versandmarkierungen und die Artefaktauslieferung.
Diese gemeinsamen Endpunkte müssen erhalten oder in passende Controller
verschoben werden. Die Klasse als Ganzes zu löschen würde auch Studio-Funktionen
und Bilder im Review entfernen. Einstellungen koppeln klassischen GET und
gemeinsamen POST sogar in derselben Methode.

Rücksprünge von Aktionen und Fehlern sowie klassische Links in Studio sind
umzustellen. Alte Detail- und Bestandsadressen können als kleine
Kompatibilitätsbrücke weiterführen; das ist eine Empfehlung und kein neues
Upgrade-Vorhaben. Bei Einstellungen ist die bestehende permanente
308-Weiterleitung zu beachten: Ein sofortiger Gegenredirect von Legacy zur
kanonischen Adresse kann mit einem Browsercache eine Schleife bilden. Der alte
Settings-Pfad kann zunächst die neue Ansicht direkt mitbedienen. Die entfernte
Prioritätsseite erhält keinen scheinbar gleichwertigen Ersatz unter `/export`.

Historische Status-/Review-Werte, Diagnosefilter und Belegmetadaten sind
Datenkompatibilität und bleiben erhalten. Die unabhängigen CLI-Exporte und der
ältere CLI-Aufnahmepfad sind vom Entfernen der klassischen Weboberfläche zu
unterscheiden.

### Studio-Bereich Export v1

Beauftragt und implementiert ist ein eigener Bereich `/export` in der unteren Navigation mit einem
durchgängigen kleinen Ablauf: vorhandene Bestandsfilter übernehmen oder den
aktiven Bestand wählen, Anzahl der Fälle zeigen und den aktuellen Fallstand als
JSON herunterladen. Bewertungen, letzte technische Beobachtung und
Kontakt-/Versandzeitpunkte bleiben getrennte Felder. Verweise auf Belege sind
Metadaten; tatsächliche Bilddateien und vollständige Historien sind ein möglicher
späterer Paketexport. Private Fallnotizen werden ausschließlich nach ausdrücklicher
Auswahl mitgegeben.

JSON wurde für den kleinen Start gewählt. Bereits `app:export:json` und
`ExportService` liefern einen älteren separaten CLI-Weg. CSV bleibt eine Alternative, falls die erste
Zielnutzung Tabellenarbeit ist. Ein ZIP mit Bildern würde eine größere
Dateiauswahl und Behandlung fehlender Belege verlangen. Der Export ersetzt
weder den bestehenden konsistenten Backup-/Restore-Ablauf noch einen
leserorientierten Bericht oder eine Meldung.

Der vorhandene JSON-Export ist nicht unverändert für diese Seite geeignet:
Er enthält private Notizen und nur die 20 jüngsten technischen Läufe, aber
keine getrennte manuelle Bewertung, Bewertungsgrundlage/-historie, Sichtungen
oder Screenshot-Auftragshistorie. Eine unbekannte Domain wird derzeit außerdem
zu einem ungefilterten Export; die Domainliste entspricht bei manchen Filtern
nicht der gewählten Fallmenge. Auswahlregeln aus `FindingListService` und
`FindingReadRepository` sollten daher für Vorschau und Download gemeinsam
verwendet werden. Eine unbekannte Auswahl darf die Menge nicht still erweitern.
Die Webseite soll über einen Anwendungsservice arbeiten und keine CLI-Prozesse
starten. Der neue Webexport verwendet hierfür einen eigenen lesenden
`StudioExportService`; der ältere CLI-Vertrag wurde nicht umgestellt.
Belege für den Ausgangspunkt: `src/Service/ExportService.php`,
`src/Command/ExportJsonCommand.php`, `src/Command/DomainExportCommand.php` und
`src/Repository/FindingReadRepository.php`.

### Export nach Verwendungszweck: Sparring zur Erweiterung

Nutzeranregung vom 2026-10-04: Export granularer und konfigurierbar gestalten.
Als Zwecke nennt er eine kompakte URL-/Schwachstellentyp-Liste für die weitere
Verwendung bei OpenBugBounty und ein Paket mit Screenshots/Details zum manuellen
Melden. Der Nutzer hat den daraus vorgeschlagenen Zuschnitt anschließend mit
„bau es“ beauftragt. Für die Häufigkeit der einzelnen Zwecke gibt es keine
Nutzungsdaten; die Priorisierung bleibt eine Produktempfehlung.

**Festgelegt und umgesetzt:** Drei verständliche Vorlagen mit geeigneten Vorgaben:

| Vorlage | Zweck | Ausgabe |
| --- | --- | --- |
| URL-Liste | Manuelle Übernahme oder Weiterverarbeitung von URL und Schwachstellentyp | Kompaktes neutrales JSON mit `{url,type}` je Fall |
| Meldung mit Belegen | Ausgewählte Befunde mit gespeicherten Nachweisen weitergeben | ZIP mit lesbarem Markdown-Bericht, Fall-/Dateizuordnung und gewählten Screenshot-Dateien |
| Aktueller Fallstand | Daten für eigene Werkzeuge und Auswertungen | Vorhandenes versioniertes JSON des gespeicherten Fallstands |

Ein mit OBB kompatibler JSON-/Dateiimport ist bisher nicht belegt. Die neutrale
URL-Liste soll erst nach Klärung des tatsächlichen Zielvertrags als konkreter
OBB-Import bezeichnet werden. Rückfrage ist gestellt: generische JSON-Liste,
manuelle Formularübernahme oder vorhandenes Importwerkzeug/festes Dateiformat?
Die Antwort steht noch aus. CSV bleibt eine mögliche Ergänzung bei tatsächlichem
Tabellen-/Importbedarf.

Die UI beginnt mit einer Vorlage und zeigt danach die vorhandene Fallauswahl und
„Inhalt anpassen“. Die Anpassung erfolgt nach verständlichen Gruppen:
Basisdaten, gespeicherte Request-/Nachweisdaten, Bewertung/letzte Beobachtung,
Kontakt-/Versandstand, Bilder sowie ausdrücklich gewählte private Fallnotizen.
Vorlagen verändern Inhalt und Verpackung; eine bestehende Filterauswahl wird
dabei nicht stillschweigend erweitert. Die Vorschau zeigt ausgewählte Fälle,
Domains, Bilder, fehlende Dateien und unbekannte Bewertungsgrundlagen.

**Belegauswahl:** dokumentierter Beleg der
Bewertung, neuester gespeicherter Bildbeleg oder alle gewählten Bildbelege.
Für eine Meldung wird die dokumentierte Bewertungsgrundlage bevorzugt. Fehlt
eine solche Zuordnung, muss das sichtbar sein und die Bildauswahl ausdrücklich
erfolgen; ein neuerer Screenshot darf nicht als früher beurteilte Grundlage
ausgegeben werden. Aufnahme- und Ablagezeit sind zu unterscheiden. Urteil und
neuere abweichende Beobachtung bleiben im Bericht getrennt. Private Fallnotizen
und als Evidence gespeicherte Notizen benötigen bewusste Auswahl.

**Ausgangsbefund vor der Erweiterung:** Der damalige Webexport lieferte lokale Artefaktlinks,
keine Bilddateien, keine Existenzprüfung der Bilder und keine explizite
Bewertungsgrundlage. Er liest die Auswahl seitenweise in einem SQLite-Snapshot.
Für ein Paket sind Dateiinhalt und die zur Einordnung nötigen Aufnahmemetadaten
zu ergänzen. `EvidenceStorageInterface`/`LocalEvidenceStorage` bieten lesende
Dateizugriffe; `FindingDetailService` zeigt die vorhandene Zuordnung zu Jobs und
Bewertungen. Die bisherige JSON-v1-Ausgabe sollte einen stabilen Vertrag behalten;
neue Profile sollten klar bezeichnete eigene Ausgabeformen erhalten. Paketexport bleibt
lesend, verwendet vorhandene lokale Belege und setzt keinen Kontakt-/Versandstand.

**Umgesetzter Vertrag:** Die drei Vorlagen verwenden weiterhin die vollständige
Bestandsfilterung über alle Listenseiten. Die URL-Liste enthält ausschließlich
`url` und `type`. Der aktuelle Fallstand behält mit allen Inhaltsgruppen den
bisherigen JSON-v1-Vertrag; einzelne Gruppen können weggelassen werden. Das
Meldungspaket enthält einen gemeinsamen Markdown-Bericht, ein strukturiertes
Manifest und je nach Auswahl die dokumentierte Bewertungsgrundlage, das neueste,
alle oder keine gespeicherten Screenshotdateien. Request-/PoC-Daten,
Bewertung/Beobachtung, Kontakt/Versand und private Notizen sind getrennt wählbar;
Notizen bleiben standardmäßig aus.

Die dokumentierte Bildgrundlage stammt nur aus der aktuellen, zur gespeicherten
Bewertung passenden Historie und ihrer neuesten Sichtungsbestätigung. Fehlt sie,
wird kein neueres Bild als Ersatz ausgegeben. Die Vorschau zählt nach Pfad,
Lesbarkeit und Größe voraussichtlich beifügbare sowie fehlende Dateien; das
Paket prüft zusätzlich Hash und Bildformat. Manifest und Bericht benennen
ausgelassene Dateien und Fälle ohne bekannte Grundlage. Ablage- und bekannte
Aufnahmezeit bleiben getrennt. Das ZIP liest Artefakte ausschließlich über den
konfigurierten Evidence-Speicher, verwendet bereinigte Archivnamen und gibt keine
internen Dateipfade aus. Der Ablauf ist lesend und löst weder Recheck noch
Screenshotauftrag, Kontakt oder Versandmarker aus.
Dateipfade müssen zum ausgewählten Fall gehören. Vor dem Beifügen werden ein
vorhandener gespeicherter SHA-256-Wert und das tatsächliche Bildformat geprüft;
GIF, JPEG, PNG und WebP sind erlaubt. Ein Bild ist auf 25 MiB und die Summe der
für ein Paket geprüften Bilddaten auf 512 MiB begrenzt. Ausgelassene Dateien bleiben mit einem
konkreten Grund in Manifest und Bericht sichtbar. Reduzierte Fallstand-Exporte
verwenden einen eigenen, selbstbeschreibenden JSON-v2-Vertrag; nur der
vollständige Fallstand delegiert unverändert an JSON v1.
Das externe Paket ersetzt lokale Finding-/Evidence-UUIDs durch paketlokale
Fall-/Bildnummern und lässt allgemeine Fall-Lebenszykluszeiten sowie die Filterabfrage
weg. Gewählte Bewertungs-, Beobachtungs-, Kontakt- und Versandangaben erscheinen
sowohl im Manifest als auch im lesbaren Bericht.

Offen bleibt allein der tatsächliche OBB-Weiterverwendungsweg. Bis ein belastbarer
Importvertrag vorliegt, ist `{url,type}` bewusst eine neutrale JSON-Liste und
kein behauptetes OBB-Format. Ein TXT-/CSV-Format kann bei einem konkreten Bedarf
ergänzt werden. Die vorhandene Backup-/Restore-Funktion bleibt der Weg zur
konsistenten Sicherung.

### Vorgeschlagene Arbeitsfolge bis zum MVP-Release

1. **Fallaktionen vervollständigen und Nebenfunktion abbauen:** Die drei
   vorhandenen Aktionen in Studio übernehmen und Legacy-Rückwege korrigieren.
   Screenshot-Einreihen verändert keine Bewertung; Recheck startet ausdrücklich
   technische Arbeit und kann separat vom Screenshot fehlschlagen. Endgültiges
   Löschen entfernt den Fall mit Historien, Aufträgen und Belegen und führt
   zurück zum Bestand. Betreiber-Priorität und HTML-Prioritätsexport entfernen.
2. **Export als kleines eigenes Vorhaben:** Den obigen Vorschlag hinsichtlich
   Zielnutzung und Datenumfang festlegen. **Anschließend beauftragt und umgesetzt:**
   JSON des aktuellen Fallstands mit gemeinsamen Bestandsfiltern und optionalen
   privaten Fallnotizen. Der Bereich bleibt unabhängig vom abschließenden Polish.
3. **Polish vor Release:** Einstellungen, Credits/Info und konsistente
   Versionsangabe in Studio integrieren; anschließend verbleibende klassische
   Renderer, Assets und Verweise entfernen. Gemeinsame Aktionen und historische
   Daten erhalten. Eine neue Release-Versionsnummer ist noch nicht festgelegt.
4. **Lizenz und öffentliche Darstellung:** `LICENSE` und Composer-Metadaten auf
   die bestätigte `GPL-3.0-or-later`-Richtung ausrichten und erforderliche
   bestehende Lizenzhinweise erhalten. Aktuell gilt im Code noch BSD-3-Clause;
   Composer nennt widersprüchlich `proprietary`. Bereits unter BSD veröffentlichte
   Versionen bleiben unter dieser Lizenz nutzbar. Konkrete Research-Domainbezüge
   in `plan-studio-ingest.md` (Zeile 81) und `abnahme-abschnitt-2.md` (Zeile 332)
   neutralisieren; persönliche absolute Pfade in öffentlichen Unterlagen
   verallgemeinern. README, Bilder und Release Notes aktualisieren.
5. **Releasekandidaten isoliert abnehmen:** Vorhandene PHP-/Browserprüfungen
   passend zum Umbau ausführen und den N01-Nachweis aus einer frischen Kopie
   des neuen Commits wiederholen: eigener DDEV-Projektname und eigener
   Datenbank-/Artefaktspeicher, keine übernommenen Nutzdaten, Start ohne
   Handreparatur, wiederholte Initialisierung, Restart und erhaltene Datensätze
   und Bildbytes. Aufnahmen bleiben auf neutralen lokalen Fixtures; technische
   Recheck-Regressionen können den bestehenden Transportstub verwenden.
   Die Live-Installation erhält keine Lifecycle-, Reset- oder Init-Aufrufe.
   Eine kleine CI bleibt eine Empfehlung, kein neu festgelegter Releaseblocker.

Der vorhandene Screenshot-Befehl ist `ddev readme-screenshots`, implementiert
unter `.ddev/commands/host/readme-screenshots`. Er verwendet klassische
Selektoren für Domain-/Metadatenfelder und Belegbilder, öffnet `/` statt des
heutigen Bestands und wählt Fälle über alte Retest-Screenshotfelder. Die
Studio-Felder werden dadurch nicht zuverlässig redigiert. Empfehlung:
Ausgabeweg für Studio anpassen und neue Bilder mit kontrollierten Demo-Fällen
in isolierter Umgebung erstellen; die aktuelle Nutzdatenbank bleibt dafür
unberührt. Den alten Befehl unverändert auszuführen wäre kein verlässlicher
Nachweis der Zensierung.

Die frühere Empfehlung einer allgemeinen Upgrade-Anleitung ist auf Nutzerwunsch
ersetzt. Der beobachtete Unterschied zwischen früheren `.env`-Speicherpfaden
und den heutigen DDEV-Umgebungswerten bleibt ein technischer Befund, begründet
aber keinen zusätzlichen Migrationsauftrag am Bestand.

Die Release-Lesung des aktuellen Git-Baums fand keine versionierten lokalen
`.env`-Dateien, Datenbanken oder Storage-/Runtime-Verzeichnisse. Composer- und
Playwright-Lockfiles sind vorhanden; ein privater absoluter Importpfad in den
Browserharnesses wurde nicht belegt. Die beiden vorhandenen README-Bilder zeigen
Legacy und wurden visuell angesehen: Zielangaben sind verwischt; eine neue
Studio-Darstellung mit neutralen Beispieldaten ist trotzdem vorzuziehen.
Die gesamte Git-Historie und der aktuelle GitHub-Releasestand wurden nicht
geprüft. Der einzige lokale Tag ist `v1.0.0`.

Die vorhandene Abnahme zählt 220 PHP-Tests / 3.205 Assertions und 37
Review-Browserprüfungen. Frischer DDEV-Aufbau, Neustart und tatsächliche lokale
Bilder sind durch [N01](abnahme-betriebsabschluss.md) nachgewiesen;
die jüngste Bestandsmigration und Restore-Prüfungen durch
[Backup](backup.md) und [Review](studio-review.md).
Die ursprüngliche Sparring-Lesung hat keine Tests, Builds, Migrationen oder
Aufnahmen ausgeführt. Nach dem folgenden Umsetzungsauftrag wurden isolierte
Prüfungen für die neuen Funktionen ergänzt; der neue vollständige N01-Lauf bleibt
für den fertigen Releasekandidaten vorgesehen.

### Umsetzungsstand nach dem Auftrag für Punkt 1 und 2

`/export` und `/export/download` lesen gespeicherte Fälle, ohne technische
Arbeit auszulösen. Auswahl und Archiv verwenden die normalisierten
`FindingReadFilter`-Regeln des Bestands; Pagination begrenzt den Download nicht.
Die JSON-Ausgabe enthält Schema-Version, Zeitpunkt, tatsächliche Fall-/Domainzahl,
ausschließlich zugehörige Domains, getrennte manuelle/technische Felder sowie
Belegmetadaten mit relativen Artefaktlinks. Private Fallnotizen fehlen standardmäßig.
Die Ausgabe liest Fall- und Evidence-Seiten unter einer lesenden
SQLite-Transaktion; Datensätze und Bilddateien bleiben erhalten.
Darauf aufbauend sind jetzt drei Zweckvorlagen umgesetzt: neutrale URL-/Typ-
Liste, vollständiger JSON-v1-Fallstand beziehungsweise reduzierter JSON-v2-
Fallstand und ein ZIP-Meldungspaket. Das Paket enthält Markdown, Manifest und
wahlweise gespeicherte Bilder; lokale Datenbankkennungen und allgemeine
Fall-Lebenszykluszeiten werden nicht in den externen Bericht übernommen. Bildpfade bleiben an den Fall
gebunden, und Hash-/Formatfehler werden ohne Dateiinhalt ausgewiesen.

Im Studio-Inspector ist „Fall endgültig löschen“ in einem eigenen ausklappbaren
Bereich erreichbar. Das native Formular verlangt ein bewusst gesetztes
Bestätigungsfeld, das der Studio-POST auch serverseitig prüft. Erfolg kehrt zur
validierten Ausgangsliste oder zum Bestand zurück; fehlende Bestätigung erhält
den Fall. Die bestehende Löschfunktion entfernt seine Bewertungen, Sichtungen,
technischen Läufe, Screenshot-Aufträge, Belege und Dateien und lässt andere
Fälle und die Domain bestehen. Es wurde keine zusätzliche CSRF-Prüfung ergänzt.

Die Betreiber-Prioritätsseite, ihr HTML-Export, Alias, Navigation, spezieller
Kontakt-Rückweg und ungenutzte Repository-Auswahl wurden entfernt. Alte
Prioritätsadressen liefern 404. CLI-Exporte bleiben vorhanden.

**Begrenzung des umgesetzten Umfangs:** Eine neue UI-Anbindung der technischen
Recheck-/Screenshot-Ausführung gegen beliebige externe gespeicherte PoC-URLs
gehört nicht zum implementierten Ergebnis dieses Pakets. Diese beiden
beauftragten Fallaktionen sind damit noch nicht als Studio-Formulare umgesetzt.
Vorhandene technische Dienste und Endpunkte wurden dabei nicht erweitert.
Einstellungen, Credits/Info und vollständiger Legacy-Abbau bleiben Punkt 3.

**Abnahme des aktuellen Arbeitsbaums:** `ddev exec php vendor/bin/phpunit`
besteht mit **247 Tests / 4.100 Assertions**. Die drei Exportprüfungen umfassen
23 Tests / 852 Assertions, die Studio-Löschprüfungen 5 / 66 und die geänderte
Bestands-/Routenabnahme 8 / 439. Geprüft sind unter anderem Auswahl über mehr als
100 Fälle und Belege, unbekannte Domains, Notizen nur nach ausdrücklicher Auswahl,
v1-/v2-Vertrag, ZIP-Inhalte, Bildgrundlage, Pfadbindung, Hash-/Formatprüfung,
lesende GET-Aufrufe, Tempdatei-Cleanup sowie erhaltene Daten bei fehlender
Löschbestätigung und vollständige abhängige Löschung.

`tests/browser/studio-export-delete.cjs` besteht mit **23 Browserprüfungen und
15 tatsächlichen JSON-/ZIP-Downloads**. Die Abnahme umfasst Fenstergrößen von
375 × 844 bis 1440 × 900 einschließlich 960 × 600, übernommene Bestandsfilter,
alle drei Profile und vier Bildmodi, Inhaltsgruppen, Downloads nach noch nicht
angewendeten Formularänderungen und Bedienung ohne JavaScript. URL-JSON und drei
Report-ZIPs wurden über den echten Browserdownload geprüft. Der Download bleibt
durch normales Scrollen oberhalb der Navigation erreichbar; die sechs
Arbeitsbereichslinks passen auch im Falldetail bei 375 Pixeln Breite.

Die Browserabnahme verwendet den eigenen gesperrten Fixture-Router
`tests/Support/studio_export_delete_browser_router.php`, eine frische temporäre
Datenbank und gespeicherte synthetische Bildbytes. Nur zwei festgelegte Testfälle
werden gelöscht; ihre abhängigen Datensätze und Artefakte verschwinden, während
Domains und andere Fälle erhalten bleiben. Externe Aufrufe, JavaScript-Ausnahmen
und interne Browserfehler wurden nicht beobachtet. Temporärer Server und
Fixture-Speicher sind anschließend entfernt. PHP-Prüfungen verwenden ebenfalls
isolierte Testdaten. Die Live-Installation erhielt keine Init-, Reset-,
Migrations- oder Lifecycle-Aufrufe; der erneute N01-Aufbau bleibt Releasearbeit.

**Polish-Sparring am 2026-10-04 nach den Rückfragen:** Der Nutzer konkretisiert
die Autorenangabe als **Tom Graßmann IT+Media**, mit Link auf
`https://grassmann-it.de/` und dem gewünschten „Proudly vibe-coded“-Hinweis.
Hinzu kommt sein aktuelles OpenBugBounty-Profil. Die öffentliche Autorenwebsite
verlinkt bei der lesenden Prüfung auf
`https://www.openbugbounty.org/researchers/grassmann-it/`; dieses Linkziel ist damit
belegt. Lizenzinformationen und eine Übernahme der Einstellungen werden als
Polish vorgeschlagen. GPL-3.0-or-later ist bereits entschieden und wurde nicht
erneut zur Wahl gestellt; Repository-Lizenz und Composer-Metadaten sind weiterhin
noch nicht umgestellt.

Empfehlung bleibt eine gemeinsame Studio-Seite hinter dem Zahnrad mit getrennten
Bereichen für Einstellungen und Info/Credits. Kompakte Credits umfassen die
gewünschte Autorenzeile, Website, aktuelles OBB-Profil, GitHub sowie Lizenz und
später eine konsistente Versionsangabe. Seitenaufteilung, der Umgang mit den
übrigen persönlichen Altlinks und die Releaseversion sind noch nicht ausdrücklich
festgelegt. Der aktuelle Auftrag ist das Sparring; Produktcode für Punkt 3 wurde
in dieser Runde nicht verändert.

Der lesende Settings-Abgleich zeigt zwei sinnvolle bestehende Felder:
Standardkennzeichen und Zeitlimit für Screenshot-Aufnahmen. Die alte Hilfe nennt
pauschal Review/Retest, tatsächlich lesen die Screenshot-Queue und der CLI-Befehl
`review:refresh` diesen Zeitlimitwert; andere Prüfpfade verwenden eigene Defaults.
Vorschlag für die Studio-Übernahme: verständliche Beschriftung, sichtbare
Validierungsfehler, vollständige Eingaben bei Fehlern erhalten und erst nach
Gesamtvalidierung speichern. Die technische Worker-Grenze beträgt 1000–120000 ms.
Eine Vereinheitlichung technischer Prüfpfade wird dadurch nicht vorgeschlagen.
Das frühere `intake.auto_verify_mode` hat außerhalb von Tests keinen Verbraucher
und begründet kein zusätzliches Einstellungsfeld. Gespeicherte Nutzerwerte sollen
beim UI-Wechsel übernommen werden. Belege: `SettingsService`,
`ScreenshotQueueService`, `ReviewRefreshCommand` und `playwright-worker/server.js`.

### Mehrsprachigkeit vor dem Release

Nutzerergänzung vom 2026-10-04: Deutsch wird persönlich bevorzugt; Englisch soll
als zweite UI-Sprache vor dem Release hinzukommen. Das ist die gewünschte
Release-Richtung, noch kein gesonderter Implementierungsauftrag.

**Anschließende Nutzerpräzisierung:** Die UI-Sprache soll über eine
ENV-Einstellung für die Installation wählbar sein, mit Deutsch als Standard.
Empfohlener Konfigurationsvertrag: optionales `APP_LOCALE`, Werte `de` und `en`;
ohne gesetzten Wert startet die Anwendung deutsch. Die frühere Empfehlung eines
Sprachwechsels in Settings mit Browsercookie ist durch diese Richtung ersetzt.
Eine gemeinsame Übersetzungsgrundlage soll vor der Settings-/Credits-Umsetzung
entstehen, damit dieser Polish sofort beide Sprachen bedient.

Der vollständige kleine Umfang umfasst die Studio-Seiten einschließlich der
geplanten Einstellungen/Info, Navigation, zugängliche Beschriftungen, Hinweise,
Validierung, Rückmeldungen, API-Anzeigelabels und interaktive JS-Texte. Auch
Zahlen, Datumsanzeigen, Statistik-Tooltips und Kalenderbeschriftungen brauchen
lokalisierte Darstellung. CLI, technische Workerdiagnosen und eine eigene
Übersetzung der später entfallenden Legacy-Oberfläche sind zusätzlicher Umfang.

**Lesender Befund:** Es gibt keine aktivierte Translation-/Locale-Konfiguration;
`symfony/translation-contracts` liegt im Lockfile, `symfony/translation` nicht.
Die PHP-Vorlagen setzen `lang="de"`, Datums- und Zahlenformate sind in PHP und
JavaScript fest deutsch. UI-Texte liegen außerdem in Read-Labels, Diensten,
Controllern und den vier Frontend-Skripten. Empfehlung ist der vorhandene
Symfony-Übersetzungsweg mit stabilen Schlüsseln und DE-/EN-Katalogen; benötigte
JS-Texte können je Seite als kleine sicher kodierte JSON-Auswahl aus derselben
Quelle bereitgestellt werden. Ein Wechsel des Template-Systems ist dafür
nicht nötig.

Nach einem Wechsel der Installationssprache sind alte Statuslabels und Fehlersätze im
Intake-`sessionStorage` zu beachten. Die Anzeige sollte aus Zustandswerten und
Parametern neu entstehen, damit gespeicherte Entwürfe/Verläufe zur gewählten
Sprache passen. Eigene Fallinhalte, Notizen, gespeicherte technische Befunde und
JSON-Maschinenwerte sind Originaldaten. Fachliche Tagesgrenzen in Europe/Berlin,
Filterwerte und die Review-Semantik (`fixed` links, `confirmed` rechts) werden
von der Sprachwahl nicht verändert.
Auch fertige `message`-/`error`-Sätze in Rücksprung-URLs bleiben sonst in ihrer
alten Sprache; für App-Rückmeldungen sind Schlüssel plus Parameter vorzusehen.
Die Status-API ist bereits `no-store`. Die vorgeschlagene Browserpräferenz
ist ersetzt; die ENV-Konfiguration braucht keine neue Datenbankeinstellung oder
Migration. UI- und API-Anzeigen verwenden die konfigurierte Sprache der
Installation, normale GET-Aufrufe lesen sie nur.

**DDEV-Konfigurationsweg für die spätere Anleitung:** Anwendungsseitig Deutsch
als Default verwenden, keinen verbindlichen `APP_LOCALE=de`-Eintrag in die
versionierte DDEV-Konfiguration setzen. Für Englisch kann der lokale
`web_environment`-Abschnitt in `.ddev/config.local.yaml` um `APP_LOCALE=en`
ergänzt werden; bestehende Einträge bleiben erhalten. Eine geänderte DDEV-ENV
wird beim nächsten Start/Neustart wirksam. Die tatsächliche Installation erhält
in dieser Sparring-Runde keine Konfigurations- oder Lifecycle-Änderung.
Ein allgemeiner Verweis auf `.env.local` wäre derzeit unzuverlässig: Der
Bootstrap lädt Dotenv nur bei vorhandener `.env`, und bereits gesetzte
System-/DDEV-Variablen haben Vorrang. Die Umsetzung soll die Symfony-ENV-Auflösung
verwenden. `APP_LOCALE` ist im Produktcode derzeit noch nicht angebunden.

Die spätere Abnahme soll vollständige DE-/EN-Kataloge, deutschen Fallback,
ENV-Auswahl beider Sprachen mit erhaltenem Arbeitskontext und die UI mit/ohne
JavaScript sowie bei schmalen/kurzen Fenstern prüfen. Diese Runde hat nur
Code gelesen und den Projektstand fortgeschrieben; i18n ist noch nicht umgesetzt.

### README und öffentliche Release-Darstellung

Weitere Nutzerpräzisierung vom 2026-10-04: DE/EN bleibt die gewünschte Richtung.
Die README soll das Programm als attraktive lokale OpenBugBounty-Alternative
vorstellen; öffentliche Dokumentation und Screenshots sollen den neuen Studio-
Stand vermitteln.

**Befund:** Die aktuelle README umfasst 478 Zeilen und mischt Produkteinstieg,
Bedienung, technische Implementierungsdetails, Wartung und frühere Abnahmezahlen.
Sie bindet genau zwei versionierte Bilder unter `docs/screenshots/` ein, beide
zeigen Legacy. Die Beschreibung von Hinweis-Sichtungen als zukünftige Arbeit
widerspricht der bereits vorhandenen Funktion; die jüngste PHP-Abnahme steht
im Architekturindex. `playwright-worker/README.md` behauptet noch einen
Headless-Retest beim neuen Intake, obwohl heute nur gespeichert und eine Aufnahme
eingereiht wird. Die öffentlichen Einstiegstexte benötigen daher einen fachlichen
Abgleich mit dem finalen Kandidaten. Auch die als vollständiger „wipe“ bezeichnete
Reset-Beschreibung ist zu präzisieren: erhaltene Datensätze und manuelle
Bewertungen gehören zum tatsächlichen Umfang der jeweiligen Wartungsaktion.

**Vorschlag für den Auftritt:** Die bestehende englische Haupt-README für GitHub
beibehalten, eine deutsche Fassung oder Kurzeinführung bei Bedarf verlinken.
Das ist eine Empfehlung und unabhängig vom gewünschten deutschen UI-Standard.
Vorgeschlagene Positionierung: „A local-first OpenBugBounty alternative for
managing findings, evidence, and manual review.“ Der konkrete Nutzen sind lokaler
Daten-/Belegbestand, schneller Eingang, nachvollziehbare Bewertungen, manuelles
Review, Statistiken und Export. Die Release-README soll zuerst diesen Nutzen und
einen kurzen Workflow zeigen, danach Voraussetzungen/Quickstart sowie Autor und
Lizenz. Ausführliche Bedienung und Betrieb können in eigene verlinkte Dokumente
wandern. Öffentliche Anleitungen, Worker-README, Lizenz-/Versionsmetadaten und
aktueller Architekturindex werden abgeglichen; historische Abnahmeprotokolle
behalten ihren zeitlichen Bezug.

Für die README werden vier gut lesbare Studio-Bilder empfohlen: Review als
Hauptbild, dazu Bestand, Falldetail und Statistiken. Eine zusätzliche Galerie
aller Arbeitsbereiche einschließlich Eingang, Export und Settings bleibt optional.
Für den internationalen GitHub-Einstieg sind englische Demo-Bilder nach der
i18n-Umsetzung eine Empfehlung; doppelte DE-/EN-Bildserien sind noch nicht
festgelegt. Die Aufnahmen sollen aus einem eigenen isolierten Demo-Bestand mit
fiktiven Fällen, neutralen Domains, festen Zeitpunkten und lokalen Bildbelegen
entstehen. Bestehende Browserharnesses liefern Isolationsmuster, ihre absichtlich
fehlerhaften Regressionstestdaten sind keine fertige Werbe-Galerie.
Die veröffentlichten Beispiele werden als Demo-Daten gekennzeichnet.

Der aktuelle `ddev readme-screenshots`-Befehl liest den Live-Bestand und verwendet
Legacy-Zensierungsselektoren; er ist unverändert ungeeignet. Der geplante
Galerieweg verwendet ausschließlich kontrollierte Demo-Daten. Nutzdatenbank,
vorhandene reale Aufnahmen und Runtime bleiben dabei erhalten. Neue öffentliche
Bilder und finale Textaussagen folgen nach i18n, Settings-/Credits-Polish und dem
Legacy-Abbau. Diese Runde hat keine Produkttexte oder Bilder ersetzt und keine
Aufnahmen, Init-/Reset- oder sonstigen Lifecycle-Aktionen ausgeführt.

Komfortfunktionen wie Bildvergleich, Rücknahme im Review, dauerhafte Wiedervorlage
und Ähnlichkeitsgruppierung bleiben mögliche spätere Vorhaben. Die ausdrücklich
zurückgestellten zusätzlichen CSRF-Prüfungen werden durch diesen Vorschlag
nicht erneut beauftragt.

## Szenarien für die jeweiligen Funktionspakete

Die früher für einen frühen UI-Prototyp vorgeschlagenen Szenarien bleiben als
Prüfideen für die schrittweisen Erweiterungen erhalten:

1. Neue Eingabe und exakte Wiederholung mit verständlichem Überspringen; ein anderer
   Fall derselben Domain bleibt eigenständig und zeigt den bestehenden Kontext.
2. Ein Review-Fall hat mehrere Gründe und erscheint einmal. Notiz, Zurückstellen
   und Beibehalten der Bewertung sind nutzbar.
3. Wechsel von `vulnerable` zu `inconclusive`: beurteilte Referenz und neue Aufnahme
   vergleichen; mehrere unbeurteilte Zwischenstände sowie fehlendes Bild darstellen.
4. Eine Meldung aus ausgewählten Fällen bearbeiten. Bei späterer Codex-Anbindung
   Fortschritt, Fehler und Ergebnis darstellen; erneutes Erstellen erhält manuelle
   Textänderungen. Ein simulierter Auftrag allein nimmt die echte Anbindung nicht ab.
5. Bei späterer Gruppierung gemeinsamen Betreiber und gemeinsamen Entwickler getrennt
   behandeln; unsichere Vorschläge ändern und Fälle teilweise für eine Meldung auswählen.
6. Bei späterem Dashboard beziehungsweise Designwechsel in eine gefilterte Liste
   wechseln, einen Fall bearbeiten und anschließend den Auswahlkontext wiederfinden.

Vor dem Ausbau der Duplikaterkennung über die exakt gleiche URL hinaus ist die
Duplikatdefinition zu klären; vor CLI-Integration sind Betriebsweg und
Ergebnisvertrag zu konkretisieren. Die Screenshot-Stabilisierung ist umgesetzt.
Die Regeln für spätere Sichtungshinweise bleiben für das Review-Folgepaket zu
klären. Die aktuelle Empfehlung steht unter
[Verbliebene Grundlagen vor Kontakten](#nach-review-v1-verbliebene-grundlagen-vor-kontakten).
