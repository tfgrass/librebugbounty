# Arbeitsbereiche und UI – Diskussionsstand

Stand: 2026-10-03. Fortgeschriebener Diskussions- und Entscheidungsstand aus dem
Architektur-Sparring. Der schnelle Einzeleingang ist beauftragt; andere als solche
gekennzeichnete Ideen bleiben Vorschläge. Geltender Stand: [Index](index.md),
[Lastenheft](lastenheft.md).

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
Eingabefehler, unbekannten
Requestausgang und den persistierten Screenshotzustand. Ein Transportabbruch gilt
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
eine mögliche Studio-Ansicht hätten eigene Layouts, Navigation und Clientzustände.
Änderungen eines Falls wären anschließend in beiden sichtbar. Eine separate
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
einer erneuten beobachteten Blockade ausdrücklich zur Umsetzung beauftragt. Die
Studio-Ansicht bleibt ein folgendes Vorhaben und ist damit noch nicht beauftragt.

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
Als nächster fachlicher Zuschnitt bleibt die kleinere, ausdrücklich zu klärende
Trennung von manueller Bewertung und Sichtungshinweis aus Abschnitt 3.
