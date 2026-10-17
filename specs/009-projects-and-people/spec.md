# Feature Specification: Projekte und Zuordnung von Buchungszeilen

**Feature Branch**: `feature/projects-and-people`

**Created**: 2026-10-17

**Status**: Etappe 1 in Arbeit

**Input**: Kleine Firmen steuern ihre Projekte heute oft in einer Tabellenkalkulation neben der Buchhaltung. Sie tippen Ist-Werte ab und verweisen von Hand auf Buchungsnummern. Gäld soll Projekte selbst führen und jede Buchungszeile einem oder mehreren Projekten und optional einer Person zuordnen können. Die Zuordnung ist eine reine Auswertungsangabe: Sie verändert weder Saldi noch MWST noch Abschluss und darf deshalb jederzeit geändert werden, auch in gesperrten, abgerechneten oder archivierten Perioden.

Diese Spezifikation beschreibt Etappe 1 (Fundament). Die folgenden Etappen bauen darauf auf und erhalten eigene Abschnitte, sobald sie anstehen:

| Etappe | Inhalt |
|---|---|
| 1 | Projekte, Zuordnungstabelle, Bedienung im Journal, Projektauswertung, API |
| 2 | Projektübersicht mit Pipeline und Termin-Ampel, Projekt am Rechnungskopf, Vorschläge aus Bankregeln und Kontakten, Liste „nicht zugeordnet“ |
| 3 | Budgetgruppen über mehrere Konten, Plan gegen Ist mit Hochrechnung |
| 4 | Anschaffungen je Person mit Status, Rückgabe- und Garantiefrist |
| 5 | Verteilschlüssel und Steuerungsseite für die Inhaber |

## Entscheide

| Frage | Entscheid |
|---|---|
| Wo liegt die Zuordnung? | In einer eigenen Tabelle neben den Buchungszeilen. `transaction_lines` wird nie beschrieben. |
| Wann ist die Zuordnung änderbar? | Immer, ohne Ausnahme, auch bei gesperrten, abgerechneten, abgeschlossenen und archivierten Buchungen. Jede Änderung wird protokolliert. |
| Was ist ein Projekt? | Eine erweiterte Kostenstelle mit Art (Bereich, Kundenprojekt, intern), Status (Pipeline, Möglich, In Arbeit, Erledigt, Verloren), Kunde, verantwortlicher Person, Zieltermin, erwartetem Betrag und Notiz. Bereiche sind übergeordnete Projekte. |
| Wer ist eine Person? | Ein Mitarbeitender aus dem Lohnmodul. |
| Aufteilung | Eine Zeile kann mit Prozentanteilen auf mehrere Projekte und Personen verteilt werden. Die Summe ist höchstens 100 %; ein Rest gilt als nicht zugeordnet. |
| Einschalten | Neues Modul `projects` pro Organisation, Standard aus. Organisationen ohne das Modul sehen keine Änderung. |
| Spesen auf dem Lohnausweis | Nicht Teil dieser Spezifikation. Die Tabelle lässt später ein Kategoriefeld und eine Sperre nach Ausstellung des Lohnausweises zu, ohne Umbau. |

## User Scenarios & Testing *(mandatory)*

### User Story 1: Projekte führen (Priority: P1)

Eine Inhaberin legt Bereiche und darunter Kundenprojekte an, mit Status, Kunde, Verantwortlicher, Zieltermin und erwartetem Betrag. Sie ändert den Status im Lauf des Projekts.

**Why this priority**: Ohne Projekte gibt es nichts zuzuordnen.

**Independent Test**: Ein Bereich mit zwei Kundenprojekten wird angelegt, ein Projekt wechselt den Status, die Liste zeigt die Hierarchie und den Status.

**Acceptance Scenarios**:

1. **Given** das Modul `projects` ist eingeschaltet, **When** ein Projekt mit Art, Status, Kunde, Verantwortlicher, Zieltermin und erwartetem Betrag gespeichert wird, **Then** erscheint es in der Projektliste unter seinem Bereich.
2. **Given** ein Projekt mit zugeordneten Zeilen, **When** es gelöscht werden soll, **Then** wird das abgelehnt und Deaktivieren angeboten.
3. **Given** das Modul ist ausgeschaltet, **When** die Projektseite aufgerufen wird, **Then** antwortet Gäld wie bei jedem ausgeschalteten Modul.
4. **Given** ein Projekt soll sein eigener Vorfahre werden, **When** gespeichert wird, **Then** wird das abgelehnt.

