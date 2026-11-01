# Rechnung mit Zahlungsplan Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eine Rechnung kann in 2 bis 12 Raten mit eigener Fälligkeit und eigenem QR-Schein bezahlt werden; Zahlungen, Überfällig, Mahnung, Abgleich und Steering richten sich nach den Raten.

**Architecture:** Neue Tabelle `invoice_installments` (Rate = Position, Fälligkeit, Betrag, QR-Referenz). Ein `InstallmentPlanService` legt den Plan im Entwurf an, verteilt Zahlungen der Reihe nach auf die Raten (keine gespeicherte Zuordnung) und führt `invoices.due_date` als Fälligkeit der ersten offenen Rate nach. Weil `due_date` nachgeführt wird, laufen Überfällig-Befehl, Liste, Automationen und Statusrückfall unverändert; angepasst werden nur PDF/QR, Abgleich, Mahnung, Steering, Formular und API.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL, Inertia + Vue 3, TCPDF, sprain/swiss-qr-bill, PHPUnit.

**Spec:** `specs/013-invoice-payment-plans/spec.md`

## Global Constraints

- 2 bis 12 Raten; Fälligkeiten streng aufsteigend, erste `>=` Rechnungsdatum; jede Rate `> 0`.
- Die letzte Rate ist immer der Rest: `total - Summe(übrige Raten)`; Gäld setzt ihn, Eingaben für die letzte Rate werden ignoriert.
- Plan nur im Status `draft` änderbar. Ohne Plan verhält sich jede Rechnung exakt wie heute (bestehende Tests bleiben grün).
- Zahlungen füllen Raten der Reihe nach (älteste Fälligkeit zuerst); Ausbuchungen (`payment_method = write_off`) zählen mit.
- `invoices.due_date` = Fälligkeit der ersten nicht gedeckten Rate; ist alles gedeckt, die der letzten Rate.
- Keine Instanzdetails ins Repo (öffentlicher Fork). Texte in `lang/{de,en,fr,it}/app.php`, keine Gedankenstriche in deutschen Texten.
- Tests: `gaeld-wt test <datei>`; am Ende `gaeld-wt test -p` und `gaeld-wt pruefen`. Bekannt rot und nicht Teil dieser Arbeit: `RevertInvoiceToDraftTest::test_a_settled_period_is_left_alone_and_the_correction_goes_to_today`.
- Commits mit `--no-verify` (Pre-Commit-Hook findet keinen Dev-Container).

## Review Focus

- Rechnungspositionen im Entwurf geändert, nachdem der Plan erfasst war: die letzte Rate muss den neuen Rest tragen; wird er `<= 0`, schlägt Speichern mit Fehler auf `installments` fehl (Task 2).
- Zahlung aus dem Bankabgleich und deren „Rückgängig“ (`UnreconcileTransactionAction` löscht die Zahlung direkt): Fälligkeit und Status müssen trotzdem nachgeführt werden (Task 4, Test über den Modell-Hook).
- Überfällige Rechnung, deren überfällige Rate nachträglich bezahlt wird, die nächste Rate aber in der Zukunft liegt: Status zurück auf `sent` (Task 4).
- Zahlung mit der QR-Referenz von Rate 3, obwohl Rate 1 offen ist: wird gebucht und füllt Rate 1 (Reihenfolge), keine Fehlermeldung (Task 7).
- Teilbezahlte Rechnung mit Plan als PDF: nur offene Raten mit offenem Betrag als QR-Schein; eine halb bezahlte Rate mit ihrem Rest (Task 6).

---

### Task 1: Datenmodell und Plan-Kern

**Files:**
- Create: `database/migrations/2026_11_02_090000_create_invoice_installments_table.php`
- Create: `app/Domains/Invoicing/Models/InvoiceInstallment.php`
- Create: `app/Domains/Invoicing/Services/InstallmentPlanService.php`
- Modify: `app/Domains/Invoicing/Models/Invoice.php` (Beziehung, Hilfsmethode)
- Test: `tests/Feature/Invoicing/InstallmentPlanServiceTest.php`

