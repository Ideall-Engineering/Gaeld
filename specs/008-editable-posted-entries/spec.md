# Feature Specification: Verbuchte Buchungen in offenen Perioden bearbeiten

**Feature Branch**: `feature/editable-posted-entries`

**Created**: 2026-10-16

**Status**: Umgesetzt auf `feature/editable-posted-entries`, noch nicht in Produktion

**Input**: Wer bucht, soll einen eben erkannten Fehler in einer verbuchten Buchung direkt beheben können, solange die Periode nicht durch eine MWST-Abrechnung oder einen Jahresabschluss festgehalten ist. Heute ist jede verbuchte Buchung sofort unveränderlich, und jeder Tippfehler erzeugt ein Storno und eine Ersatzbuchung. Die Sperre soll künftig an fachlichen Ereignissen hängen statt am Verbuchen. Jede Änderung bleibt lückenlos nachvollziehbar.

## Entscheide

| Frage | Entscheid |
|---|---|
| Manuelles Sperrdatum | Nein. Gesperrt wird nur durch MWST-Abrechnung, Jahresabschluss und Archivierung. |
| Begründung beim Ändern oder Löschen | Freiwillig. |
| Löschen verbuchter Buchungen in offenen Perioden | Ja, mit vollständigem Protokoll. |
| Ändern in abgerechneten MWST-Perioden ohne MWST-Wirkung | Nein. Eine abgerechnete Periode ist für jede Änderung gesperrt; dort bleibt der geführte Korrekturweg. |
| Standardverhalten | Pro Organisation wählbar, Standard ist `strict` (heutiges Verhalten). |

## User Scenarios & Testing *(mandatory)*

### User Story 1: Fehler direkt beheben (Priority: P1)

Eine Buchhalterin verbucht eine Buchung und bemerkt kurz darauf, dass Konto, MWST-Code, Betrag, Datum oder Text falsch ist. Sie öffnet die Buchung, ändert sie und speichert. Es entsteht keine Gegenbuchung.

**Why this priority**: Das ist der häufigste Grund für Korrekturbuchungen und der Kern des Vorhabens.

**Independent Test**: Eine verbuchte Handbuchung in einem offenen Quartal wird geändert; Saldi, MWST-Bericht und Journal zeigen sofort den neuen Stand, und der alte Stand ist als Version abrufbar.

**Acceptance Scenarios**:

1. **Given** eine verbuchte Handbuchung in einer offenen Periode und eine Organisation im Modus `flexible`, **When** die Buchung geändert wird, **Then** ersetzt der neue Stand den alten, die Version steigt um eins und der alte Stand liegt vollständig in der Versionstabelle.
2. **Given** dieselbe Buchung, **When** sie gespeichert wird, **Then** zeigen Saldobilanz, Erfolgsrechnung, Bilanz und MWST-Bericht ohne Wartezeit den neuen Stand.
3. **Given** eine Buchung mit MWST-Zeilen, **When** sich Betrag oder MWST-Code ändern, **Then** werden die MWST-Einträge vollständig neu gebildet und stimmen mit den Zeilen überein.
4. **Given** eine Organisation im Modus `strict`, **When** eine verbuchte Buchung geändert werden soll, **Then** verhält sich Gäld wie bisher.

### User Story 2: Fehlbuchung löschen (Priority: P1)

Eine irrtümlich doppelt erfasste Handbuchung in einer offenen Periode wird gelöscht.

**Why this priority**: Ohne Löschen bleibt für Doppelerfassungen nur das Storno, das zwei zusätzliche Buchungen hinterlässt.

**Independent Test**: Nach dem Löschen fehlt die Buchung in allen Auswertungen, ihr letzter Stand ist in der Versionstabelle erhalten und ihre Referenz kann nicht neu vergeben werden.

**Acceptance Scenarios**:

1. **Given** eine verbuchte Handbuchung ohne Verknüpfungen in einer offenen Periode, **When** sie gelöscht wird, **Then** verschwinden Buchung, Zeilen und MWST-Einträge, und ein Versionseintrag der Art `deleted` bleibt bestehen.
2. **Given** eine gelöschte Buchung mit Referenz, **When** eine neue Buchung dieselbe Referenz erhalten soll, **Then** wird das mit einer verständlichen Meldung abgelehnt.
3. **Given** eine Buchung mit Verknüpfung zu Rechnung, Spesen, Lohn, Bank, Abschreibung, Lettrage oder Korrektur, **When** gelöscht werden soll, **Then** wird das abgelehnt und der zuständige Weg genannt.

