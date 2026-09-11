<?php

namespace App\Domains\Payroll\Controllers;

use App\Domains\Payroll\Actions\GenerateSalaryCertificateAction;
use App\Domains\Payroll\Models\Employee;
use App\Http\Controllers\Controller;
use App\Support\PdfExportService;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class SalaryCertificateController extends Controller
{
    public function download(
        Employee $employee,
        int $year,
        GenerateSalaryCertificateAction $action,
        PdfExportService $pdf,
    ): HttpResponse {
        $this->authorize('view', $employee);

        // An incomplete Form 11 is worse than none: it looks official while
        // omitting details the tax authority expects. Say what is missing
        // instead of rendering it.
        $missing = $employee->missingCertificateFieldLabels();

        if ($missing !== []) {
            return back()->withErrors([
                'certificate' => __('app.salary_certificate_incomplete', [
                    'fields' => implode(', ', $missing),
                ]),
            ]);
        }

        $certificate = $action->execute($employee, $year);

        return $pdf->download(
            'exports.salary-certificate-form11',
            ['certificate' => $certificate, 'organization' => $employee->organization],
            "salary-certificate-{$employee->last_name}-{$year}.pdf",
        );
    }
}
