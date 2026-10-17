---

description: "Gäld task list: Projekte und Zuordnung von Buchungszeilen, Etappe 1"

---

# Tasks: Projekte und Zuordnung, Etappe 1

**Input**: [spec.md](spec.md) und [plan.md](plan.md).

**Scope rule**: Keine Schreibzugriffe auf `transaction_lines`, `journal_entries` oder `vat_entries` aus diesem Vorhaben. Die bestehende Analytik bleibt unverändert. Eingriffe in bestehende Aktionen sind je ein Aufruf an `LineAllocationService`.

**Format**: `[P]` = parallel möglich, `[USn]` = User Story aus spec.md.

---

## Phase 1: Fundament

- [x] T001 Migration `extend_cost_centers_for_projects` (Spalten laut plan.md)
- [x] T002 Migration `create_line_allocations_table` mit Check-Constraints und Übernahme aus `transaction_lines.cost_center_id`
- [x] T003 Migration `create_allocation_reviews_table`
- [x] T004 Migration `add_project_permissions` (Rechte und Rollen-Sync); `migrate` und `migrate:rollback` lokal durchspielen
- [x] T005 [P] Enums `ProjectKind`, `ProjectStatus`, `AllocationSource` mit Übersetzungsschlüsseln
- [x] T006 [P] Modelle `LineAllocation`, `AllocationReview`; `CostCenter` um Felder, Casts und Beziehungen erweitern; `TransactionLine::allocations()`
- [x] T007 [P] `OrganizationModule::Projects`, `config/features.php`, Presets, Einstellungsseite
- [x] T008 [P] `Permission`-Fälle, Zuordnung in `Role`
- [x] T009 DTO `LineAllocationData`, `JournalLineData::$allocations` samt `fromArray`

## Phase 2: User Story 1, Projekte führen (P1)

### Tests First

- [x] T010 [P] [US1] `tests/Feature/Projects/ProjectManagementTest.php`: Anlegen, Ändern, Status, Hierarchie, Zyklus abgelehnt, Löschen mit Zuordnung abgelehnt, Modul aus, Rechte, fremde Organisation

### Implementation

- [x] T011 [US1] `ProjectPolicy`, `StoreProjectRequest`, `UpdateProjectRequest` (Wiederverwendung von `ValidCostCenterParent`)
- [x] T012 [US1] `ProjectController` und Routen unter `feature:projects`
- [x] T013 [US1] Seite `Projects/Index.vue` mit Baum, Filter und Formular; Seitenleiste

## Phase 3: User Story 2 und 4, Zuordnung schreiben und übernehmen (P1) 🎯

### Tests First

- [x] T014 [P] [US2] `tests/Feature/Projects/LineAllocationServiceTest.php`: Validierung, Summe, inaktive Projekte, fremde Organisation, Kurzform `cost_center_id`
- [x] T015 [P] [US4] `tests/Feature/Projects/AllocationCarryOverTest.php`: ein Fall je Übernahmestelle aus plan.md, dazu Abgleich eindeutig, Betragsänderung, mehrdeutig mit Prüfeintrag
- [x] T016 [P] [US4] Versionseinträge `updated` und `deleted` enthalten den Zuordnungs-Snapshot

### Implementation

- [x] T017 [US2] `LineAllocationService` (`validate`, `writeFor`, `replaceForEntry`, `carryOver`, `snapshot`)
- [x] T018 [US4] `LedgerService::persistEntry` und `reverseEntry`
- [x] T019 [US4] `UpdateJournalDraftAction`
- [x] T020 [US4] `AmendPostedJournalEntryAction` und `JournalEntryRevisionRecorder`
- [x] T021 [US4] `PrepareJournalCorrectionAction` (Storno und Ersatz)
- [x] T022 [US4] `DeletePostedJournalEntryAction` (Snapshot)
- [x] T023 [US2] Journal-Requests und `JournalEntries.vue`: Projekt pro Zeile, Aufteilen

## Phase 4: User Story 3, Zuordnung jederzeit ändern (P1)

### Tests First

- [x] T024 [P] [US3] `tests/Feature/Projects/AllocationEditAnyStateTest.php`: `vat_locked`, `year_closed`, archiviert (Archivprüfung bleibt gültig), Fachmodul-Buchung, Protokolleintrag
- [x] T025 [P] [US3] Mehrfachzuordnung: nur Erfolgszeilen, Bestätigung bei bestehender Aufteilung

### Implementation

- [x] T026 [US3] `JournalAllocationController` (Einzel und Mehrfach)
- [x] T027 [US3] Dialog „Zuordnung ändern“ und Mehrfachauswahl in `JournalEntries.vue`
- [x] T028 [US3] Seite „Zuordnung prüfen“ mit Zuordnen und Verwerfen

## Phase 5: User Story 5, Projektauswertung (P2)

- [x] T029 [P] [US5] `tests/Feature/Projects/ProjectReportTest.php`: Aufrollen, Abschluss ausgeklammert, Rundung, nur Erfolgskonten, Rechte
- [x] T030 [US5] `ProjectReportService`
- [x] T031 [US5] Seite, CSV und PDF

