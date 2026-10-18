<?php

namespace App\Domains\Projects\Controllers;

use App\Domains\Accounting\Services\JournalEntryEditStateService;
use App\Domains\Organizations\Enums\Permission;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Queries\ProjectQuery;
use App\Domains\Projects\Services\AllocationCoverageService;
use App\Domains\Projects\Services\ProjectJournalService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The revenue and expense lines of a year as a journal, with project, job
 * and people on every line; the job is changed right in the line
 * (spec 010).
 */
class ProjectJournalController extends Controller
{
    public function index(Request $request, ProjectJournalService $journal, CurrentOrganization $currentOrg): Response
    {
        $this->authorize('viewAny', Project::class);

        $user = $request->user();
        $year = $request->integer('year', (int) now()->year);
        $year = $year >= 2000 && $year <= 2100 ? $year : (int) now()->year;
        $steer = $user?->can('steer', Project::class) ?? false;

        return Inertia::render('Projects/Journal', [
            'year' => $year,
            'rows' => $journal->forYear($currentOrg->id(), $year),
            'projects' => ProjectQuery::tree(withShares: $steer),
            'employees' => ProjectQuery::employeeOptions(),
            'generalCode' => AllocationCoverageService::GENERAL_CODE,
            'can' => [
                'allocate' => $user?->can('allocate', Project::class) ?? false,
                'steer' => $steer,
                // Texts of posted entries can be sharpened (spec 008); the
                // server still refuses a closed year or the archive.
                'amend_text' => ($user?->hasPermissionTo(Permission::AccountingAmendPosted) ?? false)
                    && app(JournalEntryEditStateService::class)->isFlexible($currentOrg->id()),
            ],
        ]);
    }
}
