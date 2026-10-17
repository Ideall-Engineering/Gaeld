<?php

namespace App\Http\Middleware;

use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Support\ProjectsModule;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks project routes for organizations that have not switched the
 * projects module on (spec 009).
 */
class EnsureProjectsModule
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $organization = $this->currentOrganization->isBound() ? $this->currentOrganization->get() : null;

        if (! ProjectsModule::enabledFor($organization)) {
            abort(403, "Feature 'projects' is not enabled.");
        }

        return $next($request);
    }
}
