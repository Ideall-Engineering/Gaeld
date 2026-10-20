# Feature Specification: Offerten und Rechnungen überarbeiten

**Feature Branch**: `deployment` (etappenweise)

**Created**: 2026-10-20

**Status**: In Arbeit

**Input**: Offerten (seit .142) und Rechnungen sollen im Alltag ohne Nacharbeit brauchbar sein: richtige Ansprechperson, Einheiten statt Fliesstext, Auftrag direkt anlegen, ein PDF wie aus dem bisherigen Fakturaprogramm. Dazu zwei Fehler, die beim Arbeiten mit Kontakten und mit der API auffielen.

## Entscheide

| Frage | Entscheid |
|---|---|
| Kundennummer | Keine. Das PDF zeigt nur die Dokumentnummer. |
| Produkte und Dienstleistungen | Kein Katalog im Formular nötig; die erste Spalte heisst „Beschreibung“. |
| Einheit | Freies Textfeld je Zeile mit Vorschlägen (Stunde, Pauschal, Stück, Tag, Lektion). Leer bleibt alles wie heute. |
| Ansprechperson | Wählbar auf Offerte, Rechnung und Abo-Rechnung, vorbelegt mit der Hauptansprechperson. Die Umwandlung einer Offerte übernimmt sie. Im PDF steht sie unter dem Firmennamen. |
| Auftrag | Bleibt freiwillig. Neu kann er im Formular angelegt werden (aus einer Offerte „Offen“, aus einer Rechnung „In Arbeit“). |
| „Leistung/Lieferung“, Einleitung | Freie Textfelder auf Offerte und Rechnung. |
| Reihenfolge | Etappe 1 Fehler, Etappe 2 bis 4 Ansprechperson, Einheit, Auftrag, Etappe 5 PDF. |

## Etappe 1: Fehler

### US1 Ein geleertes Kontaktfeld wird geleert (P1)

Wer im Kontaktformular ein Feld leert (MWST-Nummer, E-Mail, IBAN, …) und speichert, findet es danach leer. Felder, die das Formular nicht sendet, bleiben unverändert.

### US2 MWST per API benennen (P1)

`GET /api/v1/vat-rates` listet die Sätze der Organisation (Standard zuerst). Offerten- und Rechnungszeilen nehmen neben `vat_rate_id` auch `vat_rate_code` an (`NORMAL`, `EXEMPT`, …, Gross-/Kleinschreibung egal). Ist die Organisation MWST-pflichtig (MWST-Nummer hinterlegt) und nicht im Reverse-Charge, braucht jede Positionszeile einen Satz; sonst antwortet die API mit 422 statt still ohne MWST zu speichern. Unbekannte Codes sind ebenfalls 422.

## Etappe 2 bis 4

### US3 Ansprechperson (P1)

`contact_person_id` auf Offerten, Rechnungen und Abo-Rechnungen; muss zum Kunden gehören. Name, Funktion und Abteilung kommen in den Kundenschnappschuss. API liest und schreibt das Feld.

### US4 Einheit (P2)

`unit` auf Offerten- und Rechnungszeilen, max. 20 Zeichen, übernommen bei Umwandlung, Duplikat und Abo. PDF: „1'200.00 / Pauschal“.

### US5 Auftrag im Formular anlegen (P2)

Knopf „Neuer Auftrag“ neben der Auswahl: Nummer vorgeschlagen, Name aus dem Titel, Kunde übernommen.

### US6 Leistung/Lieferung und Einleitung (P2)

Zwei freie Texte auf Offerte und Rechnung, übernommen bei der Umwandlung.

## Etappe 5: PDF

Gemeinsames Layout für Offerte und Rechnung: Absender oben rechts, Empfänger links mit Ansprechperson, rechts Nummer, Datum, „Gültig bis“ bzw. „Fällig am“, „Leistung/Lieferung“; Titel und Einleitung; Tabelle Beschreibung, MWST, Menge, Preis / Einheit, Total, erste Beschreibungszeile fett; Totals „Total exkl. MWST“, „MWST 8.1%“, „Total CHF“; auf Rechnungen die Bankverbindung links daneben. Schweizer Zahlenformat (1'234.50), keine leere Folgeseite, Zahlungszeile nur auf Rechnungen.
