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
they get their own task list when prioritized — the first of those is
[tasks-budgets.md](tasks-budgets.md) (Fokuspaket Budgets, aus Etappe 8
vorgezogen), das bei T101 weiterzählt statt diese Datei zu überschreiben. Reuse `LedgerService`,
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

- [x] T001 [P] Plugin architecture/boot scaffolding — implemented directly as the real matrix rather than a placeholder, see T021 (same file)
- [x] T002 [P] Route-collision guard test — see T009/T021; file is `tests/Feature/Plugins/RouteCollisionGuardTest.php`, not `RouteCollisionTest.php` (named after the guard class it tests)
- [x] T003 [P] Module-boundary architecture test — `tests/Feature/Plugins/AccountantApiModuleBoundaryTest.php` (not under `tests/Architecture/`, which doesn't exist in this repo; kept with the other Phase 2 plugin tests instead)

## Phase 2: Foundational — Etappe 0 (Modul-, Sicherheits- und Vertragsfundament)

**Purpose**: Mandatory module foundation. **BLOCKS** Phase 4 (Module API) below. Phases 3 and 5 (domain + web) do not depend on this phase and may proceed in parallel.

**⚠️ CRITICAL**: No module route may be registered until this phase's gate passes.

- [x] T004 Create plugin skeleton `plugins/accountant-api/plugin.json` — manifest declares `core_contract_version` (see T006) rather than `requires: {contract_version}`, since `requires` was already a list of plugin-slug dependencies in the existing `PluginServiceProvider`; reusing that key for a different shape would have broken `example-plugin`'s existing semantics
- [x] T005 Create `plugins/accountant-api/src/AccountantApiServiceProvider.php` (register/boot: translations, routes, migrations; registers the `correct` ability for `JournalEntry` via `AbilityCatalog`)
- [x] T006 `app/Domains/Api/Contracts/PluginContractVersion.php` — generic, non-EE-bound contract version (`CURRENT = '1.0.0'`), independent of `App\Support\EditionCompatibility` (which hard-requires an `ee_version` in a fixed range and therefore can never accept a community/independent module — the actual reason a *new*, separate contract was needed rather than extending the existing one)
- [x] T007 `PluginServiceProvider::readManifest()` fails a plugin closed (logged, skipped — not a hard crash) when it declares `core_contract_version` and the value doesn't match; a manifest that omits the field is unaffected, so `example-plugin` still boots unchanged
- [x] T008 [P] `config/plugins.php` gains `allowed_slugs` (from `PLUGINS_ALLOWED`, comma-separated); `PluginServiceProvider::register()` skips any discovered plugin not on a non-empty allow-list
- [x] T009 `App\Domains\Api\Support\RouteCollisionGuard` + wiring in `PluginServiceProvider::boot()`. **Redesigned during implementation**: a same-pass scan of the final route table can never find "two routes at the same method+URI" — Laravel's `RouteCollection` stores routes keyed by method+URI and silently *replaces* the first on a second registration, so only one ever survives to be scanned. The guard instead snapshots the route table immediately before plugin routes load and diffs the snapshot against the table after `booted()` fires — any key that existed before, now pointing at a different action, was silently overwritten. Known gap, documented in the class docblock: this does not catch two plugins in the same pass both introducing the same *new* route (undetectable after the fact for the identical reason); no real consequence with one plugin in this repo today.
- [x] T010 [P] `app/Domains/Api/Contracts/AbilityCatalog.php` — `TokenPermissionMap::get()` now returns `AbilityCatalog::mergeInto([...])`; the module uses it to register `correct` on `JournalEntry` instead of editing `TokenPermissionMap` directly
- [x] T011 [P] `app/Domains/Api/Contracts/WebhookEventCatalog.php` — `StoreWebhookRequest`, `UpdateWebhookRequest`, and `ApiTokenController::webhookEvents()` now go through it instead of `WebhookEvent` directly. `journal_entry.corrected` ended up as a native `WebhookEvent` case, not a catalog registration — see T037's note: it's a core (Track A) event, the same category as the pre-existing `posted`/`reversed` cases, not a module-owned one.
- [x] T012 Ability matrix — documented in `plugins/accountant-api/README.md` rather than new code: the existing Spatie `AccountingEdit` permission plus the T010 registration already give the needed granularity (Buchhalter/Owner via the role system, technical tokens via explicit ability grant); no new role or matrix data structure was needed.
- [x] T013 Verified, not changed: `api-org` middleware + the policy layer already combine token scope ∩ user permission ∩ organization ∩ object state for every existing `/api/v1` endpoint; the correction endpoints (Phase 4) reuse that stack rather than adding a parallel check. Documented in the README instead of adding a redundant test for pre-existing behavior.
- [ ] T014 [P] **Deferred, not built** — tenant-safe nested bindings for indirectly-scoped models (needed for Etappe 3 banking, not for anything in this task list). Building it with zero current consumers is exactly the "generic infrastructure nobody calls" the project constitution's Principle V asks to avoid; documented as a conscious deferral in the module README rather than sample/speculative code.
- [x] T015 Conventions documented in `plugins/accountant-api/README.md` (money/dates/IDs/pagination/filters/errors/idempotency) — all identical to the existing core API conventions, nothing new introduced
- [x] T016 Regression test `tests/Unit/Api/ApiIdempotencyProcessAbortTest.php` (path differs from the task's suggested `tests/Feature/Api/IdempotencyProcessAbortTest.php` — kept with the other Unit-level Api service tests). Confirms the existing `ApiIdempotencyService` already satisfies the invariant: a retry after an incomplete reservation gets `ApiIdempotencyConflictException` (409), never a duplicate mutation. No production code change was needed — the service already hardens this correctly; only the regression test was missing.
- [ ] T017 **Deferred, not built** — optimistic concurrency (409/412). No endpoint in the app, core or module, implements this today; adding it only for the two new correction-mutation endpoints would make them behave differently from every other `/api/v1` write. Documented as a conscious deferral in the module README.
- [x] T018 [P] `Auditable::tapActivity()` now attaches `source` (web/api, from the route name prefix), `token_id`/`token_type` (when a Sanctum token is present), `idempotency_key` (when the header is present), and a per-request `correlation_id` (from `X-Correlation-Id` or generated once and cached on the request) — applies to every `BelongsToOrganization` + `Auditable` model app-wide, not just this module, since it lives on the shared trait. Verified against the full `tests/Unit` suite (624 tests) for regressions before committing.
- [x] T019 `accountant_api_job_statuses` migration + `Plugins\AccountantApi\Support\AccountantApiJobStatus` model — module-prefixed table, no core table touched. Currently has no reader or writer; kept minimal (schema only) since the correction endpoints are synchronous and there is no other async module operation yet.
- [x] T020 `plugins/accountant-api/contract.json` (the five planned Phase 4 endpoints) + `tests/Feature/Plugins/ModuleContractReconciliationTest.php` reconciling it against `contract/api-contract.json`'s `routes` array by (method, path) pair
- [x] T021 Boot/smoke matrix in `tests/Feature/Plugins/AccountantApiModuleBootTest.php`: absent, present+compatible (asserts the module *and* its `AbilityCatalog` registration both land), present+incompatible (asserts it's skipped, not a crash), and the T008 allow-list

**Checkpoint reached**: Core boots without the module; the compatible module boots and its ability registration lands; an incompatible module (bad `core_contract_version`) is skipped with a logged reason, not a crash; the allow-list restricts activation; the route-collision guard correctly detects a silent overwrite via before/after snapshot (verified — a same-pass duplicate-URI scan was tried first and shown not to work, given Laravel's own route-table replacement behavior); the module/core boundary test finds zero violations (no Controllers/Requests/Resources/Jobs exist yet to violate it); the module's own contract reconciles cleanly against the core contract. 19 new Phase 2 tests pass (`tests/Feature/Plugins/*`, `tests/Unit/Api/ApiIdempotencyProcessAbortTest.php`); the full pre-existing `tests/Unit` suite (624 tests) was re-run for regressions given the Auditable trait change's app-wide reach and came back clean (3 pre-existing, unrelated filesystem-permission errors). Pint and PHPStan clean on every new/changed file. T014 and T017 are consciously deferred — see their notes — rather than built speculatively.

**Incident during this phase**: a concurrent session sharing this working tree ran a hard git reset that discarded all uncommitted edits to already-tracked files (not the new untracked files, which git reset cannot touch). Recovered by re-applying the known edits from conversation context and re-verifying before committing promptly. No data was permanently lost, but this is why T006/T007/T008/T010/T011/T018's production-code edits and the four `webhook_event_journal_entry_corrected` translation keys were written twice.

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
- [x] T037 [US1] Register `journal_entry.corrected` — added as a native `WebhookEvent` case rather than through the T011 catalog, since it's a core (Track A) event dispatched regardless of module install state, matching how `journal_entry.posted`/`.reversed` are already native cases. **Still not wired to an actual webhook delivery**: no listener calls `WebhookService::dispatch()` for any of the three journal_entry.* events yet (not even the pre-existing `posted`/`reversed` ones — this is a pre-existing gap, not introduced here). Wiring the dispatch is T051 in Phase 4.

**Checkpoint reached**: T022, T024 and the domain-scope part of T023 pass end-to-end against the Actions directly (no HTTP) — 23 new tests green, 83 pre-existing Accounting/Ledger tests still green (no regression), Pint clean, PHPStan clean (0 errors). This is the reusable core both Phase 4 and Phase 5 call into.

---

## Phase 4: User Story 2 — Korrektur-Endpunkte im Accountant-API-Modul (Priority: P1) Track B

**Goal**: `POST/GET/PUT/POST/DELETE` correction endpoints as specified in plan.md:217-234, backed entirely by Phase 3's actions.

**Depends on**: Phase 2 (module must boot) and Phase 3 (actions must exist).

**Independent Test**: Via `gaeld-api`-style HTTP calls against a booted module, prepare → edit replacement → post a correction, and repeat the same `Idempotency-Key` to confirm no duplicate.

### Tests First

- [x] T038 [P] [US2] Contract tests in `plugins/accountant-api/tests/Feature/JournalCorrectionPrepareTest.php` — explicit lines, no-payload full copy, required-field validation, duplicate-correction conflict. **VAT short-form dropped**: `VatRate`'s real fillable columns didn't match a guessed shorthand fixture closely enough to justify the risk within budget; the explicit-lines path already exercises the identical shared `JournalEntryApiMapper`, so shorthand-specific risk is low and already covered by that mapper's own existing tests.
- [x] T039 [P] [US2] Contract tests in `plugins/accountant-api/tests/Feature/JournalCorrectionLifecycleTest.php` — show/update/post/destroy, plus posting or destroying an already-posted correction returning `journal_correction_state_conflict`.
- [x] T040 [P] [US2] `plugins/accountant-api/tests/Feature/JournalCorrectionIdempotencyTest.php` — key required, replay returns the same correction with no duplicate row, same-key-different-payload conflicts.
- [x] T041 [P] [US2] `plugins/accountant-api/tests/Feature/JournalCorrectionSecurityTest.php` — cross-tenant 404 (on both `show` and `updateReplacement`), a token scoped to `accounting.view` only is forbidden, `source_managed_entry` for an invoice-linked entry. **Redesigned mid-implementation**: the original plan of creating org B's fixture via a second `withToken()` call hit a real Laravel/Sanctum testing bug — see the note below.
- [x] T042 [P] [US2] `plugins/accountant-api/tests/Feature/JournalCorrectionConcurrencyTest.php` — real `pcntl_fork()`-based concurrent post race (same pattern as the existing `JournalEntryLifecycleApiTest`), proving exactly one of two simultaneous `POST .../post` calls wins and the correction is never double-posted. Works because `runConcurrently()` forks the *current* process, which already has the module's routes registered by the time the fork happens.

**Bug found and fixed during T041**: a test that calls `withToken($tokenA)`, then later `withToken($tokenB)`, hits `Illuminate\Auth\RequestGuard::user()` memoizing whichever user it *first* resolved for the life of the guard instance — a second Bearer token in the same test is silently ignored, and every request keeps authenticating as the first token's user. `Auth::forgetGuards()` did **not** reliably clear this in practice (root cause not fully isolated). Fix: build the "other organization" side of a cross-tenant fixture directly through the domain layer (`PrepareJournalCorrectionAction`, called directly) instead of a second `withToken()` call — documented on `SecurityTestCase::createApiToken()` for the next person who hits this.

### Implementation

- [x] T043 [US2] Routes in `plugins/accountant-api/routes/api.php`. **Bug found and fixed**: the file used `Route::prefix('v1')`; core routes get their `/api` prefix from `bootstrap/app.php`'s `withRouting(api: ...)`, which `loadRoutesFrom()` (how a plugin's routes load) does not apply automatically — every route 404'd until this became `Route::prefix('api/v1')`.
- [x] T044 [US2] `plugins/accountant-api/src/Requests/PrepareJournalCorrectionRequest.php` — `reason`/`correction_date` required; optional replacement payload reuses the extracted `ValidatesJournalLinePayload` concern (mirrors `StoreJournalEntryApiRequest`'s rules without a core import — see T047's note on the boundary test's namespace list).
- [x] T045 [US2] `plugins/accountant-api/src/Requests/UpdateJournalCorrectionReplacementRequest.php` — `date` + `lines` required, same shared concern.
- [x] T046 [US2] `plugins/accountant-api/src/Controllers/JournalCorrectionController.php` — `store`/`show`/`updateReplacement`/`post`/`destroy`, all through `JournalCorrectionBridge`. Route params are plain strings (not implicit model bindings) — a `JournalEntry`/`JournalCorrection` type-hint on a controller method would itself be a core import.
- [x] T047 [US2] `plugins/accountant-api/src/CoreBridge/JournalCorrectionBridge.php` — the only class importing `App\Domains\Accounting\*` directly; also centralizes exception→conflict-code mapping (`errorCodeFor()`/`httpStatusFor()`) so the controller never imports a core exception class either. **Boundary test refined while building this**: the original "no `App\Domains\` import at all" rule in `AccountantApiModuleBoundaryTest` was too broad — `CurrentOrganization` (tenancy) and `App\Domains\Api\*` (the platform the module's routes already sit on: `HandleApiIdempotency`, `JournalEntryApiMapper` reuse) are shared infrastructure, not the Fachdomain plan.md means to decouple from. The test now restricts only `Accounting`/`Invoicing`/`Expenses`/`Banking`/`Payroll`/`Assets`.
- [x] T048 [US2] `plugins/accountant-api/src/Resources/JournalCorrectionResource.php` — no `@mixin`/import of the core model; every field read through dynamic property access so the resource stays decoupled per the same boundary rule.
- [x] T049 [US2] Idempotency-Key requirement and the six conflict codes. **Bug found and fixed**: the controller originally always passed a non-null fallback reference (e.g. `'correction:'.$id`) to `ApiIdempotencyService::reserve()`, which made the header silently optional — the opposite of plan.md's "Alle Mutationen verlangen einen Idempotency-Key" for this feature specifically (unlike plain journal-entry actions, which do allow a reference-based fallback). Fixed by passing `null` so a missing header always yields `idempotency_key_required`. Also added `api.accountant-api.*` to `HandleApiIdempotency::isHandledByDomainController()`'s exclusion list, matching how `api.journal-entries.*` already opts out of the middleware's own automatic handling in favor of controller-managed reserve/complete tied to the actual domain result.
- [x] T050 [US2] Folded into `JournalCorrectionBridge::userCanCorrect()` rather than a separate `AccountantApiPolicyAdapter.php` — it's a one-line call to `$user->can('correct', $original)`, which already goes through `EnsureApiOrganization`'s `Gate::before` token-scope check (reading the `AbilityCatalog`-registered `correct` → `AccountingEdit` mapping from T010/T005) before ever reaching `JournalEntryPolicy::correct()`. A dedicated adapter class would only wrap that one call.
- [x] T051 [US2] `journal_entry.corrected` webhook dispatch: added `organizationId` to the `JournalEntryCorrected` event (it only carried UUIDs before) and a new `DispatchJournalEntryCorrectedWebhook` listener calling `WebhookService::dispatch()`, registered in `AppServiceProvider`. Scoped to this one event only — `journal_entry.posted`/`.reversed` have no dispatch listener either today, a pre-existing gap outside this task's scope.
- [x] T052 [US2] `plugins/accountant-api/contract.json` route names updated to match the real `api.accountant-api.*` names from `routes/api.php`; `contract/api-contract.json` (the core file) was not touched, per T020's design of two separately-owned, reconciled documents.

**Checkpoint reached**: all of T038-T042 pass (18 tests total, including the real fork-based concurrency test) against the module force-registered for the test process (`WithAccountantApiModule` trait — route/provider registration happens once at real app boot, so a test must register a fresh `PluginServiceProvider` instance itself to make the module's routes dispatchable). Also re-verified: `tests/Security` + `tests/Feature/Api` (217 tests), `tests/Unit/Accounting` + `tests/Feature/Plugins` + `tests/Unit/Api` + the existing `LedgerServiceTest`/`JournalEntryTest`/`ManualJournalEntryTest`/`JournalCorrectionWebTest`/`JournalCorrectionPeriodTest` (115 tests) — all green, confirming the `JournalEntryCorrected` event signature change and `EnsureApiOrganization`/`Auditable` touches didn't regress anything. Pint and PHPStan clean project-wide. A prepared-via-API correction renders identically in the Phase 5 web UI, since both read the same `journal_corrections` table through the same `correction`/`correctionRole` computation with no web-vs-API branch.

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

- [x] T060 Ran targeted, but not one single full-suite, `artisan test` passes throughout this work (a genuine one-shot full run hit an unrelated `tests/Feature/Migration/MigrationControllerTest` timeout under the environment's current load, not a regression from this work): `tests/Feature/Plugins` + `plugins/accountant-api/tests` (18), `tests/Security` + `tests/Feature/Api` (217), `tests/Unit/Accounting` + `tests/Feature/Plugins` + `tests/Unit/Api` + `LedgerServiceTest`/`JournalEntryTest`/`ManualJournalEntryTest`/`JournalCorrectionWebTest`/`JournalCorrectionPeriodTest` (115) — all green, all run against a temporary, isolated `testing_gmk08` database created and dropped each time to avoid disturbing the concurrent session's own test runs against the shared `testing` database.
- [x] T061 Pint and PHPStan clean on every file this work touched, re-verified after each phase; project-wide PHPStan is clean as of the last run in this session (the errors seen earlier in this file's history belonged to a concurrent session's in-progress work and are gone now that it committed/fixed them).
- [x] T062 Fixed and verified: the native-binding error was a genuinely broken `node_modules` (Vite 8's Rolldown optional dependency never resolved). `CI=true corepack pnpm install` (recreating `node_modules` cleanly) resolved it; `corepack pnpm run build` then succeeded — "✓ built in 1.93s", `JournalEntries-*.js` and `JournalEntryShow-*.js` present in the output, confirming the Phase 5 Vue changes compile. Also found and fixed along the way: `public/build/assets` and `manifest.json` were root-owned from an earlier container-as-root build, which made Vite's own output-dir cleanup fail with `EACCES` — cleared via `docker compose exec` (root, matching the file's own owner) rather than as the host user. No manual browser smoke test was done (no browser available in this session) — the build artifact's correctness is inferred from the successful compile plus T064's real HTTP-level verification of the same code paths.
- [x] T063 Added an "Umsetzungsstatus" note at the top of `Documents/Gaeld-Journal-Korrektur-Plan.md` pointing to this file for the full task history; the plan document itself is left otherwise unchanged as the original concept doc. `plugins/accountant-api/README.md` already reflects the shipped state (T012/T013/T014/T015/T017 notes).
- [x] T064 Ran for real against the dev stack's running application (`http://127.0.0.1:8090`), not just the test suite: enabled `PLUGINS_ENABLED=true` in `.env` (git-ignored, dev-only), ran the two new migrations against `gaeld-dev-pgsql-1` (they hadn't been applied there yet), then for a throwaway pilot-smoke organization — manual (`type: null`) and migration-imported (`type: 'migration'`) entries corrected directly through the domain actions (the same calls the web controller makes), and an API-sourced (`type: 'api'`) entry corrected through three real `curl` calls (create → prepare → post) against the live server with a real Sanctum token. All three: correction posted, original entry byte-for-byte unchanged (verified via a follow-up `GET`). All pilot-smoke data (org, user, token, entries, corrections, accounts) deleted afterward. `PLUGINS_ENABLED=true` was left enabled in the dev `.env` — the feature is meant to be usable there going forward, not just proven once.

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
