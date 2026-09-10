---

description: "Gäld task list for Etappe 0 (module foundation) and the Etappe 2 focus package: Geführte Journal-Korrektur"

---

# Tasks: Etappe 0 – Fundament & Fokuspaket Geführte Journal-Korrektur

**Input**: [spec.md](spec.md) and [plan.md](plan.md), specifically:
- `## Etappen und Abnahmetore` → `### Etappe 0 – Modul-, Sicherheits- und Vertragsfundament` (plan.md:139-161)
- `### Fokusdesign – Geführte Journal-Korrektur in Web und API` (plan.md:190-249)
- Companion detail document: [`Documents/Gaeld-Journal-Korrektur-Plan.md`](../../Documents/Gaeld-Journal-Korrektur-Plan.md)

**Scope rule**: This file covers only Etappe 0 and the Journal-Korrektur focus
package inside Etappe 2 — not the full Etappe 1–9 roadmap in plan.md. Do not
build read-model/master-data (Etappe 1), invoice/expense workflow additions
(rest of Etappe 2), banking (Etappe 3), or later etappen under this task list;
they get their own `tasks.md` revision when prioritized. Reuse `LedgerService`,
`LedgerQueryService`, `JournalEntryPolicy`, `StoreJournalEntryRequest`,
`JournalEntryData`/`JournalLineData`, and the existing `app/Domains/Api`
Sanctum/idempotency base. Do not add a second journal-entry write path, a
generic module framework beyond `plugins/`, or a parallel permission system.

**Architecture decision (confirmed)**: Correction *endpoints* ship inside the
`plugins/accountant-api` module (Track B) and therefore depend on Etappe 0
being complete first. Correction *domain logic* (migration, model, actions,
policy) and the *web dialog* are Track A core-seams and do not depend on the
module — they can be built, tested, and even shipped before Etappe 0 finishes,
but the API surface cannot go live until Phase 2 (Foundational) below is done.

## Phase 1: Setup

- [ ] T001 [P] Add plugin architecture test scaffolding in `tests/Feature/Plugins/AccountantApiModuleBootTest.php` (boots with module absent, present+compatible, present+incompatible)
- [ ] T002 [P] Add route-collision guard test in `tests/Feature/Plugins/RouteCollisionTest.php` asserting no duplicate method+URI or route name between `routes/api.php` and any plugin's routes
- [ ] T003 [P] Add architecture test in `tests/Architecture/AccountantApiModuleBoundaryTest.php` asserting: no core class references `Plugins\AccountantApi\*`, no module class outside `CoreBridge/` references core models/services directly, no module migration touches an existing core table name

## Phase 2: Foundational — Etappe 0 (Modul-, Sicherheits- und Vertragsfundament)

**Purpose**: Mandatory module foundation. **BLOCKS** Phase 4 (Module API) below. Phases 3 and 5 (domain + web) do not depend on this phase and may proceed in parallel.

**⚠️ CRITICAL**: No module route may be registered until this phase's gate passes.