### User Story 3: Sperren verstehen (Priority: P2)

Wer eine Buchung öffnet, sieht sofort, ob sie änderbar ist, und falls nicht, warum und welcher Weg stattdessen gilt.

**Why this priority**: Ohne sichtbaren Grund wirkt jede Sperre willkürlich.

**Independent Test**: Für jeden Sperrzustand zeigt Journalliste und Detailseite den passenden Hinweis und die passende Aktion.

**Acceptance Scenarios**:

1. **Given** eine Buchung in einem abgerechneten Quartal, **When** sie angezeigt wird, **Then** erscheint ein Schloss mit dem Grund und die Aktion „Korrektur erfassen“.
2. **Given** eine Buchung aus einem Fachmodul, **When** sie angezeigt wird, **Then** nennt der Hinweis das Modul, in dem korrigiert wird.

### User Story 4: Verlauf nachvollziehen (Priority: P2)

Zu jeder geänderten Buchung lassen sich alle früheren Stände mit Zeitpunkt, Person, Quelle und Begründung ansehen und vergleichen. Eine Übersicht zeigt die in den letzten 30 Tagen geänderten und gelöschten Buchungen; die Journalliste verlinkt sie mit der Anzahl.

**Why this priority**: Die Nachvollziehbarkeit ist die Bedingung dafür, dass verbuchte Buchungen überhaupt änderbar sein dürfen.

**Independent Test**: Nach drei Änderungen zeigt die Detailseite vier Stände, und die Unterschiede zwischen zwei Ständen sind je Zeile erkennbar.

**Acceptance Scenarios**:

1. **Given** eine mehrfach geänderte Buchung, **When** der Verlauf geöffnet wird, **Then** sind alle Stände in zeitlicher Folge mit Unterschieden sichtbar.
2. **Given** gelöschte Buchungen, **When** die Änderungsübersicht geöffnet wird, **Then** sind sie mit ihrem letzten Stand auffindbar.

### User Story 5: Gleiches über die API (Priority: P2)

Ein berechtigter API-Client ändert oder löscht verbuchte Buchungen unter denselben Regeln wie die Weboberfläche.

**Independent Test**: Ein `PATCH` mit veralteter Version wird mit 409 abgelehnt, mit aktueller Version ausgeführt; eine Wiederholung mit demselben Idempotency-Key erzeugt keine zweite Version.

### User Story 6: Korrekturweg entlasten (Priority: P3)

Für gesperrte Perioden bleibt der geführte Korrekturweg, wird aber einfacher: Das Korrekturdatum schlägt das erste offene Datum vor, eine bereits korrigierte Ersatzbuchung lässt sich erneut korrigieren, und Storno-Paare lassen sich im Journal ausblenden.

### Edge Cases

* Das Datum wird aus einer offenen in eine abgerechnete MWST-Periode verschoben oder umgekehrt: abgelehnt, sobald altes **oder** neues Datum gesperrt ist.
* Das Datum wird in ein abgeschlossenes Geschäftsjahr verschoben: abgelehnt. Ein Datum ohne erfasstes Geschäftsjahr wird behandelt wie beim Verbuchen.
* Eine Buchung ohne MWST-Zeilen liegt in einer abgerechneten Periode: gesperrt, obwohl heute nur MWST-Einträge den Sperrschutz auslösen.
* Zwei Änderungen derselben Buchung gleichzeitig (Web und API): die zweite scheitert mit Versionskonflikt.
* Eine Zeile auf einem Bankkonto ändert ihren Betrag: erlaubt, aber mit deutlicher Warnung, dass der Saldo vom Kontoauszug abweichen kann.
* Die Buchung wurde storniert oder ist selbst ein Storno, eine Ersatzbuchung oder eine Eröffnungsbuchung (`OPENING-*`): nicht direkt änderbar.
* Zeilen tragen einen Lettrage-Schlüssel: nicht änderbar, bis die Lettrage aufgehoben ist.
* Archivierte Buchungen: weder Kopf noch Zeilen änderbar, auch nicht über Zeilen-Modelle.

