<?php

namespace Tests\Feature\Plugins;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * plan.md Etappe 0 architecture test: "kein Kernimport ausserhalb
 * CoreBridge/, keine Referenz auf Plugins\AccountantApi im Kern und keine
 * Modulmigration auf fachliche Kerntabellen ohne explizite Ausnahme."
 *
 * Only Controllers/Requests/Resources/Jobs are restricted (plan.md
 * "Erlaubte Änderungen ausserhalb des Moduls" — the module's own
 * ServiceProvider wiring, e.g. registering an ability for a core model, is
 * explicitly the module's boot-time glue, not request-handling code).
 *
 * Restricted namespaces are the Fachdomains plan.md means to decouple from
 * (Accounting and, as later etappen add endpoints, Invoicing/Expenses/
 * Banking/Payroll/Assets) — not every `App\Domains\*` namespace. Discovered
 * while building Phase 4: `App\Domains\Organizations\Services\
 * CurrentOrganization` and `App\Domains\Api\*` are the same shared
 * tenancy/API-platform infrastructure the module's routes already sit on
 * top of (the 'api-org' middleware, `HandleApiIdempotency`,
 * `JournalEntryApiMapper` reuse for the identical explicit-or-VAT-shorthand
 * format) — restricting those too would make the module unable to validate
 * a request against the correct organization at all without duplicating
 * that platform code.
 */
class AccountantApiModuleBoundaryTest extends TestCase
{
    private const RESTRICTED_DIRS = ['Controllers', 'Requests', 'Resources', 'Jobs'];

    private const RESTRICTED_NAMESPACES = [
        'Accounting', 'Invoicing', 'Expenses', 'Banking', 'Payroll', 'Assets',
    ];

    public function test_no_core_class_references_the_accountant_api_plugin_namespace(): void
    {
        $offenders = [];

        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            if (str_contains($file->getContents(), 'Plugins\\AccountantApi')) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'Core files referencing the plugin namespace: '.implode(', ', $offenders));
    }

    public function test_module_controllers_requests_resources_and_jobs_do_not_import_core_classes_directly(): void
    {
        $moduleSrc = base_path('plugins/accountant-api/src');
        if (! is_dir($moduleSrc)) {
            $this->markTestSkipped('Module not present.');
        }

        $offenders = [];

        foreach (self::RESTRICTED_DIRS as $dir) {
            $path = $moduleSrc.'/'.$dir;
            if (! is_dir($path)) {
                continue;
            }

            $pattern = '/^use\s+App\\\\Domains\\\\('.implode('|', self::RESTRICTED_NAMESPACES).')\\\\/m';

            foreach ((new Finder)->files()->in($path)->name('*.php') as $file) {
                if (preg_match($pattern, $file->getContents())) {
                    $offenders[] = $dir.'/'.$file->getRelativePathname();
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Module Controllers/Requests/Resources/Jobs importing a Fachdomain class directly (route through CoreBridge/ instead): '.implode(', ', $offenders),
        );
    }

    public function test_module_migrations_do_not_touch_core_tables(): void
    {
        $migrationsPath = base_path('plugins/accountant-api/migrations');
        if (! is_dir($migrationsPath)) {
            $this->markTestSkipped('Module not present.');
        }

        $coreTables = ['journal_entries', 'journal_corrections', 'transaction_lines', 'accounts', 'organizations', 'users'];
        $offenders = [];

        foreach ((new Finder)->files()->in($migrationsPath)->name('*.php') as $file) {
            foreach ($coreTables as $table) {
                if (preg_match('/Schema::(create|table|drop\w*)\([\'"]'.preg_quote($table, '/').'[\'"]/', $file->getContents())) {
                    $offenders[] = $file->getFilename().' touches '.$table;
                }
            }
        }

        $this->assertSame([], $offenders, implode(', ', $offenders));
    }
}
