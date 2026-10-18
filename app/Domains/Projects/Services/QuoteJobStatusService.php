<?php

namespace App\Domains\Projects\Services;

use App\Domains\Invoicing\Enums\QuoteStatus;
use App\Domains\Invoicing\Models\Quote;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Support\ProjectsModule;

/**
 * A job follows its quotes: a sent quote makes an open job "quote sent", an accepted
 * one "in progress", a declined one "lost". Done jobs are left alone, and a
 * job already in progress does not fall back because a further quote goes
 * out or is declined. A job without an expected amount takes the quote's net
 * total.
 */
class QuoteJobStatusService
{
    public function follow(Quote $quote): void
    {
        if ($quote->cost_center_id === null || ! ProjectsModule::enabledFor($quote->organization)) {
            return;
        }

        $job = Project::withoutGlobalScopes()
            ->where('organization_id', $quote->organization_id)
            ->whereKey($quote->cost_center_id)
            ->first();

        if ($job === null || $job->kind !== ProjectKind::Project || $job->status === ProjectStatus::Done) {
            return;
        }

        $status = match ($quote->status) {
            QuoteStatus::Sent => in_array($job->status, [ProjectStatus::Pipeline, ProjectStatus::Possible, ProjectStatus::Lost], true) ? ProjectStatus::Possible : null,
            QuoteStatus::Accepted => ProjectStatus::InProgress,
            QuoteStatus::Declined => in_array($job->status, [ProjectStatus::Pipeline, ProjectStatus::Possible], true) && ! $this->hasOpenQuote($quote) ? ProjectStatus::Lost : null,
            QuoteStatus::Draft => null,
        };

        if ($status !== null) {
            $job->status = $status;
        }
        if ($job->expected_amount === null && in_array($quote->status, [QuoteStatus::Sent, QuoteStatus::Accepted], true)) {
            $job->expected_amount = $quote->subtotal;
        }
        if ($job->isDirty()) {
            $job->save();
        }
    }

    /** Another quote for the same job still out or accepted. */
    private function hasOpenQuote(Quote $quote): bool
    {
        return Quote::withoutGlobalScopes()
            ->where('organization_id', $quote->organization_id)
            ->where('cost_center_id', $quote->cost_center_id)
            ->whereKeyNot($quote->getKey())
            ->whereIn('status', [QuoteStatus::Sent->value, QuoteStatus::Accepted->value])
            ->exists();
    }
}
