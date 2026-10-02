# Abnahme Arbeitsabschnitt 3b – Bestand konsistent lesen und filtern

Stand: 2026-10-02. Der Nutzer hat nach 3a auch 3b ausdrücklich beauftragt und
Commits mit aussagekräftigen Nachrichten gewünscht. Umfang: bestehende Übersicht,
Filter und Zähler ordnen; kein neues Review-Arbeitsverfahren.

## Umsetzung

`FindingReadFilter`, `FindingReadView` und `FindingReadRepository` bilden eine
gemeinsame lesende Projektion für Web-Übersicht und CLI. Auswahl und Count teilen
ihre WHERE-Bedingungen; die Seitenabfrage lädt skalare Werte ohne Finding-,
Evidence- oder Dateihydrierung. Counts ohne Beobachtungsfilter benötigen keinen
Lauf-Join. Das Home-HTML liegt jetzt in `templates/home.php`; die Detailansicht
verwendet dieselben Bewertungs- und Beobachtungsbegriffe.

Die folgenden Regeln sind technische Konkretisierungen des beauftragten 3b:

- Manuelles Urteil: `confirmed`, `fixed`, `discarded` oder keine aufgezeichnete
  Bewertung (`unknown`, gespeicherter Wert NULL). Ein historischer Schutzmarker
  bleibt erhalten und wird dadurch nicht zu einer neu belegten Entscheidung.
- Letzte technische Beobachtung: Ergebnis und Herkunft desselben gespeicherten
  RetestRun. Reihenfolge wie in der Detailansicht: Abschlusszeit, sonst Startzeit,
  absteigend; gleiche Sekunden werden durch SQLite-Einfügereihenfolge aufgelöst.
  Ein neuerer gespeicherter `pending`-Lauf bleibt als solcher erkennbar.
  `none` bedeutet keine gespeicherte Laufzeile, auch bei altem Retest-Zeitstempel.
- Kontakt: eigener Zeitpunkt; Filter `yes`/`no` prüft ausschließlich dessen
  Vorhandensein und ändert kein Urteil.
- Scope: standardmäßig aktive Fälle; `discarded`, `duplicates` und `all` erlauben
  bewusstes Wiederfinden. Neue Verwerfungsgründe und historische Statuswerte
  `duplicate`/`discarded` werden berücksichtigt. `wontfix` bleibt ein eigener
  gespeicherter Status und wird nicht nachträglich als Verwerfen interpretiert.
- Alle Dimensionen gelten gemeinsam mit AND. Alte `status`-/`bucket`-Links und
  `--status` bleiben ausdrücklich gekennzeichnete Diagnosefilter. Scope wird nur
  bei einem alten expliziten Archivfilter ohne Scope zur Kompatibilität ergänzt.
- Globale Dashboardlinks setzen andere Listenfilter zurück und führen genau zu
  der Menge ihres Zählers. Es gibt keinen neuen Zähler für einen Review-Vorrat.

Technisches `fixed` heißt „Kein Nachweis (fixed)“; „Behoben“ bezeichnet ein
aufgezeichnetes manuelles Urteil. Altwerte bleiben sichtbar, ohne manuelle Herkunft,
Entscheidungszeit oder betrachtete Belege zu erfinden. Typ und Schweregrad,
Suchfilter und Seitennavigation bleiben nutzbar.

`app:domain:list` zählt aktive Fälle und manuelle/kontaktierte Teilmengen über
dieselben Filter mit exaktem Domainvergleich. Domain-Export und Betreiber-Priorität
beschreiben ihren gespeicherten Status als solchen; ihre bisherige Auswahl wurde
nicht in neue fachliche Arbeitsregeln umgewandelt.

## Nachweise

Der vollständige DDEV-Lauf nach Integration bestand mit **142 Tests und 1.284
Assertions**. Darin enthalten sind fünf neue HTTP-Abnahmen mit 192 Assertions:
Mischbestand und Leseerhalt, Archiv/Bookmarks, sämtliche Dashboardziele,
Filtererhalt über Pagination/Formulare sowie ungültige Eingaben. Symfony-Container-Lint,
PHP-Syntaxprüfung aller 18 betroffenen PHP-Dateien und `git diff --check` waren erfolgreich.

```bash
ddev exec php vendor/bin/phpunit
ddev exec php bin/console lint:container
```

Die isolierten DB- und CLI-Prüfungen umfassen Mischbestand, abweichende manuelle
und technische Zustände, unbekannte Altherkunft, Archivscope, AND-Kombinationen,
Zeitgleichheit mit gegenläufiger UUID-Reihenfolge, `pending`, reine alte
Retest-Zeitstempel und 5.500 synthetische Fälle. Lesende Projektionen hydrieren
keine ORM-Entitäten und verändern keine fachlichen Daten.

Am lokalen DDEV-Bestand wurden Übersicht, alle neun Dashboardziele und eine
Detailansicht ausschließlich mit GET aufgerufen. Alle antworteten mit HTTP 200;
jede Zielmenge entsprach ihrem Zähler. Der Bestand enthält 5.348 aktive Fälle,
218 zuletzt uneindeutige Beobachtungen, sieben Fälle ohne gespeicherten Lauf und
2.784 Kontaktzeitpunkte. Neue manuelle Urteile sind noch nicht aufgezeichnet;
historische Werte wurden nicht übernommen oder umgedeutet.

Chromium zeigte die lokale Übersicht bei 1.440 Pixel Breite. Bei 390 Pixel Breite
bleibt die Dokumentbreite nach der Korrektur genau 390 Pixel; ausschließlich die
Tabelle scrollt horizontal (699 Pixel Inhalt in 320 Pixel sichtbarer Breite).
Es wurden keine gespeicherten Ziel-URLs geöffnet.

Ein lesender Vorher-/Nachher-Vergleich aller acht Datenbanktabellen einschließlich
Migrationstabelle ergab identische Zeilenzahlen und Inhalts-Hashes. Alle fünf
vorhandenen Artefaktdateien haben weiterhin identische SHA-256-Hashes. Es fanden
weder eine Bestandsbereinigung noch eine schreibende Übernahme statt.

| Anforderung | Nachweis |
| --- | --- |
| F02 – konsistente Übersicht/Filter/Seitennavigation | Gemeinsame Read-Abfrage, Mischbestands- und Paginationprüfungen, neun echte Zählerziele. |
| F06 – Beobachtung und Bewertung unterscheiden | Technisches `fixed` bleibt unabhängig vom manuellen Urteil; Altwerte behalten unbekannte Herkunft. |
| F05 – Nutzinformationen erhalten | GET-/CLI-Snapshots bleiben unverändert; 3a-Formulare und Bewertungshistorie bleiben bestehen. |
| N02 – isolierte Tests | Temporäre SQLite-/Artefaktablage durch bestehenden Testbootstrap; synthetische Fälle und simulierte Browserantworten. |

## Grenzen

Keine Schema- oder Datenmigration war erforderlich. Neue Sichtungsgründe,
Erledigen/Zurückstellen von Hinweisen, Bildvergleich und Resolve-Navigation bleiben
spätere Pakete. Die bestehende technische Arbeitsauswahl wurde nicht erweitert.
Der verbleibende Nachweis eines vollständig frischen isolierten DDEV-Aufbaus
gehört weiterhin zu Abschnitt 4.
