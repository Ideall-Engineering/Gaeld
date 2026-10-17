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
