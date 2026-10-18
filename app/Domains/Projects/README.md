# Projects Domain

Projects, areas and the allocation of journal lines to projects and people
(spec `specs/009-projects-and-people`).

## Scope

- **Projects**: `Project` is a cost centre (`cost_centers`) seen as an area,
  customer project or internal block, with status, customer, person
  responsible, target date and expected amount. The analytical module keeps
  working on the same records through `CostCenter`.
- **Allocations**: `LineAllocation` (`line_allocations`) gives a share of a
  transaction line to a project and/or an employee. Shares are percentages;
  a line may be allocated up to 100 %.
- **Review list**: `AllocationReview` collects entries whose lines were
  rebuilt and whose old allocations had no clear successor line.
- **Report**: `ProjectReportService` sums revenue and expense lines by their
  allocated share, rolls up sub-projects and leaves out year-end closing.
- **Coverage** (spec `specs/010-projects-simple`): `AllocationCoverageService`
  puts every revenue and expense line of a year into assigned, general (cost
  block or the internal job `ALLG`), payroll (class 5 without block) or open,
  leaving out reversals and posted corrections like the journal's "hide
  corrections". The allocate inbox lists the open ones.

In the interface an area is called a project and a project a job; the code
keeps the names of spec 009.

## Rules

- Nothing here writes `transaction_lines`, `journal_entries` or
  `vat_entries`, and nothing asks whether an entry is locked. Allocations are
  reporting facts; `AllocationLedgerInvarianceTest` proves the books stay the
  same.
- Every path that copies or rebuilds lines passes allocations on through
  `JournalLineData::$allocations` (`null` = say nothing, `[]` = clear):
  `LedgerService::persistEntry` and `reverseEntry`, `UpdateJournalDraftAction`,
  `AmendPostedJournalEntryAction`, `PrepareJournalCorrectionAction`. Where a
  caller says nothing, `LineAllocationService::inherit()` matches old to new
  lines and never guesses.
- The module is enforced per organization by `EnsureProjectsModule`; without
  it, journal answers stay exactly as they were.

## Permissions

`projects.view`, `projects.manage`, `projects.allocate`. Owner, admin and
accountant hold all three; member and viewer may view; employee none.