### User Story 2: Zeilen zuordnen beim Erfassen (Priority: P1)

Wer eine Buchung im Journal erfasst oder ändert, wählt pro Zeile ein Projekt oder verteilt die Zeile auf mehrere Projekte.

**Why this priority**: Das ersetzt die Handverweise auf Buchungsnummern.

**Independent Test**: Eine Buchung mit einer auf zwei Projekte verteilten Aufwandszeile wird erfasst; die Projektauswertung zeigt beide Anteile.

**Acceptance Scenarios**:

1. **Given** eine neue Buchung, **When** einer Zeile ein Projekt gewählt wird, **Then** wird die Zeile zu 100 % diesem Projekt zugeordnet.
2. **Given** eine Zeile mit Aufteilung 60 % und 40 %, **When** gespeichert wird, **Then** sind beide Anteile gespeichert und die Auswertung rechnet mit Rappenausgleich auf dem letzten Anteil.
3. **Given** Anteile über 100 %, ein inaktives Projekt oder ein Projekt einer fremden Organisation, **When** gespeichert wird, **Then** wird das mit einer verständlichen Meldung abgelehnt.

### User Story 3: Zuordnung jederzeit ändern (Priority: P1)

Eine Buchung aus einem abgerechneten Quartal oder eine Rechnungsbuchung soll nachträglich einem Projekt zugeordnet werden. Der Dialog „Zuordnung ändern“ ist bei jeder Buchung verfügbar, auch wenn die Buchung selbst gesperrt ist.

**Why this priority**: Die Nachpflege des laufenden Jahres und alle Fachmodul-Buchungen gehen nur so.

**Independent Test**: Eine archivierte Buchung erhält eine Zuordnung; Archiv-Prüfsumme, Saldi und MWST bleiben unverändert, und die Änderung steht im Protokoll.

**Acceptance Scenarios**:

1. **Given** eine Buchung in einem abgerechneten MWST-Quartal, einem abgeschlossenen Jahr oder ein archivierter Beleg, **When** die Zuordnung geändert wird, **Then** gelingt das und die Buchung selbst bleibt unverändert.
2. **Given** eine Zuordnungsänderung, **When** das Protokoll geöffnet wird, **Then** stehen alter und neuer Stand, Person und Zeitpunkt darin.
3. **Given** eine gefilterte Journalliste, **When** mehrere Buchungen ausgewählt und einem Projekt zugeordnet werden, **Then** erhalten deren Erfolgszeilen die Zuordnung; Zeilen mit bestehender Aufteilung werden nur nach ausdrücklicher Bestätigung überschrieben.

### User Story 4: Zuordnung geht nicht verloren (Priority: P1)

Ändern, Storno, Korrektur und Verbuchen eines Entwurfs übernehmen die Zuordnung.

**Why this priority**: Gäld legt beim Ändern die Zeilen neu an. Ohne Übernahme wäre jede Korrektur ein stiller Datenverlust.

**Independent Test**: Eine zugeordnete Buchung wird geändert, storniert und über den Korrekturweg ersetzt; alle Folgebuchungen tragen dieselbe Zuordnung, die Stornobuchung mit umgekehrtem Vorzeichen.

**Acceptance Scenarios**:

1. **Given** eine zugeordnete Buchung, **When** sie storniert wird, **Then** trägt die Stornobuchung dieselbe Zuordnung, sodass das Projekt danach netto null zeigt.
2. **Given** eine zugeordnete Buchung, **When** sie über die Oberfläche geändert wird, **Then** übernimmt jede Zeile die Zuordnung, die der Dialog mitsendet.
3. **Given** eine Änderung über die API ohne Angabe von Zuordnungen, **When** sich die Zeilen eindeutig den alten Zeilen zuordnen lassen, **Then** wird die Zuordnung übernommen; **Otherwise** kommt die Buchung auf die Liste „Zuordnung prüfen“, und die Zuordnung der nicht eindeutigen Zeilen wird nicht geraten.
4. **Given** ein Korrekturweg mit Storno und Ersatz, **When** die Entwürfe angelegt werden, **Then** tragen beide die Zuordnung der Originalbuchung.

