# Feature Specification: Steering mit erwarteten Ausgaben

**Feature Branch**: `feature/steering-expected-expenses`

**Created**: 2026-10-20

**Status**: Etappe 1 in Arbeit

**Input**: Die Seite Projekte → Steering zeigt heute, welche Zahlungseingänge noch erwartet werden. Daneben soll sichtbar sein, welche Ausgaben noch kommen (Löhne, Sozialversicherungen, Miete, Abos, offene Rechnungen), und am Schluss, was unter dem Strich noch erwartet wird.

## Entscheide

| Frage | Entscheid |
|---|---|
| Name der Seite | „Steering“ in allen Sprachen (statt „Personen“). |
| Planposten unter „Bezogen“ | Sind ausbezahlte Löhne, also Vergangenes. Sie überschneiden sich nicht mit den erwarteten Löhnen. |
| Netto oder brutto | Netto, wie die erwarteten Einnahmen. Eine Vorschau auf den Bankstand (brutto) kommt später. |
| Sozialversicherungen | Aus der Lohnberechnung abgeleitet: die Arbeitgeberbeiträge je erwarteten Lohnmonat. Keine eigenen Vorlagen dafür. |
| Lohntag | Der 25. des Monats. |
| Wer sieht die Seite | Ab Buchhalter (Buchhalter, Admin, Owner): Seite, Export und API. Schlüssel, Kostenblöcke und Planposten ändern bleibt beim Owner. |

## Etappe 1

### US1 Name und Sichtbarkeit (P1)

Das Menü und die Seite heissen „Steering“. Wer Aufträge verwalten darf (`projects.manage`, also Buchhalter, Admin und Owner), sieht den Menüpunkt, die Seite, den Export und `GET /api/v1/steering`. Ohne das Recht `steer` (nur Owner) sind auf der Seite die Knöpfe zum Ändern von Kostenblöcken und Planposten ausgeblendet, und die Schreibwege antworten weiterhin mit 403.

### US2 Noch erwartete Ausgaben (P1)

Eine zweite Übersicht neben „Noch offene Zahlungseingänge“, mit denselben Reitern für das gewählte und das nächste Jahr. Jede Zeile hat Datum, Bezeichnung, Quelle, Konto und Betrag netto. Gezählt wird ab heute bis Ende Jahr; für ein künftiges Jahr das ganze Jahr, für ein vergangenes nichts ausser offenen Ausgaben.

Quellen:

1. **Lohn**: je aktive Person mit Monatslohn und Monat ohne verbuchte oder extern gebuchte Lohnabrechnung. Betrag aus der bestehenden Lohnvorschau: Bruttolohn (im Dezember mit 13. Monatslohn) plus Spesenpauschale, anteilig bei Ein- und Austritt. Datum der 25. des Monats. Personen im Stundenlohn haben keine Schätzung; die Seite nennt sie.
2. **Sozialversicherungen**: die Arbeitgeberbeiträge derselben Lohnvorschau, eine Zeile je Monat.
3. **Wiederkehrend**: jede Fälligkeit aktiver wiederkehrender Ausgaben ab ihrem nächsten Fälligkeitsdatum bis Ende Jahr und höchstens bis zum Enddatum, mit dem Nettobetrag der Vorlage.
4. **Offene Ausgabe**: Ausgaben im Status „ausstehend“ oder „genehmigt“ mit Datum bis Ende Jahr. Liegt das Datum vor heute, ist die Zeile als überfällig markiert.

Nichts zählt doppelt: Eine verbuchte Lohnabrechnung nimmt ihren Monat aus der Liste, eine wiederkehrende Ausgabe rückt ihr nächstes Fälligkeitsdatum beim Erzeugen weiter, und eine verbuchte Ausgabe ist nicht mehr offen.

### US3 Was noch erwartet wird (P1)

Am Schluss der Seite je Reiter: noch erwartete Einnahmen minus noch erwartete Ausgaben ergibt das erwartete Ergebnis. Die Ausgaben stehen zusätzlich als Summe je Quelle.

## Etappe 2 (später)

Bestellte Anschaffungen, MWST (offener Saldo 2201 und geschätzte Zahllast), Rest der Kostenblöcke, Ausgaben im Export.

## Etappe 3 (später)

Von Hand erfasste einzelne erwartete Ausgaben (neue Tabelle), Verlauf Monat für Monat, Vorschau auf den Bankstand.