**Interfaces:**
- Produces:
  - Tabelle `invoice_installments`: `id` uuid PK, `organization_id` (FK organizations, cascade), `invoice_id` (FK invoices, cascade), `position` smallint, `due_date` date, `amount` decimal(15,2), `qr_reference` string(27) nullable, timestamps; unique `(invoice_id, position)`, unique `(organization_id, qr_reference)`.
  - `InvoiceInstallment` (Traits wie `InvoicePayment`: `Auditable, BelongsToOrganization, HasFactory, HasUuids`; Casts `due_date` date, `amount` decimal:2, `position` integer).
  - `Invoice::installments(): HasMany` sortiert nach `position`; `Invoice::hasPaymentPlan(): bool`.
  - `InstallmentPlanService::sync(Invoice $invoice, array $rates): void`, `$rates` = `list<array{due_date: string, amount?: string|null}>`; `[]` entfernt den Plan. Wirft `ValidationException` mit Schlüssel `installments` bei Regelverstoss (Global Constraints). Setzt die letzte Rate auf den Rest und `due_date` der Rechnung auf die erste Fälligkeit.
  - `InstallmentPlanService::states(Invoice $invoice): list<array{installment: InvoiceInstallment, paid: string, open: string, overdue: bool}>` (Reihenfolge-Verteilung von `amountPaid()`; `overdue` = offen > 0 und Fälligkeit vor heute).
  - `InstallmentPlanService::refresh(Invoice $invoice): void` setzt `due_date` (Constraint) und kippt nur bei `sent`/`overdue`: Fälligkeit vor heute → `overdue`, sonst → `sent`. Ohne Plan: tut nichts.

- [ ] **Step 1: Failing tests schreiben** (Rechnung 10 × 150.00 zu 8.1 %, Total 1621.50, Rechnungsdatum `2026-11-02`, `travelTo('2026-11-02')`)

```php
public function test_the_last_rate_carries_the_rest(): void
// sync mit [['due_date'=>'2026-11-30','amount'=>'600.00'],['due_date'=>'2026-12-31','amount'=>'600.00'],['due_date'=>'2027-01-31','amount'=>'1.00']]
// assert amounts ['600.00','600.00','421.50'], positions [1,2,3], invoice due_date '2026-11-30'

public function test_a_plan_breaking_the_rules_is_refused(): void
// je ValidationException (Schlüssel installments) für: 1 Rate; 13 Raten; Daten nicht aufsteigend;
// erste Rate vor Rechnungsdatum; übrige Raten >= Total (Rest <= 0)

public function test_an_empty_plan_removes_it(): void
// sync(...3 Raten), sync([]) → installments()->count() === 0, hasPaymentPlan() false

public function test_payments_fill_the_rates_in_order(): void
// Rechnung abgeschlossen, Zahlung 900.00 → states: paid ['600.00','300.00','0.00'], open ['0.00','300.00','421.50']

public function test_refresh_moves_the_due_date_and_status(): void
// travelTo('2026-12-05'), Status overdue, Zahlung 600.00 → refresh → due_date '2026-12-31', status sent
```

- [ ] **Step 2: `gaeld-wt test tests/Feature/Invoicing/InstallmentPlanServiceTest.php` → FAIL (Klassen fehlen)**
- [ ] **Step 3: Migration, Modell, Beziehung und Service nach den Interfaces implementieren**
- [ ] **Step 4: Test erneut → PASS**
- [ ] **Step 5: Commit** `git commit --no-verify -m "Add payment plans to invoices: rates, order of payments, next due date"`

### Task 2: Plan speichern (Formular-Backend und API)

**Files:**
- Modify: `app/Domains/Invoicing/DTOs/InvoicePayloadData.php` (neues Feld `public ?array $installments = null`; `null` = nicht gesendet, unverändert lassen)
- Modify: `app/Domains/Invoicing/Actions/CreateInvoiceAction.php`, `app/Domains/Invoicing/Actions/UpdateInvoiceAction.php` (nach `recalculate()` → `sync`, wenn `installments !== null`; bei Update ohne neues Feld, aber geänderten Zeilen: `sync` mit bestehendem Plan erneut, damit der Rest stimmt)
- Modify: `app/Domains/Invoicing/Requests/Concerns/InvoiceValidationRules.php`, `app/Domains/Api/Requests/StoreInvoiceApiRequest.php`, `app/Domains/Api/Requests/UpdateInvoiceApiRequest.php`
- Modify: `app/Domains/Api/Resources/InvoiceResource.php`
- Test: `tests/Feature/Invoicing/InstallmentPlanInputTest.php`

**Interfaces:**
- Consumes: `InstallmentPlanService::sync`, `::states`.
- Produces: Request-Regeln `installments` `nullable|array|min:2|max:12` (leeres Array erlaubt), `installments.*.due_date` `required|date|after_or_equal:issue_date`, `installments.*.amount` `nullable|numeric|min:0.01`. `InvoiceResource.installments`: `list<{position, due_date, amount, amount_paid, amount_open, qr_reference}>` (nur wenn geladen bzw. Plan vorhanden; sonst `[]`).