### User Story 5: Projektauswertung (Priority: P2)

Pro Projekt und Zeitraum zeigt Gäld Ertrag, Aufwand und Deckungsbeitrag, mit aufgerollten Unterprojekten, ohne Abschlussbuchungen, als Bildschirm, CSV und PDF.

**Why this priority**: Das ist der erste sichtbare Nutzen der Zuordnung.

**Independent Test**: Ein Bereich mit zwei Projekten zeigt die Summe beider; eine Jahresabschlussbuchung im Zeitraum verändert das Ergebnis nicht.

**Acceptance Scenarios**:

1. **Given** Zeilen auf zwei Unterprojekten, **When** der Bereich ausgewertet wird, **Then** enthält das Ergebnis beide.
2. **Given** eine Abschlussbuchung im Zeitraum, **When** ausgewertet wird, **Then** bleibt sie unberücksichtigt.
3. **Given** eine Person ohne Recht `projects.view`, **When** sie die Auswertung aufruft, **Then** wird das abgelehnt.

### User Story 6: Gleiches über die API (Priority: P2)

Ein API-Client liest Projekte und Zuordnungen, legt Projekte an und ändert Zuordnungen unter denselben Regeln wie die Oberfläche.

**Acceptance Scenarios**:

1. **Given** ein Token mit `projects.allocate`, **When** die Zuordnungen einer Buchung ersetzt werden, **Then** gelingt das auch für gesperrte Buchungen; derselbe Idempotency-Key liefert dieselbe Antwort.
2. **Given** eine Buchung beim Lesen, **When** sie Zuordnungen hat, **Then** stehen sie pro Zeile in der Antwort.

### Edge Cases

- Eine Zeile wird beim Ändern gelöscht: ihre Zuordnung verschwindet mit ihr; der letzte Stand bleibt in der Versionstabelle der Buchung.
- Eine Buchung wird gelöscht: die Zuordnungen verschwinden mit den Zeilen, ihr letzter Stand bleibt in der Version `deleted`.
- Ein Projekt wird deaktiviert: bestehende Zuordnungen bleiben und zählen in der Auswertung; neue Zuordnungen sind nicht möglich.
- Ein Mitarbeitender tritt aus: bestehende Zuordnungen bleiben.
- Zeilen auf Bilanzkonten: Zuordnung ist erlaubt, die Projektauswertung zählt aber nur Erfolgskonten.
- Die bisherige Spalte `transaction_lines.cost_center_id`: wird von Gäld heute nicht beschrieben. Bestehende Werte werden einmalig als 100 %-Zuordnung übernommen; die Spalte bleibt unberührt.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Gäld MUSS Projekte als erweiterte Kostenstellen mit Art, Status, Kunde, verantwortlicher Person, Zieltermin, erwartetem Betrag und Notiz führen.
- **FR-002**: Zuordnungen MÜSSEN in einer eigenen Tabelle liegen; keine Funktion dieses Vorhabens darf `transaction_lines`, `journal_entries` oder `vat_entries` beschreiben.
- **FR-003**: Eine Zuordnung MUSS Projekt und/oder Person mit einem Anteil in Prozent tragen; die Summe pro Zeile darf 100 % nicht übersteigen.
- **FR-004**: Zuordnungen MÜSSEN unabhängig vom Sperrzustand der Buchung änderbar sein.
- **FR-005**: Jede Änderung einer Zuordnung MUSS im Aktivitätsprotokoll stehen.
- **FR-006**: Verbuchen, Ändern, Storno und Korrekturweg MÜSSEN Zuordnungen übernehmen; nicht eindeutige Fälle MÜSSEN auf der Liste „Zuordnung prüfen“ erscheinen statt geraten zu werden.
- **FR-007**: Saldenliste, Erfolgsrechnung, Bilanz, MWST-Bericht, MWST-Abrechnung und Jahresabschluss MÜSSEN mit und ohne Zuordnungen identische Ergebnisse liefern.
- **FR-008**: Die Projektauswertung MUSS Unterprojekte aufrollen und Abschlussbuchungen ausklammern.
- **FR-009**: Neue Rechte `projects.view`, `projects.manage`, `projects.allocate`.
- **FR-010**: Alles ist hinter dem Modul `projects` verborgen; ohne Modul ändern sich weder Oberfläche noch API-Antworten.
- **FR-011**: Die API MUSS Projekte und Zuordnungen lesen und schreiben können; Schreibaufrufe brauchen einen Idempotency-Key.

