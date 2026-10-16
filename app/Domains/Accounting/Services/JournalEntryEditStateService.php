<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\Constants\AccountCode;
use App\Domains\Accounting\Enums\JournalEntryEditState;
use App\Domains\Accounting\Enums\PostedEntryEditMode;
use App\Domains\Accounting\Exceptions\JournalEntryNotEditableException;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalCorrection;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Rules\NotSystemReference;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Support\Carbon;

/**
 * Decides whether a posted journal entry may be amended or deleted in place.
 *
 * The lock on a posted entry follows the events that freeze its period, not
 * the act of posting: a VAT settlement, a closed fiscal year and the legal
 * archive. Entries that another workflow owns or that the system generated
 * stay locked regardless of their period, because changing them here would
 * leave their source document out of step with the ledger.
 *
 * This is the single source of truth for the policy, both write actions,
 * the web list and the API resource.
 */
class JournalEntryEditStateService
{
    /**
     * References the system writes itself and that must not drift by hand.
     * Users cannot enter them either ({@see NotSystemReference}).
     */
    public const SYSTEM_REFERENCE_PREFIXES = [
        'OPENING-',
        'REV-',
        'VAT-SETTLEMENT-',
        'DISP-',
    ];

    /** Entry types that summarise or carry forward other entries. */
    private const SYSTEM_TYPES = [
        'historical_summary',
        'reversal',
    ];

    /** @var array<string, bool> */
    private array $flexibleByOrganization = [];

    /** @var array<string, JournalEntryEditState|null> */
    private array $periodStates = [];

    public function __construct(
        private VatPeriodLockService $vatPeriodLocks,
        private LedgerService $ledger,
        private JournalCorrectionEligibilityService $eligibility,
    ) {}

    public function stateFor(JournalEntry $entry): JournalEntryEditState
    {
        if (! $entry->is_posted) {
            return JournalEntryEditState::Draft;
        }

        if ($entry->archived_at !== null) {
            return JournalEntryEditState::Archived;
        }

        $periodState = $this->periodStateFor($entry->organization_id, $entry->date->toDateString());
        if ($periodState !== null) {
            return $periodState;
        }

        if (! $this->isFlexible($entry->organization_id)) {
            return JournalEntryEditState::Strict;
        }

        if ($this->isCorrectionBound($entry)) {
            return JournalEntryEditState::CorrectionBound;
        }

        if ($this->eligibility->isSourceManaged($entry)) {
            return JournalEntryEditState::SourceManaged;
        }

        if ($this->isSystemGenerated($entry)) {
            return JournalEntryEditState::SystemGenerated;
        }

        if ($entry->lines()->whereNotNull('lettrage_key')->exists()) {
            return JournalEntryEditState::Lettered;
        }

        return JournalEntryEditState::Open;
    }

    /**
     * @throws JournalEntryNotEditableException When the entry or its new date is locked
     */
    public function assertAmendable(JournalEntry $entry, ?string $newDate = null): void
    {
        $state = $this->stateFor($entry);

        if (! $state->isAmendable()) {
            throw new JournalEntryNotEditableException($state);
        }

        if ($newDate === null || $newDate === $entry->date->toDateString()) {
            return;
        }

        // Moving an entry into a frozen period would change that period just
        // as much as editing an entry that already sits in it.
        $targetState = $this->periodStateFor($entry->organization_id, $newDate);
        if ($targetState !== null) {
            throw new JournalEntryNotEditableException($targetState, $newDate);
        }
    }

    /**
     * @throws JournalEntryNotEditableException When the entry is locked
     */
    public function assertDeletable(JournalEntry $entry): void
    {
        $state = $this->stateFor($entry);

        if (! $state->isAmendable()) {
            throw new JournalEntryNotEditableException($state);
        }
    }

    /**
     * The first date on or after the given one that no settled VAT period or
     * closed fiscal year covers: where a correction of a locked entry can be
     * booked without reopening anything.
     */
    public function firstOpenDateOnOrAfter(string $organizationId, string $date): string
    {
        $candidate = Carbon::parse($date)->startOfDay();

        // Each step jumps past one locked period, so a handful of steps
        // covers any realistic run of settled quarters and closed years.
        for ($step = 0; $step < 24; $step++) {
            $state = $this->periodStateFor($organizationId, $candidate->toDateString());

            if ($state === null) {
                return $candidate->toDateString();
            }

            $lockEnd = $state === JournalEntryEditState::VatLocked
                ? $this->vatLockEnd($organizationId, $candidate->toDateString())
                : $this->fiscalYearEnd($organizationId, $candidate->toDateString());

            $candidate = ($lockEnd ?? $candidate)->copy()->addDay();
        }

        return $candidate->toDateString();
    }

