<?php

namespace App\Domains\Accounting\Actions;

use App\Domains\Accounting\DTOs\JournalEntryChangeContext;
use App\Domains\Accounting\Enums\JournalEntryRevisionKind;
use App\Domains\Accounting\Events\JournalEntryAmended;
use App\Domains\Accounting\Exceptions\JournalEntryNotEditableException;
use App\Domains\Accounting\Exceptions\StaleJournalEntryRevisionException;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Services\JournalEntryEditStateService;
use App\Domains\Accounting\Services\JournalEntryRevisionRecorder;
use App\Domains\Accounting\Services\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sharpens the texts of a posted journal entry: its description and the
 * descriptions of its lines, nothing else. Allowed in more states than a
 * full amendment ({@see JournalEntryEditStateService::textStateFor()}),
 * because no amount, account or date moves. The previous state still goes
 * to `journal_entry_revisions` and the revision number goes up by one.
 */
class AmendJournalEntryTextAction
{
    public function __construct(
        private LedgerService $ledger,
        private JournalEntryEditStateService $editState,
        private JournalEntryRevisionRecorder $revisions,
    ) {}

    /**
     * @param  array<int|string, string|null>|null  $lineDescriptions  by line id; lines not named keep their text
     *
     * @throws StaleJournalEntryRevisionException When the entry changed since the caller loaded it
     * @throws JournalEntryNotEditableException When the texts are locked
     */
    public function execute(
        JournalEntry $entry,
        bool $setDescription,
        ?string $description,
        ?array $lineDescriptions,
        JournalEntryChangeContext $context,
    ): JournalEntry {
        $result = DB::transaction(function () use ($entry, $setDescription, $description, $lineDescriptions, $context): ?array {
            $locked = JournalEntry::withoutGlobalScopes()
                ->whereKey($entry->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->revision !== $context->expectedRevision) {
                throw new StaleJournalEntryRevisionException($context->expectedRevision, $locked->revision);
            }

            $this->editState->assertTextAmendable($locked);

            $lines = TransactionLine::query()->where('journal_entry_id', $locked->id)->get()->keyBy('id');
            $lineChanges = [];
            foreach ($lineDescriptions ?? [] as $id => $text) {
                $line = $lines->get((int) $id);
                $text = self::clean($text);
                if ($line !== null && $line->description !== $text) {
                    $lineChanges[] = [$line, $text];
                }
            }
            $description = self::clean($description);
            $headerChanged = $setDescription && $locked->description !== $description;

            if (! $headerChanged && $lineChanges === []) {
                return null;
            }

            $revision = $this->revisions->record($locked, JournalEntryRevisionKind::Updated, $context);
            $previousRevision = $locked->revision;

            $locked->update([
                'description' => $headerChanged ? $description : $locked->description,
                'revision' => $previousRevision + 1,
            ]);
            foreach ($lineChanges as [$line, $text]) {
                $line->update(['description' => $text]);
            }

            return [$locked, $previousRevision, $revision->id];
        });

        if ($result === null) {
            return $entry->fresh(['lines.account']) ?? $entry;
        }

        [$amended, $previousRevision, $revisionId] = $result;

        $this->ledger->flushCache($amended->organization_id);

        JournalEntryAmended::dispatch(
            Str::uuid()->toString(),
            $amended->organization_id,
            $amended->id,
            $previousRevision,
            $amended->revision,
            $revisionId,
        );

        return $amended->fresh(['lines.account']);
    }

    /**
     * Validated `lines` input ([{id, description}]) keyed by line id; null
     * when no lines were sent.
     *
     * @return array<int, string|null>|null
     */
    public static function linesById(mixed $lines): ?array
    {
        if (! is_array($lines)) {
            return null;
        }

        $byId = [];
        foreach ($lines as $line) {
            if (is_array($line) && isset($line['id'])) {
                $byId[(int) $line['id']] = isset($line['description']) ? (string) $line['description'] : null;
            }
        }

        return $byId;
    }

    private static function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }
}