### Key Entities

- **Projekt** (`cost_centers`, erweitert): Code, Name, übergeordnetes Projekt, Art, Status, Kunde, Verantwortliche Person, Zieltermin, erwarteter Betrag, Notiz, aktiv.
- **Zuordnung** (`line_allocations`, neu): Buchungszeile, Projekt, Person, Anteil, Herkunft.
- **Prüfeintrag** (`allocation_reviews`, neu): Buchung, deren Zuordnung nach einer Änderung nicht eindeutig übernommen werden konnte.

## Success Criteria *(mandatory)*

- **SC-001**: Der Vergleichstest über alle Berichte zeigt mit und ohne Zuordnungen keine Abweichung.
- **SC-002**: Jeder der Wege Verbuchen, Ändern, Storno, Korrekturweg hat einen Test, der die Übernahme der Zuordnung belegt.
- **SC-003**: Eine archivierte Buchung erhält eine Zuordnung, und ihre Archivprüfung bleibt gültig.
- **SC-004**: Mit ausgeschaltetem Modul laufen alle bestehenden Testsuiten unverändert grün.

---

# Etappe 2: Projektübersicht und Rechnungen

**Status**: in Arbeit

**Ziel**: Das Blatt „Projekte“ wird überflüssig. Die Übersicht zeigt pro Projekt dieselben Kennzahlen wie das Excel, die Ist-Werte kommen aus der Buchhaltung, Eingang und Offen aus den Rechnungen in Gäld.

## Entscheide Etappe 2

| Frage | Entscheid |
|---|---|
| Netto | Ertrag minus Aufwand aus den Zuordnungen (wie die Projektauswertung). Das entspricht „Brutto ohne MWST minus Fremdkosten minus Abzüge“. |
| Erwartet | `expected_amount` bleibt der Planwert netto. Ein neues Feld `billing_month` hält den geplanten Rechnungsmonat. |
| Eingang und Offen | Aus den Rechnungen mit diesem Projekt am Kopf: Eingang = erfasste Zahlungen, Offen = Total minus Zahlungen, ohne Entwürfe und stornierte Rechnungen. |
| Zahlungsstatus | bezahlt (Offen ≤ 1.00), teilweise, offen; ohne Rechnung leer. |
| Termin-Ampel | erledigt (Status Erledigt oder Verloren), kein Termin, überfällig (Zieltermin vorbei), kritisch (≤ 14 Tage), im Plan. |
| Projekt am Rechnungskopf | Eigene Tabelle `invoice_projects` (eine Zeile pro Rechnung), damit das Projekt auch bei archivierten Rechnungen änderbar bleibt, vorbelegt mit dem offenen Projekt des Kunden (Kunde am Projekt, Status nicht Erledigt oder Verloren), wenn es genau eines gibt. Beim Verbuchen erhalten die Ertragszeilen die Zuordnung. Gutschriften übernehmen das Projekt der Rechnung. |
| Projekt einer verbuchten Rechnung ändern | Jederzeit möglich; die Ertragszeilen der Rechnungsbuchung werden umgehängt, die Buchung bleibt unverändert. |
| Vorschläge | Liste „nicht zugeordnet“ mit Erfolgszeilen ohne Zuordnung im Zeitraum. Vorschlag aus (1) Rechnung mit Projekt, (2) dem einzigen offenen Projekt des Kunden, (3) dem Projekt, dem dieselbe Kombination aus Konto und Buchungstext zuletzt zugeordnet wurde. Übernehmen einzeln oder alle. |
| Bankregeln | Zurückgestellt: In der Instanz laufen keine Bankbewegungen über Gäld, Buchungen kommen über die API. Wird nachgeholt, sobald Bankimporte genutzt werden. |
| Excel-Import | Einmalig über ein lokales Skript ausserhalb des Repositorys, das über die API Projekte anlegt und belegte Buchungen zuordnet; mit Probelauf. |