- [ ] **Step 1: Failing tests**

```php
public function test_a_draft_is_saved_with_a_plan_through_the_page(): void
// POST /invoices (Entwurf) mit installments (3 Raten) → 3 Raten in DB, letzte = Rest

public function test_changing_the_lines_of_a_draft_moves_the_rest_to_the_last_rate(): void
// Entwurf mit Plan 600/600/Rest, Update: Menge 12 statt 10 (Total 1945.80) ohne installments → letzte Rate '745.80'

public function test_a_rest_of_zero_is_refused(): void
// Update: Menge 7 (Total 1135.05) bei Raten 600/600 → Session-Fehler auf installments, Plan unverändert

public function test_the_api_writes_and_reads_the_plan(): void
// POST /api/v1/invoices mit installments → 201, data.installments[2].amount == Rest; PATCH installments: [] → data.installments == []
```

- [ ] **Step 2: Tests → FAIL**
- [ ] **Step 3: DTO, Actions, Regeln, Resource erweitern**
- [ ] **Step 4: Tests → PASS; zusätzlich `gaeld-wt test tests/Feature/Api/InvoiceParityApiTest.php tests/Feature/Invoicing/InvoiceFlowTest.php` → PASS**
- [ ] **Step 5: Commit** `"Save an invoice's payment plan from the page and the API"`

### Task 3: Formular und Rechnungsseite

**Files:**
- Create: `resources/js/Components/Invoices/PaymentPlanFields.vue`
- Modify: `resources/js/Pages/Invoices/Create.vue`, `resources/js/Pages/Invoices/Edit.vue`, `resources/js/Pages/Invoices/Show.vue`
- Modify: `app/Domains/Invoicing/Controllers/InvoiceController.php` (`edit`: Plan mitgeben; `show`: Prop `installmentStates` aus `states()` als `list<{position, due_date, amount, paid, open, overdue}>`)
- Modify: `lang/{de,en,fr,it}/app.php`
- Test: `tests/Feature/Invoicing/InvoiceShowActionsTest.php` (erweitern)

**Interfaces:**
- Consumes: Request-Format aus Task 2, `states()` aus Task 1.
- Produces: `PaymentPlanFields` mit `v-model` = Array `{due_date, amount}`, Props `total` (Zahl), `issueDate` (String). Schalter „Zahlungsplan“; „Gleichmässig aufteilen“ mit Anzahl (2 bis 12) und Abstand in Tagen (Vorgabe 30), Beträge auf 0.05 gerundet, letzte Zeile = Rest, nur lesbar. Mit Plan wird das Feld „Fällig am“ im Formular ausgeblendet.

- [ ] **Step 1: Failing test** `test_the_invoice_page_shows_the_rates_with_their_state` (Inertia-Prop `installmentStates` mit 3 Einträgen, erster `paid` 600.00, `open` 0.00)
- [ ] **Step 2: Test → FAIL**
- [ ] **Step 3: Komponente, Seiten, Controller-Props, Übersetzungen** (Schlüssel u. a. `payment_plan`, `payment_plan_split_evenly`, `payment_plan_count`, `payment_plan_interval_days`, `payment_plan_rest`, `installment`, `installment_overdue`)
- [ ] **Step 4: Test → PASS; `gaeld-wt pruefen` (Übersetzungen, Build) grün; `gaeld-wt ui` für `/invoices/create` (Schalter an via `SHOT_CLICK`) und eine Rechnung mit Plan (`UI_EXTRA`) in `1400:light,390:dark` ansehen**
- [ ] **Step 5: Commit** `"Enter and show an invoice's payment plan"`

### Task 4: Abschliessen und Nachführen bei Zahlungen

**Files:**
- Modify: `app/Domains/Invoicing/Actions/FinalizeInvoiceAction.php`
- Modify: `app/Domains/Invoicing/Services/SwissQrInvoiceService.php` (`ensureInstallmentReferences`)
- Modify: `app/Domains/Invoicing/Models/InvoicePayment.php` (`booted()`: `created` und `deleted` → `app(InstallmentPlanService::class)->refresh($payment->invoice)`)
- Modify: `app/Domains/Invoicing/Actions/DuplicateInvoiceAction.php`
- Test: `tests/Feature/Invoicing/InstallmentPlanLifecycleTest.php`

