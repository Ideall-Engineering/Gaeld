# Accountant API Module

Extends the core `/api/v1` with the guided journal correction endpoints
(`specs/007-accountant-api/plan.md`, Etappe 2 "Fokusdesign — Geführte
Journal-Korrektur"), and is the landing place for the rest of that plan's
etappen as they are built. It is a thin transport layer: controllers here
call only core Domain-Actions and Query-Services, through `CoreBridge/`
adapters where a direct core dependency is unavoidable. Fachlogik and
fachliche Daten stay in the core.

## Conventions (plan.md "Etappe 0", T015)

These match the core API's own conventions — nothing new is introduced for
this module specifically:

- **Money**: decimal strings (`"123.45"`), never floats.
- **Dates**: ISO-8601 (`YYYY-MM-DD` for dates, RFC 3339 for timestamps).
- **Public IDs**: UUIDs. Internal auto-increment IDs (e.g. `accounts.id`) are
  never exposed; `Account.uuid` is used instead where a public reference is
  needed.
- **Pagination**: `per_page` / `page` query params; response shape
  `{ "data": [...], "links": {...}, "meta": {...} }` for lists, `{ "data":
  {...} }` for a single record — identical to `app/Domains/Api`.
- **Filters**: `filter.<field>` query params, exact-match only unless a
  route documents otherwise.
- **Errors**: `{ "message": "...", "code": "machine_readable_code",
  "errors": {...} }` (the last key only for 422 validation errors) — the
  same shape as the core API, so a client cannot tell module and core errors
  apart by structure.
- **Idempotency**: every mutating endpoint requires `Idempotency-Key` and
  goes through the existing, generic `HandleApiIdempotency` middleware /
  `ApiIdempotencyService` — no module-specific idempotency mechanism.

## Authorization (plan.md T012/T013)

No new roles or a separate ability matrix were introduced. The existing
Spatie permission (`AccountingEdit`) and the existing role system already
give an organization the granularity plan.md asks for:

- **Buchhalter/Owner** (or any role granted `AccountingEdit`): can prepare,
  edit, post, and cancel corrections — same permission the web UI already
  requires for `Korrigieren`.
- **Technical organization tokens**: only gain the `correct` ability when
  explicitly issued with it; see `AccountantApiServiceProvider::registerAbilities()`,
  which adds `correct` to `App\Domains\Api\Contracts\AbilityCatalog` for
  `JournalEntry` (a core model) precisely because this module owns the only
  endpoints that ability guards.
- Token scope ∩ user permission ∩ organization ∩ object state are already
  enforced together by the existing `api-org` middleware + policy layer used
  by every other `/api/v1` endpoint; the correction endpoints reuse that
  same stack rather than adding a parallel check.

## Deliberately deferred (T014, T017)

Two Etappe-0 items have no code yet, by design — building them now would be
exactly the kind of "generic infrastructure nobody calls" the project
constitution asks to avoid:

- **Tenant-safe nested bindings for indirectly-scoped models** (plan.md:
  needed for Etappe 3 banking resources like bank transactions/matches that
  are scoped through a parent account). No endpoint in this module needs
  one yet; add the generic resolver together with the first endpoint that
  does.
- **Optimistic concurrency (409/412 on concurrent edit)**: no endpoint in
  the app — core or module — implements this today. Introducing it only for
  the correction endpoints would make them behave differently from every
  other `/api/v1` write. Revisit as a cross-cutting core-seam if concurrent
  editing of the same record becomes a real, observed problem.

## Job status table (T019)

`accountant_api_job_statuses` exists (see the migration) for a future async
module operation. The correction endpoints are synchronous and do not use
it. Module migrations use the `accountant_api_` prefix and touch no core
table.