## Requirements *(mandatory)*

### Functional Requirements

* **FR-001**: Jede Organisation hat einen Modus `strict` oder `flexible` für verbuchte Buchungen. Standard ist `strict`; dann gilt das heutige Verhalten unverändert.
* **FR-002**: Ein zentraler Dienst bestimmt für jede Buchung genau einen Bearbeitungszustand mit Grund: `open`, `vat_locked`, `fiscal_year_closed`, `archived`, `source_managed`, `correction_bound`, `system_generated`, `lettered`.
* **FR-003**: Im Modus `flexible` sind verbuchte Buchungen im Zustand `open` änderbar und löschbar. Alle anderen Zustände sperren.
* **FR-004**: Vor jeder Änderung wird der vollständige bisherige Stand (Kopf, Zeilen, MWST-Einträge) als Version gespeichert, mit Person, Quelle, Zeitpunkt, optionaler Begründung und Art (`updated`, `deleted`).
* **FR-005**: Versionen werden nie automatisch bereinigt und überdauern das Löschen der Buchung.
* **FR-006**: Eine Änderung bildet MWST-Einträge neu, leert die Caches für Hauptbuch, Berichte und Dashboard und löst ein Ereignis sowie einen Webhook aus.
* **FR-007**: Die Sperrprüfung berücksichtigt altes und neues Datum und ist unabhängig davon, ob die Buchung MWST-Zeilen hat.
* **FR-008**: Jede Buchung trägt eine Versionsnummer. Web und API lehnen Änderungen auf einer veralteten Version ab.
* **FR-009**: Referenzen gelöschter Buchungen bleiben reserviert.
* **FR-010**: Die API bietet `PATCH /api/v1/journal-entries/{id}` und erweitert `DELETE` auf verbuchte Buchungen, beide mit Idempotency-Key und `If-Match`.
* **FR-011**: Ändern und Löschen verbuchter Buchungen verlangt die Berechtigung `AccountingAmendPosted` (`accounting.amend-posted`), die standardmässig nur Inhaber, Admin und Buchhalter haben. API-Tokens brauchen dieses Recht ausdrücklich.
* **FR-012**: Archivierte Buchungszeilen sind auf Modellebene gegen Änderung und Löschung gesperrt, und die Archiv-Prüfsumme umfasst die Zeilen.
* **FR-013**: Hilfe- und Bestätigungstexte beschreiben das Verhalten je Modus korrekt, in allen vier Sprachen.

### Key Entities

* **Journal Entry Revision**: unveränderlicher Stand einer Buchung vor einer Änderung oder Löschung.
* **Edit State**: abgeleiteter, nicht gespeicherter Bearbeitungszustand einer Buchung mit Grund.
* **Posted Entry Edit Mode**: Einstellung der Organisation.

## Success Criteria *(mandatory)*

### Measurable Outcomes

* **SC-001**: Ein Fehler in einer offenen Periode ist mit einem Speichern behoben, ohne zusätzliche Buchung im Journal.
* **SC-002**: Für jede je verbuchte Buchung lässt sich jeder frühere Stand wiederherstellen, auch nach dem Löschen.
* **SC-003**: Keine Änderung erreicht eine abgerechnete MWST-Periode oder ein abgeschlossenes Jahr; belegt durch Tests für jede Richtung des Datumswechsels.
* **SC-004**: Im Modus `strict` verhalten sich alle bestehenden Tests unverändert.

## Assumptions

* Die Schweizer Geschäftsbücherverordnung verlangt Nachvollziehbarkeit, keine Stornopflicht in offenen Perioden. Das Versionsprotokoll ist deshalb Teil der Buchführung und wird wie diese aufbewahrt.
* Buchungen aus Fachmodulen werden weiterhin in ihrem Modul korrigiert. Eine Rückwirkung von Journaländerungen auf Rechnungen, Spesen oder Löhne ist nicht Teil dieses Vorhabens.
* Ein manuelles Sperrdatum ist ausdrücklich nicht gewünscht.
