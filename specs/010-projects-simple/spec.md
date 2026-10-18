# Feature Specification: Aufträge einfach führen

**Feature Branch**: `feature/projects-simple`

**Created**: 2026-10-18

**Status**: In Arbeit

**Input**: Spec 009 hat Projekte, Zuordnung, Kostenblöcke, Anschaffungen und Steuerung gebaut. Im Alltag soll ein Geschäftsführer, der Gäld nur gelegentlich öffnet, drei Fragen ohne Einarbeitung beantworten können:

1. Hat jeder Beleg seine Zuordnung?
2. Wie lukrativ war ein Auftrag, wie viel bleibt netto?
3. Wie viel hat jede Person erwirtschaftet, als Grundlage für einen leistungsbezogenen Lohn?

Das Datenmodell aus Spec 009 trägt das bereits. Diese Spezifikation ändert Begriffe, Einstieg und Bedienung und ergänzt den Stand der Zuordnung. Es gibt keine neue Tabelle und keine Migration.

## Entscheide

| Frage | Entscheid |
|---|---|
| Begriffe | Was bisher „Bereich“ hiess, heisst „Projekt“ (z.B. KI-Kurse). Was bisher „Projekt“ hiess, heisst „Auftrag“. „Intern“ bleibt „Intern“. Nur die Anzeige ändert sich, die Daten nicht. Das Modul heisst weiter „Projekte“. |
| Wann ist ein Beleg erledigt? | Eine Ertrags- oder Aufwandszeile ist erledigt, wenn sie einem Auftrag zugeordnet ist (auch intern) oder wenn ihr Konto in einem Kostenblock des Jahres liegt („allgemeine Kosten“). |
| Was zählt nicht? | Löhne und Sozialleistungen (Konten 5…, Personalaufwand) kommen aus der Lohnabrechnung und werden ausgeblendet, ausser ihr Konto liegt in einem Kostenblock. Beide Hälften jeder Stornierung und jeder verbuchten Korrektur werden ausgeblendet, wie im Journal mit „Korrekturen ausblenden“. Abschlussbuchungen zählen nie. |
| Allgemeine Kosten | Laufende Kosten (Miete, Software, Versicherung) gehören in einen Kostenblock. Wer sie trägt, legt der Kostenblock fest: gemeinsam nach Schlüssel oder eine Person allein, wie im Excel. |
| Einzelner allgemeiner Beleg | Ein Ertrag ohne Auftrag (z.B. Kursgewinn) oder ein einmaliger Aufwand auf einem Konto ohne Kostenblock wird dem internen Auftrag „Allgemein“ zugeordnet. Gäld legt ihn beim ersten Gebrauch an. |
| Leistung je Person | Netto je Auftrag nach dem Anteil der Personen, dazu der Anteil an den allgemeinen Kosten nach Kostenblock, abzüglich Bezogenem. So rechnet die Steuerung aus Spec 009 schon; neu ist die Aufschlüsselung je Auftrag und Person. |
| Sichtbarkeit | Anteile und Personenseite bleiben den Inhabern vorbehalten (`steer`), wie in Spec 009. |

## User Stories

### US1 Stand der Zuordnung auf einen Blick (P1)

Als Geschäftsführer sehe ich oben auf der Seite „Aufträge“ für das gewählte Jahr, wie viele Belege erledigt sind und wie viele noch offen. Ein Knopf führt direkt zu den offenen Belegen. Sind alle erledigt, steht das ausdrücklich da.

**Akzeptanz**

1. Eine Ertragszeile mit Auftrag zählt als erledigt, ohne Auftrag als offen.
2. Eine Aufwandszeile auf einem Konto eines Kostenblocks des Jahres zählt als allgemein und damit als erledigt.
3. Lohnzeilen (Konto 5… ohne Kostenblock) und beide Hälften einer Stornierung erscheinen weder als offen noch als erledigt; die Zahl der ausgeblendeten Zeilen wird genannt.
4. Die Zahlen stimmen mit der Liste „Zuordnen“ überein.

### US2 Zuordnen als Posteingang (P1)

Als Geschäftsführer arbeite ich die offenen Belege von oben nach unten ab. Pro Beleg wähle ich einen Auftrag (gruppiert nach Projekt, mit Suche) oder „Allgemein“. Vorschläge sind vorausgewählt. Mit einem Knopf übernehme ich alle gewählten.

**Akzeptanz**

1. Die Liste zeigt nur offene Belege (US1). Ein Schalter blendet auch allgemeine Belege ein, um einen davon doch einem Auftrag zuzuordnen.
2. „Allgemein“ bei einem Aufwand fragt: alle Belege dieses Kontos über einen Kostenblock (bestehender oder neuer, nach dem Konto benannt) oder nur diesen Beleg.
3. „Allgemein“ bei einem Ertrag ordnet den Beleg dem internen Auftrag „Allgemein“ zu.
4. Nach dem Übernehmen verschwinden die Belege aus der Liste, der Zähler sinkt.

### US3 Aufträge mit Netto und Marge (P1)

Als Geschäftsführer sehe ich je Projekt seine Aufträge mit Status, Personen, Einnahmen, Ausgaben, Netto und Marge, und je Projekt die Summe. Ein Klick auf einen Auftrag öffnet seine Seite.

**Akzeptanz**

1. Die Seite hat eine einzige Ansicht; die Stammdaten stehen im Bearbeiten-Dialog.
2. Marge ist Netto geteilt durch Einnahmen, leer ohne Einnahmen.
3. Ist noch nichts eingegangen, steht der erwartete Betrag grau daneben.
4. „Neuer Auftrag“ ist der Hauptknopf; „Neues Projekt“ legt eine Gruppe an.

### US4 Seite eines Auftrags (P1)

Als Geschäftsführer sehe ich auf der Seite eines Auftrags Einnahmen, Ausgaben, Netto und Marge, wer daran arbeitet mit welchem Anteil und welchem Netto-Anteil, und jede zugeordnete Buchungszeile mit Link zur Buchung. Bei einem Projekt sehe ich zusätzlich seine Aufträge.

### US5 Personen und Leistung (P1)

Als Inhaber sehe ich je Person: Netto aus Aufträgen, aufgeschlüsselt nach Auftrag mit Anteil; Anteil an den allgemeinen Kosten; Erwirtschaftet; Bezogen; Spielraum für den Leistungslohn.

## Nicht im Umfang

Stundenerfassung, neue Rechte, Änderungen an Buchungen, Datenmigration.