## User Stories Etappe 2

### User Story 7: Projektübersicht (P1)

Die Liste zeigt pro Projekt Bereich, Verantwortlich, Status, Zieltermin mit Ampel, Rechnungsmonat, Erwartet, Ertrag, Aufwand, Netto, Eingang, Offen und Zahlungsstatus, dazu Summen nach Status und nach Bereich, filterbar nach Jahr, Status und Bereich.

**Acceptance Scenarios**:

1. **Given** ein Projekt mit zugeordnetem Ertrag 1000 und Aufwand 200, **When** die Übersicht geöffnet wird, **Then** zeigt sie Netto 800.
2. **Given** eine Rechnung über 1081 mit einer Zahlung über 500 am Projekt, **Then** Eingang 500, Offen 581, Zahlungsstatus teilweise.
3. **Given** ein Projekt mit Zieltermin in 10 Tagen und Status In Arbeit, **Then** Ampel kritisch; mit Status Erledigt **Then** erledigt.
4. **Given** Projekte in zwei Bereichen, **Then** stimmen die Bereichssummen mit der Summe ihrer Projekte überein.

### User Story 8: Projekt auf der Rechnung (P1)

**Acceptance Scenarios**:

1. **Given** ein Kunde mit genau einem offenen Projekt, **When** eine Rechnung für ihn angelegt wird, **Then** ist dieses Projekt vorbelegt; bei mehreren bleibt das Feld leer.
2. **Given** eine Rechnung mit Projekt, **When** sie verbucht wird, **Then** sind ihre Ertragszeilen zu 100 % dem Projekt zugeordnet, Debitoren- und MWST-Zeile nicht.
3. **Given** eine verbuchte Rechnung, **When** ihr Projekt geändert wird, **Then** hängen die Ertragszeilen am neuen Projekt, die Buchung und ihre Revision bleiben gleich.
4. **Given** eine Gutschrift zu einer Rechnung mit Projekt, **Then** trägt sie dasselbe Projekt.
5. **Given** das Modul ist aus, **Then** zeigt das Rechnungsformular kein Projektfeld und das Verbuchen bleibt wie bisher.

### User Story 9: Nachpflegen mit Vorschlägen (P2)

**Acceptance Scenarios**:

1. **Given** Erfolgszeilen ohne Zuordnung, **When** die Liste geöffnet wird, **Then** steht bei jeder Zeile ein Vorschlag, wenn es einen gibt, mit seiner Quelle.
2. **Given** markierte Vorschläge, **When** übernommen wird, **Then** sind die Zeilen zugeordnet und verschwinden aus der Liste.

## Requirements Etappe 2

- **FR-012**: Übersicht mit den Kennzahlen aus User Story 7, CSV-Export.
- **FR-013**: Tabelle `invoice_projects` und Spalte `cost_centers.billing_month`, beide optional; ohne Modul ungenutzt. Rechnungen selbst werden nicht beschrieben.
- **FR-014**: Ein Projekt mit Rechnungen oder Kundenbezug lässt sich nicht löschen.
- **FR-015**: Jede Übernahme von Vorschlägen ist protokolliert wie jede andere Zuordnung.

---

# Etappe 3: Plan gegen Ist

**Status**: in Arbeit

**Ziel**: Das Blatt „Interne Aufwände“ wird überflüssig. Kostenblöcke fassen mehrere Konten zusammen, zum Beispiel „Software 6031 bis 6035“. Der Plan wird einmal erfasst, das Ist kommt laufend aus der Buchhaltung.

## Entscheide Etappe 3

