<?php

namespace App\Domains\Projects\Controllers;

use App\Domains\Accounting\Models\Account;
use App\Domains\Projects\Models\AllocationReview;
use App\Domains\Projects\Models\Project;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Entries whose allocations could not be carried over unambiguously after
 * their lines were rebuilt (spec 009, User Story 4).
 */
class AllocationReviewController extends Controller
{
    public function index(): Response
    {
        $this->authorize('allocate', Project::class);

        $reviews = AllocationReview::query()
            ->whereNull('resolved_at')
            ->with('journalEntry:id,date,reference,description')
            ->orderBy('created_at')
            ->get();

        $accountIds = $reviews->flatMap(fn (AllocationReview $r) => array_column($r->previous, 'account_id'))->unique()->all();
        $accounts = Account::query()->whereIn('id', $accountIds)->get(['id', 'code', 'name'])->keyBy('id');
        $projects = Project::query()->get(['id', 'code', 'name'])->keyBy('id');

        return Inertia::render('Projects/AllocationReviews', [
            'reviews' => $reviews->map(fn (AllocationReview $review) => [
                'id' => $review->id,
                'created_at' => $review->created_at->toIso8601String(),
                'entry' => $review->journalEntry === null ? null : [
                    'id' => $review->journalEntry->id,
                    'date' => $review->journalEntry->date->toDateString(),
                    'reference' => $review->journalEntry->reference,
                    'description' => $review->journalEntry->description,
                ],
                'previous' => array_map(fn (array $line) => [
                    'account' => isset($accounts[(int) $line['account_id']])
                        ? $accounts[(int) $line['account_id']]->code.' '.$accounts[(int) $line['account_id']]->display_name
                        : $line['account_id'],
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'allocations' => array_map(fn (array $a) => [
                        'project' => $a['project_id'] !== null && isset($projects[$a['project_id']])
                            ? $projects[$a['project_id']]->code.' '.$projects[$a['project_id']]->name
                            : null,
                        'employee_id' => $a['employee_id'],
                        'share' => $a['share'],
                    ], $line['allocations']),
                ], $review->previous),
            ]),
        ]);
    }

    /** The person decided the old allocations no longer apply. */
    public function dismiss(Request $request, AllocationReview $allocationReview): RedirectResponse
    {
        $this->authorize('allocate', Project::class);

        if ($allocationReview->resolved_at === null) {
            $allocationReview->update(['resolved_at' => now(), 'resolved_by' => $request->user()?->id]);
        }

        return back()->with('success', __('app.allocation_review_dismissed'));
    }
}