- [ ] T004 Create plugin skeleton `plugins/accountant-api/plugin.json` (manifest: name, slug, version, provider `Plugins\AccountantApi\AccountantApiServiceProvider`, `requires` with `contract_version`), following the shape of `plugins/example-plugin/plugin.json`
- [ ] T005 Create `plugins/accountant-api/src/AccountantApiServiceProvider.php` (register/boot: translations, routes, migrations, config), modeled on `plugins/example-plugin/src/ExamplePluginServiceProvider.php`
- [ ] T006 Define the generic core extension contract and version identifier (not EE-bound) in `app/Domains/Api/Contracts/PluginContractVersion.php`; extend today's `contract_version`/`ee_version` compatibility check
- [ ] T007 Extend `app/Providers/PluginServiceProvider.php` with fail-closed contract-compatibility verification at plugin boot (generic core-seam, no module namespace reference)
- [ ] T008 [P] Add deployment-level plugin allow-list config in `config/plugins.php` so `PLUGINS_ENABLED=true` does not implicitly enable every discovered plugin
- [ ] T009 Add core+module route inventory and boot guard enforcing no duplicate method/URI or route name (implementation backing T002)
- [ ] T010 [P] Add registrable ability catalog core-seam in `app/Domains/Api/Contracts/AbilityCatalog.php` (replace direct edits to `app/Http/Middleware/Api/TokenPermissionMap.php` per new module ability with a registration call)
- [ ] T011 [P] Add registrable webhook-event catalog core-seam in `app/Domains/Api/Contracts/WebhookEventCatalog.php`
- [ ] T012 Define the accountant/owner/technical-token ability matrix for the module in `plugins/accountant-api/src/AccountantApiServiceProvider.php` boot registration
- [ ] T013 Harden token authorization so scope ∩ user-permission ∩ organization ∩ object-state are all required in `app/Http/Middleware/Api/*` (verify no wildcard token path bypasses a domain invariant)
- [ ] T014 [P] Add tenant-safe nested route-model bindings/resolvers for indirectly-scoped models in `app/Domains/Api/Support/` (needed later for banking; add the generic resolver now as a core-seam)
- [ ] T015 Define and document unified response/error/pagination/filter/money/date/UUID conventions for the module contract in `plugins/accountant-api/README.md`
- [ ] T016 Harden `app/Domains/Api/Services/ApiIdempotencyService.php` (or equivalent) against process abort after domain commit for all financial mutations; add regression test `tests/Feature/Api/IdempotencyProcessAbortTest.php`
- [ ] T017 Define optimistic concurrency control (`409`/`412` semantics) for concurrent web/API edits; add a monotone version core-seam migration only if `updated_at` is not reliable enough
- [ ] T018 [P] Add actor/token/source/correlation-id/idempotency-key context to `activity_log` entries written from the API path
- [ ] T019 Add persistent module job-status model and reliable after-commit webhook dispatch under `plugins/accountant-api/migrations/` (module-prefixed table names only, no core shadow tables)
- [ ] T020 Write the module's own contract document and a reconciliation check against `contract/api-contract.json` proving no collision
- [ ] T021 Add boot/smoke matrix tests: module absent, present+compatible, present+incompatible in `tests/Feature/Plugins/AccountantApiModuleBootTest.php` (fills T001)

**Checkpoint**: Core boots without the module; compatible module boots and registers only collision-free routes; incompatible module fails closed with diagnosis; T001-T003 architecture/boot tests pass. Only after this gate may Phase 4 begin.

---

## Phase 3: User Story 1 — Geführte Journal-Korrektur als Core-Fähigkeit (Priority: P1) 🎯 Track A

**Goal**: Original bleibt unverändert; ein Korrekturvorgang aus Gegenbuchungsentwurf und Ersatzentwurf entsteht, wird gemeinsam verbucht oder gemeinsam verworfen. No dependency on Phase 2 — can start immediately in parallel.

**Independent Test**: Über Tinker/Domain-Action direkt (ohne HTTP) eine verbuchte manuelle Journalbuchung korrigieren, verbuchen, und prüfen, dass Original, Gegenbuchung und Ersatzbuchung korrekt verknüpft und saldiert sind.

### Tests First

- [x] T022 [P] [US1] Add `JournalCorrection` domain tests (original unverändert, vollständige Feld-/MWST-Kopie, Gegenbuchung vertauscht Soll/Haben, Rollback bei Fehler im zweiten Eintrag, Einzelaktionen gesperrt, zweite Korrektur desselben Originals verhindert, Korrektur der Ersatzbuchung erlaubt) in `tests/Unit/Accounting/JournalCorrectionTest.php`
- [x] T023 [P] [US1] Add eligibility tests in `tests/Unit/Accounting/JournalCorrectionEligibilityTest.php` — **partial**: manuell/API/Migration zulässig, Rechnung/Ausgabe/MWST-Abschluss/Jahresabschluss abgelehnt, and a correction's own reversal draft rejected are covered; Bankabstimmung/Lohn/Anlage rejection is implemented in `JournalCorrectionEligibilityService` (T029) but not yet unit-tested — no factory existed for `BankTransaction`/`DepreciationEntry`/`SalarySlip` at the time, follow-up task
- [x] T024 [P] [US1] Add period tests (offene Periode, gesperrtes Geschäftsjahr, gesperrte MWST-Periode, Korrektur in späterer offener Periode) in `tests/Feature/Accounting/JournalCorrectionPeriodTest.php` — the date-suggestion behaviour itself (proposing the original vs. current open date) is not yet implemented; `correction_date` is currently a plain required input, see note on T031

### Implementation