| Frage | Entscheid |
|---|---|
| Wo liegen die Kostenblöcke? | Neue Tabellen `budget_groups` (pro Jahr) und `budget_group_accounts`. Die bestehenden Budgets pro Konto bleiben unverändert. |
| Plan | Betrag am Kostenblock; fehlt er, gilt die Summe der Kontobudgets des Jahres (Monatsbetrag mal 12). |
| Ist | Saldo der Konten des Blocks im Jahr bis heute, nur verbuchte Buchungen, ohne Abschlussbuchungen. Was einem Kundenprojekt zugeordnet ist, zählt nicht als interner Aufwand und wird separat ausgewiesen. |
| Hochrechnung | Laufende Kosten: Ist hochgerechnet auf das ganze Jahr. Einmalige Ausgaben: das Ist. Liegt die Hochrechnung über dem Plan, wird der Block markiert. |
| Konten ohne Block | Erfolgskonten mit Aufwand im Jahr, die keinem Block angehören, erscheinen in einer eigenen Liste, damit nichts übersehen wird. |
| Folgejahr | „Vorjahr übernehmen“ kopiert die Blöcke samt Konten und Plan. |
| Verteilung auf Personen | Folgt in Etappe 5 (Tragschlüssel und Gutschrift pro Block). |

## User Story 10: Plan gegen Ist (P1)

**Acceptance Scenarios**:

1. **Given** ein Block „Software“ mit den Konten 6031 und 6034, Plan 1800, gebucht 600 und 300, **When** die Ansicht geöffnet wird, **Then** zeigt sie Ist 900 und Rest 900.
2. **Given** ein Block ohne Planbetrag und Kontobudgets von monatlich 100 und 50, **Then** ist der Plan 1800.
3. **Given** ein Aufwand von 200, davon 50 % einem Kundenprojekt zugeordnet, **Then** ist das interne Ist 100 und die Spalte „davon Kundenprojekte“ zeigt 100.
4. **Given** der 1. Juli und ein laufender Block mit Ist 1000, **Then** beträgt die Hochrechnung rund 2000; bei einmaliger Ausgabe 1000.
5. **Given** ein Aufwandskonto mit Buchungen, das keinem Block angehört, **Then** erscheint es unter „Ohne Kostenblock“.
6. **Given** Blöcke im Vorjahr, **When** „Vorjahr übernehmen“ gewählt wird, **Then** stehen dieselben Blöcke im gewählten Jahr, bereits vorhandene werden nicht doppelt angelegt.

## Requirements Etappe 3

- **FR-016**: Kostenblöcke anlegen, ändern, löschen (Recht `projects.manage`), ansehen (Recht `projects.view`); ein Konto gehört pro Jahr höchstens einem Block an.
- **FR-017**: CSV-Export und API zum Lesen und Anlegen von Kostenblöcken (für den einmaligen Import).

---

# Etappe 4: Anschaffungen je Person

**Status**: in Arbeit

**Ziel**: Das Blatt „Anschaffungen“ wird überflüssig. Material und Geräte, die einer Person zugewiesen sind, werden als Artikel geführt; der Nettobetrag wird der Person belastet und fliesst in Etappe 5 ins „Bezogene“.

## Entscheide Etappe 4

| Frage | Entscheid |
|---|---|
| Granularität | Ein Artikel pro Zeile, nicht pro Buchungszeile: Eine Bestellung enthält oft Artikel für verschiedene Personen. Neue Tabelle `acquisitions`, optional mit Verweis auf die Buchung. |
| Personen | Tabelle `acquisition_people` mit Anteil; „beide“ ist zweimal 50 %. Personen sind Mitarbeitende aus dem Lohnmodul. |
| Netto | Brutto mal Menge; mit MWST wird die Vorsteuer abgezogen (Satz am Artikel, Standard 8.1 %), ohne MWST ist Netto gleich Brutto. Der Nettobetrag bleibt überschreibbar. |
| Status | bestellt, geliefert, retourniert. Retournierte Artikel belasten niemanden. |
| Fristen | Rückgabe bis und Garantie bis. Rückgabefristen in den nächsten 14 Tagen werden hervorgehoben, solange der Artikel nicht retourniert ist. |
| Buchhaltung | Wird nicht beschrieben. Der Verweis auf die Buchung dient dem Abgleich. |

