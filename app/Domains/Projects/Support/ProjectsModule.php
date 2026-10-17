<?php

namespace App\Domains\Projects\Support;

use App\Domains\Organizations\Enums\OrganizationModule;
use App\Domains\Organizations\Models\Organization;
use App\Support\FeatureFlag;

/**
 * Whether projects and line allocations (spec 009) are switched on for an
 * organization.
 *
 * Unlike most modules this is enforced per organization, not only hidden:
 * an organization that never switched it on sees no project data, no
 * project routes and no allocations in API answers.
 */
final class ProjectsModule
{
    public static function enabledFor(?Organization $organization): bool
    {
        return $organization !== null
            && FeatureFlag::enabledForOrg(OrganizationModule::Projects->value, $organization);
    }
}
