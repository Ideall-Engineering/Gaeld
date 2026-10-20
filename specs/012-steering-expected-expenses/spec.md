# Feature Specification: Steering mit erwarteten Ausgaben

**Feature Branch**: `feature/steering-expected-expenses`

**Created**: 2026-10-20

**Status**: Etappen 1 bis 3 gebaut, nicht ausgerollt (mit Migration)

**Input**: Die Seite Projekte → Steering zeigt heute, welche Zahlungseingänge noch erwartet werden. Daneben soll sichtbar sein, welche Ausgaben noch kommen (Löhne, Sozialversicherungen, Miete, Abos, offene Rechnungen), und am Schluss, was unter dem Strich noch erwartet wird.

## Entscheide

| Frage | Entscheid |
|---|---|
| Name der Seite | „Steering“ in allen Sprachen (statt „Personen“). |
| Planposten unter „Bezogen“ | Sind ausbezahlte Löhne, also Vergangenes. Sie überschneiden sich nicht mit den erwarteten Löhnen. |
| Netto oder brutto | Netto, wie die erwarteten Einnahmen. Die Vorschau auf den Bankstand rechnet die Ausgaben brutto und die MWST-Zahlungen mit. |
| Sozialversicherungen | Aus der Lohnberechnung abgeleitet: die Arbeitgeberbeiträge je erwarteten Lohnmonat. Keine eigenen Vorlagen dafür. |
| Lohntag | Der 25. des Monats. |
| Wer sieht die Seite | Ab Buchhalter (Buchhalter, Admin, Owner): Seite, Export und API. Schlüssel, Kostenblöcke und Planposten ändern bleibt beim Owner. Einzelne erwartete Ausgaben erfassen alle, die die Seite sehen. |
| MWST | Ist eine Zahlung, kein Aufwand: erscheint in der Liste und im Bankstand, nicht im Ergebnis netto. |

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

## Etappe 2

### US4 Weitere Quellen (P1)

5. **Anschaffung**: Anschaffungen im Status „bestellt“ ohne Buchung, mit Kaufdatum, netto (brutto für den Bankstand).
6. **MWST**: im laufenden Jahr der Saldo von 2201 (abgerechnet, noch nicht bezahlt) mit Datum heute; dazu jedes noch nicht abgerechnete Quartal, dessen Zahlung (Quartalsende plus 60 Tage) ins Jahr fällt, mit der Zahllast aus dem MWST-Bericht. Das laufende Quartal zählt mit dem bisher Gebuchten und erscheint im Jahr seiner Fälligkeit. MWST-Zeilen zählen nicht zum Ergebnis netto.
7. **Kostenblock**: je Kostenblock des Jahres der Rest aus Plan minus gebucht minus allem, was die übrigen Zeilen auf seinen Konten schon erwarten; nur wenn positiv, ohne Datum. Lohnzeilen tragen dafür das Lohnkonto, Sozialversicherungen das Aufwandkonto ihres Abzugssatzes.

### US5 Export (P2)

CSV und PDF des Steering enthalten die erwarteten Ausgaben, das PDF auch den Verlauf Monat für Monat.

## Etappe 3

### US6 Einzelne erwartete Ausgaben (P1)

Neue Tabelle `planned_expenses` (Datum, Bezeichnung, Betrag netto, MWST, Konto, erledigt am). Auf der Seite erfassen, abhaken und löschen; über die API `POST /api/v1/planned-expenses`, `PATCH /api/v1/planned-expenses/{id}` (`done`: true/false), `DELETE`. Ein Posten zählt, bis er erledigt ist; liegt sein Datum vor heute, ist er überfällig.

### US7 Monat für Monat und Bankstand (P1)

Für das laufende Jahr: ab dem laufenden Monat bis Dezember des nächsten Jahres je Monat Einnahmen, Ausgaben netto, Ergebnis kumuliert und Bankstand. Der Bankstand beginnt beim heutigen Buchsaldo der aktiven Bankkonten, zählt die Einnahmen mit ihrem erwarteten Betrag und zieht die Ausgaben brutto samt MWST-Zahlungen ab. Überfälliges zählt im laufenden Monat, Undatiertes im Dezember seines Jahres. Darstellung als Balken (Einnahmen, Ausgaben), Linie (Bankstand) und Tabelle; in der Gegenüberstellung Bankstand heute und erwarteter Bankstand am 31.12. beider Jahre.
