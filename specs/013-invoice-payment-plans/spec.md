# Feature Specification: Rechnung mit Zahlungsplan

**Feature Branch**: `feature/zahlungsplan`

**Created**: 2026-11-01

**Status**: Entwurf, Entscheide zur Durchsicht

**Input**: Eine Rechnung soll in Raten bezahlt werden können, ohne sie in mehrere Rechnungen aufzuteilen (Variante B). Der Kunde erhält ein Dokument mit Zahlungsplan und je Rate einem QR-Einzahlungsschein. Teilzahlungen, Rest ausbuchen und Zahlung entfernen gibt es seit `.182`/`.183`; darauf baut der Zahlungsplan auf.

## Entscheide

| Frage | Entscheid |
|---|---|
| Anzahl Raten | 2 bis 12. Ohne Zahlungsplan bleibt alles wie heute. |
| Raten festlegen | Je Rate Fälligkeit und Betrag. Die letzte Rate ist immer der Rest (Total minus übrige Raten) und wird von Gäld gesetzt. Das Formular hilft mit „Gleichmässig aufteilen“ (Anzahl, Abstand in Tagen ab Rechnungsdatum). |
| Fälligkeiten | Feste Daten, streng aufsteigend, erste frühestens am Rechnungsdatum. |
| Zuordnung der Zahlungen | Keine feste Zuordnung. Zahlungen füllen die Raten der Reihe nach (älteste Fälligkeit zuerst). Entfernen oder Ausbuchen rechnet neu. |
| Fälligkeit der Rechnung | `due_date` ist die Fälligkeit der ersten nicht gedeckten Rate. Gäld führt sie bei jeder Zahlung nach; Überfällig, Liste, Mahnung und Automationen arbeiten damit unverändert. |
| QR-Scheine | Ein Schein je Rate, je mit eigener QR-Referenz (bei QR-IBAN). Bei offener Rechnung nur die nicht gedeckten Raten mit ihrem offenen Betrag; bei bezahlter Rechnung alle Raten wie ausgestellt. |
| PDF | Kopf „Zahlbar: gemäss Zahlungsplan“ statt „Fällig am“, keine Rechnungsreferenz im Kopf. Unter den Totals eine Tabelle Rate, Fällig am, Betrag. |
| Abgleich | Eine Zahlung mit der Referenz einer Rate gehört zur Rechnung. Der Betragsvorschlag kennt auch den Betrag einer Rate. |
| Mahnung | Eine Mahnung je Rechnung. Sie nennt die überfälligen Raten und deren offenen Betrag. |
| MWST | Unverändert: die ganze Rechnung wird am Rechnungsdatum gebucht (vereinbartes Entgelt). |
| Steering | Offene Rechnungen mit Plan erscheinen je offene Rate mit deren Fälligkeit und Betrag. |
| Bearbeiten | Plan nur im Entwurf änderbar. Zurück in den Entwurf wie heute (ohne Zahlungen). Duplikat übernimmt den Plan mit gleichen Abständen ab dem neuen Rechnungsdatum. |
| Nicht Teil davon | Zahlungsplan in Offerten, wiederkehrende Rechnungen mit Plan, Gutschriften mit Plan. |

## US1 Zahlungsplan erfassen (P1)

Im Rechnungsformular (neu und bearbeiten) lässt sich „Zahlungsplan“ einschalten. Gäld schlägt Raten vor, die Person passt Daten und Beträge an. Die letzte Rate zeigt den Rest und ist nicht editierbar. Ein Rest von 0 oder weniger ist ein Fehler. Über die API: `installments: [{due_date, amount}]` beim Erstellen und Ändern, `[]` entfernt den Plan; `amount` der letzten Rate wird ignoriert.

## US2 Abschliessen, PDF und QR (P1)

Abschliessen prüft den Plan (Anzahl, Daten, Summe gleich Total) und vergibt je Rate eine QR-Referenz. Das PDF zeigt den Plan und je Rate einen Einzahlungsschein.

## US3 Zahlungen und Fälligkeit (P1)

Nach jeder erfassten, entfernten oder ausgebuchten Zahlung (auch aus dem Bankabgleich und dessen Rückgängig) zeigt die Rechnung die nächste offene Rate als Fälligkeit. Eine überfällige Rechnung wird wieder „Gesendet“, sobald die überfälligen Raten gedeckt sind. Die Rechnungsseite zeigt die Raten mit bezahlt, offen und überfällig.

## US4 Abgleich, Mahnung, Steering (P2)

QR-Abgleich und Bankvorschlag finden die Rechnung über die Referenz oder den Betrag einer Rate. Die Mahnung nennt überfällige Raten und deren Betrag. Steering verteilt offene Rechnungen mit Plan auf die Raten.

## US5 Duplikat und API-Ausgabe (P2)

Das Duplikat übernimmt den Plan. `InvoiceResource` liefert `installments` mit `position`, `due_date`, `amount`, `amount_paid`, `amount_open`, `qr_reference`.