## Phase 6: User Story 6, API (P2)

- [x] T032 [P] [US6] `tests/Feature/Api/ProjectApiTest.php` und `JournalAllocationApiTest.php`: Lesen, Schreiben, gesperrte Buchung, Idempotenz, Modul aus, Token-Rechte
- [x] T033 [US6] API-Controller, Ressourcen, Routen
- [x] T034 [US6] `contract/api-contract.json`, Prüfung des Vertrags

## Phase 7: Abnahme

- [x] T035 Vergleichstest `tests/Feature/Projects/AllocationLedgerInvarianceTest.php` (siehe plan.md)
- [x] T036 Texte in allen vier Sprachen, Übersetzungskontrolle
- [x] T037 `CiWorkflowCoverageTest`: neue Testdateien in die CI-Gruppen
- [x] T038 Pint, PHPStan, alle Suiten über `gaeld-test`
- [x] T039 UI-Durchgang im Browser mit eingeschaltetem und ausgeschaltetem Modul
- [x] T040 `CHANGELOG.md` (Unreleased) und Domain-README
- [ ] T041 Review, Merge nach `deployment`, Rollout nach Freigabe

---

# Tasks: Etappe 2, Projektübersicht und Rechnungen

- [x] T042 Migrationen `add_billing_month_to_cost_centers`, `create_invoice_projects_table`
- [x] T043 Modell `InvoiceProject` mit Policy; `AllocationSource::Invoice`
- [x] T044 `InvoiceProjectService`: Projekt einer Rechnung setzen, Ertragszeilen beim Verbuchen und bei jeder Änderung zuordnen, Gutschrift erbt, Vorschlag aus dem Kunden
- [x] T045 Rechnungsformulare (Anlegen, Bearbeiten, Detail) mit Projektfeld; Änderung auch bei archivierten Rechnungen
- [x] T046 `ProjectOverviewService` (Ertrag, Aufwand, Netto, Fakturiert, Eingang, Offen, Zahlungsstatus, Termin-Ampel, Summen nach Status und Bereich) und Ansicht „Übersicht“ mit Jahr und CSV
- [x] T047 `AllocationSuggestionService` und Seite „Nicht zugeordnet“ mit Übernehmen
- [x] T048 Löschschutz für Projekte mit Rechnungen
- [x] T049 Tests `tests/Feature/Projects/InvoiceProjectTest.php`
- [x] T050 UI-Durchgang Etappe 2
- [x] T051 Lokales Importskript für das Excel (ausserhalb des Repositorys), Probelauf
- [x] T052 Alle Suiten, Review

---

# Tasks: Etappe 3, Plan gegen Ist

- [x] T053 Migration `create_budget_groups_tables`, Modell `BudgetGroup` mit Policy, Enum `BudgetGroupKind`
- [x] T054 `PlanActualService` (Plan aus Betrag oder Kontobudgets, Ist ohne Kundenprojekte, Rest, Hochrechnung, Konten ohne Block, Vorjahr übernehmen)
- [x] T055 Seite „Plan gegen Ist“ mit Formular, CSV; API zum Lesen und Anlegen
- [x] T056 Tests `PlanActualTest`, API-Test
- [x] T057 Importskript liest auch „Interne Aufwände“ (ausserhalb des Repositorys)
- [x] T058 UI-Durchgang, alle Suiten

---

# Tasks: Etappe 4, Anschaffungen je Person

- [x] T059 Migration `create_acquisitions_tables`, Modell `Acquisition` mit Policy, Enum `AcquisitionStatus`
- [x] T060 `AcquisitionService` (Netto, Belastung je Person mit Rappenausgleich, Fristen, Summen)
- [x] T061 Seite „Anschaffungen“ mit Filter, Formular, CSV; API mit Personenliste
- [x] T062 Tests `AcquisitionTest`, API-Test
- [x] T063 Importskript liest auch „Anschaffungen“ und verbindet Buchungen (ausserhalb des Repositorys)
- [x] T064 UI-Durchgang, alle Suiten

---

# Tasks: Etappe 5, Gründer-Steuerung

- [x] T065 Migration `create_steering_tables` (Anteile, Schlüssel der Kostenblöcke, Planposten), Modelle mit Policies
- [x] T066 `SteeringService` (Wert je Projekt, vererbte Anteile, Wirkung der Blöcke, Bezogen, Spielmasse mit und ohne Möglich, gebuchte Lohnkosten zum Vergleich)
- [x] T067 Seite „Steuerung“ nur für Owner, CSV und PDF; Anteile im Projektformular, Schlüssel im Kostenblockformular
- [x] T068 API für Anteile, Schlüssel und Planposten (nur Owner)
- [x] T069 Tests `SteeringTest` mit durchgerechnetem Beispiel, API-Test
- [x] T070 Importskript: Anteile, Schlüssel und Planposten aus dem Excel
- [ ] T071 UI-Durchgang, alle Suiten
- [ ] T072 Parallelbetrieb ein Monat (nach Rollout, mit dem Nutzer)