- [x] T025 [US1] Create migration `database/migrations/2026_09_10_120000_create_journal_corrections_table.php` (uuid pk, `organization_id`, unique `original_journal_entry_id`/`reversal_journal_entry_id`/`replacement_journal_entry_id` FKs with `restrictOnDelete`, `status`, `reason`, `source`, nullable `user_id`/`token_id`, `client_operation_id`, `request_hash`, `posted_at`, timestamps; composite index `(organization_id, original_journal_entry_id)`)
- [x] T026 [P] [US1] Create migration `database/migrations/2026_09_10_120001_add_reversal_of_entry_id_to_journal_entries_table.php` (nullable unique indexed `reversal_of_entry_id` FK to `journal_entries.id`)
- [x] T027 [US1] Create `app/Domains/Accounting/Models/JournalCorrection.php` with `organization()`, `original()`, `reversal()`, `replacement()` relations and status casts (depends on T025)
- [x] T028 [US1] Add `reversalOf()`/`reversedBy()` relations and `reversal_of_entry_id` fillable/cast to `app/Domains/Accounting/Models/JournalEntry.php` (depends on T026)
- [x] T029 [US1] Implement `app/Domains/Accounting/Services/JournalCorrectionEligibilityService.php` inspecting actual foreign-module linkages (invoices, expenses, bank reconciliation matches, payroll runs, assets/depreciation, VAT settlements, year-end closings) since `journal_entries.type` is not reliable — **the one-time `journal_events.payload` backfill for unambiguous historical reversals was not implemented in this pass**, follow-up task before Phase 4/5 ship
- [x] T030 [US1] Implement `app/Domains/Accounting/Actions/UpdateJournalDraftAction.php` — shared MWST-safe draft field update, reused by `PUT /journal-entries` web path (refactored `AccountingController::updateJournalEntry` to call it) and the correction replacement update; refuses to touch a reversal draft. Added `LedgerService::validateDraftLines()` as the small public wrapper this and future callers reuse.
- [x] T031 [US1] Implement `app/Domains/Accounting/Actions/PrepareJournalCorrectionAction.php` — in one transaction: eligibility check via T029, create `JournalCorrection`, locked reversal draft (lines swapped from original), full-field replacement draft copy (depends on T025, T027, T029). **Note**: `correction_date` and `reason` are required caller inputs; no date-suggestion helper was added yet (needed for T024's remaining scope and for the web dialog in Phase 5).
- [x] T032 [US1] Implement `app/Domains/Accounting/Actions/PostJournalCorrectionAction.php` — `lockForUpdate` on correction + original + both drafts, full validation (open fiscal year via `LedgerService::postDraft`, VAT period lock at correction date via `VatPeriodLockService`, balanced lines, active accounts, no concurrent plain-reversal of the original), atomic post of both entries via `LedgerService::postDraft`, dispatches `JournalEntryCorrected` after commit (depends on T031)
- [x] T033 [US1] Implement `app/Domains/Accounting/Actions/CancelJournalCorrectionAction.php` — atomic delete of both unposted drafts + open correction record, audit event retained, only allowed in `draft` status (depends on T031). **Bug fixed during implementation**: the correction row must be deleted before its two `restrictOnDelete`-guarded journal entries, not after — caught by `test_cancel_deletes_both_drafts_and_the_correction`.
- [x] T034 [US1] Add `app/Domains/Accounting/Events/JournalEntryCorrected.php` (event id, original/reversal/replacement UUIDs), dispatched only after DB commit
- [x] T035 [US1] Add `correct` ability to `app/Domains/Accounting/Policies/JournalEntryPolicy.php` requiring `Permission::AccountingEdit`, entry posted, not archived. Deliberately does **not** re-check already-corrected/-reversed state (kept solely in the action layer, see docblock) to avoid two sources of truth drifting apart.
- [x] T036 [US1] Migrate `LedgerService::reverseEntry()` (`app/Domains/Accounting/Services/LedgerService.php`) to also set the structured `reversal_of_entry_id` relationship, keeping the `REV-` reference prefix as display-only text (depends on T028)
- [ ] T037 [US1] Register `journal_entry.corrected` in the webhook-event catalog core-seam from T011 — not started; Phase 2 (T011) does not exist yet, so this event is currently dispatched but not delivered anywhere. Do this once Phase 2 lands, or wire a direct interim listener first if the module is delayed.

**Checkpoint reached**: T022, T024 and the domain-scope part of T023 pass end-to-end against the Actions directly (no HTTP) — 23 new tests green, 83 pre-existing Accounting/Ledger tests still green (no regression), Pint clean, PHPStan clean (0 errors). This is the reusable core both Phase 4 and Phase 5 call into.

---

## Phase 4: User Story 2 — Korrektur-Endpunkte im Accountant-API-Modul (Priority: P1) Track B

**Goal**: `POST/GET/PUT/POST/DELETE` correction endpoints as specified in plan.md:217-234, backed entirely by Phase 3's actions.

**Depends on**: Phase 2 (module must boot) and Phase 3 (actions must exist).

**Independent Test**: Via `gaeld-api`-style HTTP calls against a booted module, prepare → edit replacement → post a correction, and repeat the same `Idempotency-Key` to confirm no duplicate.

### Tests First

- [ ] T038 [P] [US2] Contract tests for `POST /api/v1/journal-entries/{original}/corrections` (explicit lines, VAT short-form, no-payload full copy) in `plugins/accountant-api/tests/Feature/JournalCorrectionPrepareTest.php`
- [ ] T039 [P] [US2] Contract tests for `GET/PUT/POST post/DELETE /api/v1/journal-corrections/{correction}` in `plugins/accountant-api/tests/Feature/JournalCorrectionLifecycleTest.php`
- [ ] T040 [P] [US2] Idempotency-replay and payload-conflict tests (`idempotency_conflict`) in `plugins/accountant-api/tests/Feature/JournalCorrectionIdempotencyTest.php`
- [ ] T041 [P] [US2] Security tests: cross-tenant UUIDs return `404` without existence disclosure, wrong token scope/permission, `source_managed_entry` rejection per excluded module in `plugins/accountant-api/tests/Feature/JournalCorrectionSecurityTest.php`
- [ ] T042 [P] [US2] Concurrency test: parallel correction attempts on the same original in `plugins/accountant-api/tests/Feature/JournalCorrectionConcurrencyTest.php`

### Implementation

- [ ] T043 [US2] Add routes in `plugins/accountant-api/routes/api.php`: `POST journal-entries/{original}/corrections`, `GET|PUT|POST|DELETE journal-corrections/{correction}[/replacement|/post]`
- [ ] T044 [US2] Create `plugins/accountant-api/src/Requests/PrepareJournalCorrectionRequest.php` (`reason`, `correction_date` required; optional explicit/VAT-short-form replacement payload, reusing the extracted validation rules from T030)
- [ ] T045 [US2] Create `plugins/accountant-api/src/Requests/UpdateJournalCorrectionReplacementRequest.php`
- [ ] T046 [US2] Create `plugins/accountant-api/src/Controllers/JournalCorrectionController.php` (`store`, `show`, `updateReplacement`, `post`, `destroy`) calling only Phase 3 actions via `CoreBridge/`, no line-item orchestration in the controller
- [ ] T047 [US2] Create `plugins/accountant-api/src/CoreBridge/JournalCorrectionBridge.php` centralizing the only direct references to core `JournalCorrection`/`JournalEntry` models and Phase 3 actions
- [ ] T048 [US2] Create `plugins/accountant-api/src/Resources/JournalCorrectionResource.php` (status + original/reversal/replacement UUIDs and non-recursive relationship links)
- [ ] T049 [US2] Wire `Idempotency-Key` requirement and stable conflict codes (`journal_entry_already_corrected`, `journal_entry_already_reversed`, `journal_correction_state_conflict`, `journal_correction_concurrent_transition`, `source_managed_entry`, `idempotency_conflict`) in `plugins/accountant-api/src/Controllers/JournalCorrectionController.php`
- [ ] T050 [US2] Register the `correct` ability requirement (from T035) and organization/token scope check via the module's Policy adapter in `plugins/accountant-api/src/Support/AccountantApiPolicyAdapter.php`
- [ ] T051 [US2] Add `journal_entry.corrected` webhook dispatch after commit, referencing T034/T037
- [ ] T052 [US2] Update `contract/api-contract.json` (or the module's own contract doc reconciled per T020) with the five new endpoints

**Checkpoint**: T038-T042 pass against a running module; a prepared-via-API correction is visible identically after reload in the web journal (once Phase 5 ships).

---

## Phase 5: User Story 3 — Weboberfläche für die geführte Korrektur (Priority: P2) Track A

**Goal**: `Korrigieren`-Aktion, Korrekturdialog, Verknüpfungsanzeige. No dependency on Phase 2/4 — only needs Phase 3.

**Independent Test**: Im Browser eine verbuchte manuelle Journalbuchung korrigieren, Ersatzentwurf bearbeiten, gemeinsam verbuchen, und die Verlinkung in Journal und Detailansicht sehen.

### Tests First

- [x] T053 [P] [US3] Frontend/feature tests for the correction dialog, required fields, replacement editing, joint posting, full cancel, error display, and action locking in `tests/Feature/Accounting/JournalCorrectionWebTest.php` — 10 tests, all passing

### Implementation

- [x] T054 [US3] Add web routes in `routes/web/accounting.php`: `POST journal-entries/{journalEntry}/corrections` (prepare), `PUT journal-corrections/{correction}/replacement`, `POST journal-corrections/{correction}/post`, `DELETE journal-corrections/{correction}`
- [x] T055 [US3] Add `prepareCorrection`, `updateCorrectionReplacement`, `postCorrection`, `cancelCorrection` methods to `app/Domains/Accounting/Controllers/AccountingController.php`, calling only Phase 3 actions. Also extended `showJournalEntry`/`journalEntries` to compute `correction`/`correctionRole` context per entry, and to catch the wider global `\DomainException` (not just the app-namespaced one) — needed because `FiscalYearClosedException`/`VatPeriodLockedException` extend the built-in `\DomainException` directly, a pre-existing inconsistency elsewhere in the codebase that would otherwise surface as an uncaught 500 here.
- [x] T056 [US3] Replace the single-entry `Stornieren`-only path with `Korrigieren` for eligible posted entries in `resources/js/Pages/Accounting/JournalEntries.vue` and `resources/js/Pages/Accounting/JournalEntryShow.vue`; `Stornieren` remains available on both for a deliberate plain reversal
- [x] T057 [US3] Build the correction dialog (required `reason` + `correction_date`, explicit intro notice) as `resources/js/Components/Accounting/JournalCorrectionDialog.vue`. **Scope note**: the dialog itself only collects the two required fields, not a side-by-side original/reversal/replacement preview — that comparison happens on the detail page after prepare, per T058.
- [x] T058 [US3] After prepare, redirect directly into the replacement draft's detail page (`JournalEntryShow.vue`), which gained an inline editable-lines section for open replacement drafts, `korrigiert`/`Gegenbuchung`/`Ersatzbuchung` badges, and cross-links between all three entries, in both `JournalEntries.vue` (list) and `JournalEntryShow.vue` (detail)
- [x] T059 [US3] Added translations to all four locales (`lang/{de,en,fr,it}/app.php`), not only German, matching the repo's existing per-locale parity

**Checkpoint reached**: T053's 10 tests plus the existing `ManualJournalEntryTest` (23 tests) and the Phase 3 domain/period suites all pass together, and the full `tests/Feature/Accounting` + `tests/Unit/Accounting` + `tests/Security/Authorization` suites pass at 322 tests / 1441 assertions (the only 2 errors are `ZipArchive` temp-file failures in an unrelated pre-existing export test, not touched by this work). Verified against a temporary, isolated `testing_gmk08` database created and dropped for this check alone, to route around a concurrent session's own test runs against the shared `testing` database without disturbing it. Pint and PHPStan clean on all new/changed files.

Added `app/Domains/Accounting/Policies/JournalCorrectionPolicy.php` during this checkpoint: `tests/Security/Authorization/OrganizationScopedModelPolicyCoverageTest` requires every `BelongsToOrganization` model to resolve a policy, and `JournalCorrection` had none (the actual authorization decision still lives on `JournalEntryPolicy::correct()` against the original entry, per T035 — this policy exists so the model itself has a resolvable authorization surface, matching the coverage test's own documented expectation).

An API-created correction will render identically in this UI once Phase 4 ships (the `correction`/`correctionRole` props are computed from `journal_corrections` alone, with no web-vs-API distinction).

---

## Phase 6: Polish & Cross-Cutting

- [ ] T060 Run `vendor/bin/sail artisan test` for the full Accounting + Api + Plugins suites
- [ ] T061 Run Pint and PHPStan (`vendor/bin/sail composer pint`, `vendor/bin/sail composer phpstan` or repo equivalents)
- [ ] T062 Run the frontend build (`pnpm build`) and a manual smoke pass in the browser
- [ ] T063 Update `plugins/accountant-api/README.md` / module contract doc and `Documents/Gaeld-Journal-Korrektur-Plan.md` status note to reflect what shipped
- [ ] T064 Pilot smoke: one manual, one API-sourced, and one migration-imported journal entry each corrected end-to-end in the dev stack before considering this task list done

---

## Dependencies & Execution Order

- **Setup (Phase 1)**: no dependencies, can start immediately.
- **Foundational (Phase 2 / Etappe 0)**: no dependency on Phase 1 completion but shares its test scaffolding; **blocks Phase 4 only**.
- **Phase 3 (Domain, Track A)**: no dependency on Phase 2; can run fully in parallel with it.
- **Phase 4 (Module API, Track B)**: depends on Phase 2 **and** Phase 3.
- **Phase 5 (Web UI, Track A)**: depends on Phase 3 only; can run in parallel with Phase 2 and Phase 4.
- **Phase 6 (Polish)**: depends on whichever of Phase 4/5 are in scope for this delivery slice.

### Suggested sequencing for a single implementer

1. Phase 1 (small, fast)
2. Phase 3 (domain core — unlocks both later phases and is independently testable/demoable via Tinker/tests)
3. Phase 5 (web UI — smallest remaining slice to reach a usable, demoable feature without the module)
4. Phase 2 (module foundation — largest phase, no functional payoff on its own)
5. Phase 4 (module API — payoff arrives immediately once Phase 2 lands)
6. Phase 6

This order gets a working, demoable web correction flow (Phases 1+3+5) before committing to the larger Phase 2 module foundation, while keeping Track A and Track B commits strictly separated as plan.md requires.

---

## Commit- und Rollback-Strategie (Fallback)

**Commits**: Ein Commit pro abgeschlossenem Task oder pro eng zusammengehöriger
Task-Gruppe (z. B. T025+T027 Migration+Model zusammen), nie ein Sammelcommit
über eine ganze Phase. Track A und Track B landen nie im selben Commit (siehe
plan.md `Liefer- und Git-Strategie`). Jeder Commit muss für sich lauffähig
sein: Migration mit funktionierendem `down()`, zugehöriger Test grün, Pint/
PHPStan sauber — kein Commit, der einen kaputten Zwischenzustand hinterlässt.
Damit ist jeder Commit ein potenzieller Rollback-Punkt, nicht nur die
Phasen-Checkpoints.

**Rollback-Ebenen**:

1. **Task-Ebene**: `git revert` des einzelnen Commits, falls ein Test oder
   eine manuelle Prüfung nach dem Commit ein Problem zeigt.
2. **Migrations-Ebene**: T025/T026 (und jede weitere Migration) werden vor dem
   Commit lokal mit `migrate` **und** `migrate:rollback` gegen den
   `gaeld-dev-pgsql-1`-Stack durchgespielt (nie gegen `gaeld-postgres-1`,
   siehe bestätigte Umgebungsregel). Erst wenn beide Richtungen sauber
   laufen, gilt die Migration als committable.
3. **Checkpoint-Ebene**: Die Checkpoints am Ende jeder Phase sind die
   vorgesehenen Stellen, um bei Bedarf auf den letzten grünen Checkpoint
   zurückzuspringen (`git reset`/`revert` bis zum Checkpoint-Commit), ohne
   bereits abgeschlossene frühere Phasen zu berühren — möglich, weil Track A
   (Phase 3, 5) und Track B (Phase 2, 4) strikt getrennte Commit-Ströme sind.
4. **Rollout-Ebene (Produktion)**: Die Deployment-Allow-List aus T008 ist der
   produktive Fallback-Schalter — das Modul (Phase 4) lässt sich ohne
   Schema-Rollback sofort deaktivieren. Schema-Änderungen aus Phase 3
   (`journal_corrections`, `reversal_of_entry_id`) sind rein additiv und
   wirken ohne aktives Modul nicht auf bestehende Abläufe; ein Rollback in
   Produktion bedeutet daher in der Regel "Modul deaktivieren", nicht
   "Migration zurückrollen".

**Kein** Commit vor grünem Test der zugehörigen "Tests First"-Aufgabe dieser
Phase — Tests, die vor der Implementierung fehlschlagen, werden nicht
mitcommittet, bevor sie grün sind (Ausnahme: ein bewusster
"WIP: failing test"-Zwischenschritt, wenn du das ausdrücklich so willst).