**Interfaces:**
- Consumes: Task 1.
- Produces: `SwissQrInvoiceService::ensureInstallmentReferences(Invoice $invoice, Organization $organization): void` (nur bei QR-IBAN; Referenznummer = Ziffern der Rechnungsnummer auf 18 Stellen links mit 0 + Position auf 2 Stellen; gleiche Kundenkennung wie `ensureQrReference`; bei Kollision mit `invoices.qr_reference` oder `invoice_installments.qr_reference` der Organisation: `InvalidInvoiceStateException`). `InstallmentPlanService::assertReadyToFinalize(Invoice $invoice): void` (Summe = Total, Regeln aus Task 1; sonst `InvalidInvoiceStateException` mit `__('app.payment_plan_invalid')`). `InstallmentPlanService::copy(Invoice $from, Invoice $to): void` (gleiche Tagesabstände ab `$to->issue_date`, Beträge wie Vorlage, letzte = Rest).

- [ ] **Step 1: Failing tests**

```php
public function test_finalizing_gives_each_rate_its_own_reference(): void
// Organisation mit QR-IBAN; 3 Raten → 3 verschiedene 27-stellige Referenzen, keine gleich invoices.qr_reference

public function test_a_plan_that_no_longer_adds_up_is_not_finalized(): void
// Rate-Betrag direkt in DB verändert → FinalizeInvoiceAction wirft InvalidInvoiceStateException

public function test_a_bank_line_taken_back_reopens_the_rate(): void
// Zahlung 600 über InvoiceReconciler::reconcileWithInvoice, dann UnreconcileTransactionAction → due_date wieder Rate 1

public function test_a_write_off_settles_every_rate(): void
// Zahlung 600, Rest ausbuchen → status paid, states alle open '0.00'

public function test_the_duplicate_keeps_the_plan_from_its_new_date(): void
// Raten +28/+59/+90 Tage ab Rechnungsdatum → Duplikat gleiche Abstände ab heute
```

- [ ] **Step 2: Tests → FAIL**
- [ ] **Step 3: Implementieren** (`FinalizeInvoiceAction`: bei Plan `assertReadyToFinalize`, dann `ensureInstallmentReferences` nach `ensureQrReference`)
- [ ] **Step 4: Tests → PASS; dazu `gaeld-wt test tests/Feature/Invoicing/InvoicePartPaymentTest.php tests/Unit/Actions/RecordPaymentActionTest.php` → PASS**
- [ ] **Step 5: Commit** `"Finalize a payment plan with a reference per rate and keep its due date with every payment"`

### Task 5: Abgleich über Raten

**Files:**
- Modify: `app/Domains/Invoicing/Models/Invoice.php` (`scopeWithQrReference`, `scopeByOpenAmountRange`)
- Modify: `app/Domains/Automation/Handlers/QrPaymentMatchingHandler.php`, `app/Domains/Banking/Services/MatchingService.php`, `app/Domains/Invoicing/Queries/InvoiceReportingQuery.php:66`
- Test: `tests/Feature/Automation/AutomationHandlersTest.php`, `tests/Unit/Services/MatchingServiceTest.php` (erweitern)

**Interfaces:**
- Produces: `Invoice::scopeWithQrReference(Builder $query, string $reference): Builder` = `qr_reference = ?` oder `whereHas('installments', qr_reference = ?)`. `scopeByOpenAmountRange` passt zusätzlich, wenn eine Rate der Rechnung mit ihrem Betrag in der Toleranz liegt.

- [ ] **Step 1: Failing tests** `test_a_payment_with_a_rate_reference_is_booked` (Referenz von Rate 3, Betrag 421.50, Rate 1 offen → gebucht, `states()[0].paid == '421.50'`); `test_a_rate_amount_is_suggested` (Bankzeile 600.00 vom Kunden → Vorschlag Betrag und Kunde)
- [ ] **Step 2: → FAIL**
- [ ] **Step 3: Scope einführen und an den drei Stellen statt `where('qr_reference', …)` nutzen; Betragsbedingung ergänzen**
- [ ] **Step 4: → PASS**
- [ ] **Step 5: Commit** `"Match payments by the reference or amount of a rate"`

### Task 6: PDF und QR-Scheine je Rate

