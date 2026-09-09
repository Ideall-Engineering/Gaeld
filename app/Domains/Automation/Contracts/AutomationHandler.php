<?php

namespace App\Domains\Automation\Contracts;

use App\Domains\Automation\DTOs\AutomationContext;
use App\Domains\Automation\DTOs\AutomationResult;
use App\Domains\Organizations\Enums\Permission;

/**
 * One automated workflow.
 *
 * The interface deliberately forces every implementation to declare whether it
 * changes business data. That single answer decides its default: anything that
 * writes starts switched off and has to be turned on by a person, anything that
 * only reports may run from the start.
 */
interface AutomationHandler
{
    /** Stable key — used in the run log, the settings table and the schedule. */
    public function key(): string;

    /** Translation key suffix for the human-readable name. */
    public function label(): string;

    public function description(): string;

    /**
     * Does this automation change business data — ledger, invoices, e-mails?
     *
     * True means it needs explicit approval before it may ever run.
     */
    public function writes(): bool;

    /** Permission a user needs to trigger this by hand. */
    public function permission(): Permission;

    public function run(AutomationContext $context): AutomationResult;
}