    /**
     * Whether any line of the given set books onto an account that is
     * linked to a bank account, so changing its amount would make the
     * ledger disagree with the bank statement.
     *
     * @param  array<int, string|int>  $accountIds
     */
    public function touchesBankAccount(string $organizationId, array $accountIds): bool
    {
        if ($accountIds === []) {
            return false;
        }

        return BankAccount::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereIn('account_id', $accountIds)
            ->exists();
    }

    /**
     * Results are memoised per instance, so a list can ask for many entries
     * of the same organization without repeating the same lookups. Resolve
     * a fresh instance for each request.
     */
    public function isFlexible(string $organizationId): bool
    {
        return $this->flexibleByOrganization[$organizationId] ??= Organization::query()
            ->whereKey($organizationId)
            ->first(['id', 'posted_entry_edit_mode'])
            ?->posted_entry_edit_mode === PostedEntryEditMode::Flexible;
    }

    /**
     * The lock a date falls under, independent of the entry on it.
     *
     * The VAT check is deliberately date-based: the model hooks on
     * `VatEntry` only fire for entries that carry VAT lines, which would let
     * an amendment slip into a settled quarter.
     */
    private function periodStateFor(string $organizationId, string $date): ?JournalEntryEditState
    {
        $key = $organizationId.'|'.$date;
        if (array_key_exists($key, $this->periodStates)) {
            return $this->periodStates[$key];
        }

        return $this->periodStates[$key] = $this->resolvePeriodState($organizationId, $date);
    }

    private function resolvePeriodState(string $organizationId, string $date): ?JournalEntryEditState
    {
        if ($this->ledger->isFiscalYearClosedOn($organizationId, $date)) {
            return JournalEntryEditState::FiscalYearClosed;
        }

        if ($this->vatPeriodLocks->isLocked($organizationId, $date)) {
            return JournalEntryEditState::VatLocked;
        }

        return null;
    }

    private function vatLockEnd(string $organizationId, string $date): ?Carbon
    {
        $end = JournalEntry::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('type', 'vat_settlement')
            ->where('is_posted', true)
            ->whereDate('vat_period_start', '<=', $date)
            ->whereDate('vat_period_end', '>=', $date)
            ->max('vat_period_end');

        return $end === null ? null : Carbon::parse($end);
    }

    private function fiscalYearEnd(string $organizationId, string $date): Carbon
    {
        $fiscalYear = FiscalYear::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->forDate($date)
            ->first();

        return $fiscalYear?->end_date?->copy() ?? Carbon::parse($date)->endOfYear();
    }

    private function isCorrectionBound(JournalEntry $entry): bool
    {
        if ($entry->reversal_of_entry_id !== null) {
            return true;
        }

        $isReversed = JournalEntry::withoutGlobalScopes()
            ->where('organization_id', $entry->organization_id)
            ->where(fn ($query) => $query
                ->where('reversal_of_entry_id', $entry->id)
                // Older reversals are linked by their `REV-` reference only.
                ->when($entry->reference !== null, fn ($inner) => $inner
                    ->orWhere('reference', 'REV-'.$entry->reference)))
            ->exists();

        if ($isReversed) {
            return true;
        }

        return JournalCorrection::withoutGlobalScopes()
            ->where(fn ($query) => $query
                ->where('original_journal_entry_id', $entry->id)
                ->orWhere('reversal_journal_entry_id', $entry->id)
                ->orWhere('replacement_journal_entry_id', $entry->id)
            )
            ->exists();
    }

    private function isSystemGenerated(JournalEntry $entry): bool
    {
        if (in_array($entry->type, self::SYSTEM_TYPES, true)) {
            return true;
        }

        foreach (self::SYSTEM_REFERENCE_PREFIXES as $prefix) {
            if ($entry->reference !== null && str_starts_with($entry->reference, $prefix)) {
                return true;
            }
        }

        // Opening balances can be recorded under any reference; they are
        // recognisable by the opening balance account they post against.
        $openingAccountIds = Account::withoutGlobalScopes()
            ->where('organization_id', $entry->organization_id)
            ->where('code', AccountCode::OPENING_BALANCE)
            ->pluck('id');

        return $openingAccountIds->isNotEmpty()
            && $entry->lines()->whereIn('account_id', $openingAccountIds)->exists();
    }
}
