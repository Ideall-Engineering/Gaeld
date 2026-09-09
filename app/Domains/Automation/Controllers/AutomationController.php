<?php

namespace App\Domains\Automation\Controllers;

use App\Domains\Automation\Models\AutomationRun;
use App\Domains\Automation\Models\AutomationSetting;
use App\Domains\Automation\Requests\TriggerAutomationRequest;
use App\Domains\Automation\Requests\UpdateAutomationSettingRequest;
use App\Domains\Automation\Services\AutomationRegistry;
use App\Domains\Automation\Services\AutomationRunner;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The automation screen: what may run, what ran, and what it found.
 */
class AutomationController extends Controller
{
    public function __construct(
        private readonly AutomationRegistry $registry,
        private readonly AutomationRunner $runner,
    ) {}

    public function index(CurrentOrganization $currentOrg): Response
    {
        $this->authorize('viewAny', AutomationRun::class);

        $runs = AutomationRun::query()
            ->with('triggeredBy:id,name')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return Inertia::render('Automation/Index', [
            'automations' => $this->registry->describeFor($currentOrg->get()),
            'runs' => $runs,
            'canConfigure' => request()->user()->can('create', AutomationSetting::class),
        ]);
    }

    public function update(
        UpdateAutomationSettingRequest $request,
        CurrentOrganization $currentOrg,
    ): RedirectResponse {
        $this->authorize('create', AutomationSetting::class);

        $validated = $request->validated();
        $handler = $this->registry->get($validated['automation']);

        abort_if($handler === null, 404);

        // Turning on something that writes takes the permission that its work
        // would take — organization.edit alone must not unlock the ledger.
        if ($validated['is_enabled'] && $handler->writes()) {
            abort_unless(
                $request->user()->hasPermissionTo($handler->permission()),
                403,
                __('app.automation_requires_permission', ['permission' => $handler->permission()->value]),
            );
        }

        AutomationSetting::updateOrCreate(
            [
                'organization_id' => $currentOrg->id(),
                'automation' => $validated['automation'],
            ],
            [
                'is_enabled' => $validated['is_enabled'],
                'updated_by' => $request->user()->id,
            ],
        );

        return back()->with('success', __('app.saved'));
    }

    /**
     * Run one automation now, on this operator's authority.
     *
     * The event key marks it as a manual occasion so it never collides with the
     * scheduled run for the same day — an operator asking for a check should get
     * one, not be told today is already done.
     */
    public function trigger(
        TriggerAutomationRequest $request,
        CurrentOrganization $currentOrg,
    ): RedirectResponse {
        $this->authorize('viewAny', AutomationRun::class);

        $key = (string) $request->input('automation');
        $handler = $this->registry->get($key);

        abort_if($handler === null, 404);

        abort_unless(
            $request->user()->hasPermissionTo($handler->permission()),
            403,
            __('app.automation_requires_permission', ['permission' => $handler->permission()->value]),
        );

        try {
            $run = $this->runner->run(
                $key,
                $currentOrg->get(),
                'manual:'.now()->format('Y-m-d\TH:i:s.u'),
                [],
                $request->user(),
            );
        } catch (\Throwable $e) {
            // The runner rethrows so the queue can retry. A person pressing a
            // button wants the failure shown, not a stack trace — it is already
            // recorded on the run and the owners have been notified.
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $run->message ?? __('app.saved'));
    }
}