**Files:**
- Modify: `app/Domains/Invoicing/Services/InvoicePdfRenderer.php` (`renderMeta`, neue `renderInstallments(TCPDF $tcpdf, Invoice $invoice): void` nach `renderTotals`)
- Modify: `app/Domains/Invoicing/Actions/GenerateQrInvoicePdfAction.php` (eine QR-Seite je Schein)
- Modify: `app/Domains/Invoicing/Services/SwissQrInvoiceService.php` (`buildQrBill(Invoice $invoice, Organization $organization, ?InvoiceInstallment $installment = null, ?string $amount = null): QrBill`; Referenz und Betrag der Rate, Zusatzinfo `"{number} Rate {position}/{count}"`)
- Modify: `app/Domains/Invoicing/Services/InstallmentPlanService.php` (`slips(Invoice $invoice): list<array{installment: InvoiceInstallment, amount: string}>`)
- Modify: `lang/{de,en,fr,it}/app.php` (`pdf_payable`, `pdf_as_per_payment_plan`, `pdf_payment_plan`, `pdf_installment`)
- Test: `tests/Feature/Invoicing/GenerateQrInvoicePdfActionTest.php` (erweitern)

**Interfaces:**
- Consumes: `states()` (Task 1), Referenzen (Task 4).
- Produces: `slips()` nach Spec: offene Rechnung → Raten mit `open > 0` und `open` als Betrag; sonst alle Raten mit `amount`.

- [ ] **Step 1: Failing tests** `test_each_open_rate_gets_its_slip` (Zahlung 900 → `slips()` = Rate 2 mit 300.00, Rate 3 mit 421.50; `buildQrBill(..., rate 2, '300.00')->getPaymentAmountInformation()->getAmount() === 300.0`; PDF beginnt mit `%PDF-` und hat 1 + 2 Seiten (Seitenzahl über `preg_match_all('/\/Type\s*\/Page[^s]/', $pdf)`)); `test_a_paid_plan_prints_every_rate`
- [ ] **Step 2: → FAIL**
- [ ] **Step 3: Implementieren**; ohne Plan bleibt die Ausgabe Byte für Byte im Aufbau wie heute (bestehende PDF-Tests grün)
- [ ] **Step 4: → PASS; ein Beispiel-PDF mit `gaeld-wt php` erzeugen und ansehen**
- [ ] **Step 5: Commit** `"Print the payment plan and one payment slip per open rate"`

### Task 7: Mahnung und Steering

**Files:**
- Modify: `app/Domains/Invoicing/Mail/InvoiceReminderMail.php` (neu `public readonly array $overdueRates`; `amountDue` bei Plan = Summe `open` der überfälligen Raten)
- Modify: `resources/views/emails/invoice-reminder.blade.php`, `lang/{de,en,fr,it}/mail.php`
- Modify: `app/Domains/Projects/Services/SteeringService.php` (`openInvoices`)
- Test: `tests/Feature/Invoicing/PaymentReminderTest.php`, `tests/Feature/Projects/` (bestehender Steering-Test der offenen Rechnungen, erweitern)

**Interfaces:**
- Consumes: `states()`.
- Produces: `openInvoices()` liefert bei Plan je offene Rate eine Zeile mit gleichem `id`, zusätzlich `installment` (`"2/3"`), `due_date` der Rate, `gross` = offener Ratenbetrag, `net` anteilig wie heute; ohne Plan unverändert (`installment` = `null`). Steering-Ansicht zeigt `installment` hinter der Nummer.

- [ ] **Step 1: Failing tests** `test_the_reminder_names_the_overdue_rates` (travelTo nach Rate 1, nichts bezahlt → Mail enthält „Rate 1“ und `600.00`, nicht `1'621.50`); `test_steering_spreads_a_plan_over_its_rates` (3 Zeilen, Summe `gross` = 1621.50, Fälligkeiten der Raten)
- [ ] **Step 2: → FAIL**
- [ ] **Step 3: Implementieren** (Vue-Anzeige der Steering-Liste: Datei per `grep -rn "open_invoices" resources/js` finden)
- [ ] **Step 4: → PASS**
- [ ] **Step 5: Commit** `"Remind of overdue rates and spread a payment plan over steering"`

### Task 8: Gesamtprüfung und Rollout

- [ ] **Step 1:** `gaeld-wt test -p` → nur der bekannte Datumstest rot; `gaeld-wt pruefen` → grün
- [ ] **Step 2:** Durchgang im Browser (`gaeld-wt ui`): Rechnung mit Plan anlegen, abschliessen, PDF herunterladen, Rate 1 erfassen, Rechnungsseite und Liste prüfen
- [ ] **Step 3:** Masteranleitung (`~/Gaeld-Projektstatus.md`, 2.1 und 9) vorbereiten; Migration ist rein additiv (neue Tabelle) und ändert keine Daten
- [ ] **Step 4:** `git push -u origin feature/zahlungsplan`, dann `gaeld-ausrollen feature/zahlungsplan` und `bericht-nachher.txt` lesen
