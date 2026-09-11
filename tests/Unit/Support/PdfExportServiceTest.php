<?php

namespace Tests\Unit\Support;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Models\Employee;
use App\Support\PdfExportService;
use Tests\TestCase;

/**
 * The service raises PHP's limits so a large report can finish rendering.
 *
 * The time limit must stay a web-only concern. set_time_limit() restarts the
 * execution clock for the entire process, so calling it on the CLI gives the
 * rest of a test run — or a queue worker's remaining jobs — 120 seconds in
 * total, ending in a fatal error that reads like a test failure rather than
 * what it is.
 */
class PdfExportServiceTest extends TestCase
{
    public function test_it_does_not_cap_execution_time_on_the_cli(): void
    {
        $before = ini_get('max_execution_time');

        $this->renderCertificate();

        $this->assertSame(
            $before,
            ini_get('max_execution_time'),
            'Rendering a PDF changed max_execution_time in a console process.',
        );
    }

    public function test_it_still_raises_the_memory_limit(): void
    {
        ini_set('memory_limit', '128M');

        $this->renderCertificate();

        $this->assertSame('512M', ini_get('memory_limit'));
    }

    /**
     * Render the smallest shape exports.salary-certificate will accept.
     */
    private function renderCertificate(): void
    {
        $organization = new Organization(['name' => 'Test AG']);
        $employee = new Employee(['first_name' => 'Test', 'last_name' => 'Person']);

        (new PdfExportService)->stream(
            'exports.salary-certificate',
            [
                'organization' => $organization,
                'certificate' => [
                    'employee' => $employee,
                    'organization' => $organization,
                    'year' => 2026,
                    'months_covered' => 12,
                    'gross_salary' => '0.00',
                    'avs_employee' => '0.00',
                    'ac_employee' => '0.00',
                    'aanp_employee' => '0.00',
                    'lpp_employee' => '0.00',
                    'source_tax' => '0.00',
                    'reimbursements' => '0.00',
                    'net_salary' => '0.00',
                    'total_paid' => '0.00',
                    'employer_charges' => '0.00',
                ],
            ],
            'test.pdf',
        );
    }
}