## User Story 11: Anschaffungen je Person (P1)

**Acceptance Scenarios**:

1. **Given** ein Artikel über 1550 brutto mit MWST für Person A, **Then** belastet er A mit 1433.86.
2. **Given** ein Artikel über 35.70 für beide, **Then** belastet er A und B mit je 16.51 (Rappenausgleich auf dem letzten Anteil).
3. **Given** ein retournierter Artikel, **Then** belastet er niemanden.
4. **Given** eine Rückgabefrist in 10 Tagen, **Then** ist der Artikel markiert; nach Status retourniert nicht mehr.
5. **Given** ein Artikel mit Verweis auf eine Buchung, **Then** führt ein Link zur Buchung.

## Requirements Etappe 4

- **FR-018**: Liste mit Filter nach Jahr, Person und Status, Summen je Person, CSV; Anlegen, Ändern, Löschen mit `projects.manage`, Ansehen mit `projects.view`.
- **FR-019**: API zum Lesen und Anlegen (für den einmaligen Import) und eine Liste der Personen.

---

# Etappe 5: Gründer-Steuerung

**Status**: umgesetzt, Parallelbetrieb mit dem Excel steht aus

**Ziel**: Das Blatt „Steuerung“ wird überflüssig: Beitrag je Person aus den Kundenprojekten, Wirkung der Kostenblöcke, Bezogenes und daraus die Spielmasse, mit und ohne „Möglich“.

## Entscheide Etappe 5

| Frage | Entscheid |
|---|---|
| Wert eines Projekts | Sobald Ertrag gebucht ist: gebuchter Ertrag minus zugeordneter Aufwand. Vorher: erwarteter Betrag minus zugeordneter Aufwand. Verlorene Projekte zählen nicht. |
| Anteile je Person | Tabelle `project_shares`. Ein Projekt ohne eigene Anteile übernimmt die des nächsten Bereichs darüber. Ohne Anteile wird der Wert als „nicht verteilt“ ausgewiesen. |
| Kostenblöcke | Tabelle `budget_group_shares` mit Tragschlüssel und Gutschrift je Person. Basis ist der Plan des Blocks. Ohne Tragschlüssel tragen alle gleich viel; ohne Gutschrift wird niemand entlastet (extern erledigt). |
| Bezogen | Planposten je Person und Jahr (`steering_items`: Lohn, 13. Monatslohn, Arbeitgeberbeiträge, Pauschale, Boni) plus Anschaffungen. Die gebuchten Lohnkosten aus den Lohnabrechnungen stehen zum Vergleich daneben. |
| Sichtbarkeit | Nur Owner (Recht `projects.view` und `organization.delete`), auch für Anteile, Schlüssel und Planposten. |
| Ausgabe | Seite, CSV und PDF; API zum Setzen von Anteilen, Schlüsseln und Planposten für den einmaligen Import. |

## User Story 12: Steuerung (P1)

**Acceptance Scenarios** (durchgerechnetes Beispiel im Test `SteeringTest`):

1. **Given** ein Bereich mit Anteilen 50/50 und darunter ein erledigtes Projekt mit Ertrag 1000 und Aufwand 200, **Then** trägt es je 400 bei.
2. **Given** ein Projekt mit eigenem Anteil 100 % für A und erwartetem Betrag 300, **Then** trägt A 300 bei.
3. **Given** ein Kostenblock mit Plan 1000, A hat die Arbeit gemacht, trägt 30 %, B 70 %, **Then** Wirkung A +700, B −700.
4. **Given** Planposten 2000 für A und eine Anschaffung von 100 für B, **Then** ist das Bezogene 2000 und 100.
5. **Given** ein Admin, **When** er die Steuerung öffnet, **Then** wird das abgelehnt.

## Parallelbetrieb

Einen Monat lang werden Excel und Gäld nebeneinander geführt. Jede Abweichung wird erklärt, bevor das Excel stillgelegt wird. Bekannte Unterschiede beim Start: Gäld rechnet das Ist aus den Buchungen, das Excel aus abgetippten Werten; einzelne Kostenblöcke weichen deshalb ab (zum Beispiel Büromaterial 4010).
