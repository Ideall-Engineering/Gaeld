@php
    $employeeData = $employeeData ?? $slip->employeeDocumentData();

    $deductions = $slip->deductions;

    // The named breakdown the calculation froze into the slip. Listing it is
    // what keeps the rows and the total underneath them talking about the same
    // set of contributions — a chart with KTG, FAK or BU used to show a total
    // larger than the lines above it.
    $deductionLines = is_array($deductions['lines'] ?? null) ? $deductions['lines'] : [];

    // Slips written before the breakdown existed still print what they printed.
    if ($deductionLines === []) {
        foreach (['avs_employee', 'ac_employee', 'aanp_employee', 'lpp_employee'] as $code) {
            if (isset($deductions[$code])) {
                $deductionLines[] = ['code' => $code, 'name' => '', 'type' => 'employee', 'amount' => (string) $deductions[$code]];
            }
        }
        foreach (['avs_employer', 'ac_employer', 'lpp_employer'] as $code) {
            if (isset($deductions[$code])) {
                $deductionLines[] = ['code' => $code, 'name' => '', 'type' => 'employer', 'amount' => (string) $deductions[$code]];
            }
        }
    }

    $standardLabels = [
        'avs_employee' => 'exports.salary_slip.avs_ai_apg',
        'ac_employee' => 'exports.salary_slip.unemployment_insurance',
        'aanp_employee' => 'exports.salary_slip.aanp',
        'lpp_employee' => 'exports.salary_slip.pension_lpp',
        'avs_employer' => 'exports.salary_slip.avs_ai_apg_employer',
        'ac_employer' => 'exports.salary_slip.unemployment_insurance_employer',
        'lpp_employer' => 'exports.salary_slip.pension_lpp_employer',
    ];

    $deductionLabel = static function (array $line) use ($standardLabels): string {
        $code = (string) ($line['code'] ?? '');

        return isset($standardLabels[$code])
            ? __($standardLabels[$code])
            : (string) ($line['name'] !== '' ? $line['name'] : $code);
    };

    $linesOfType = static fn (string $type): array => array_values(array_filter(
        $deductionLines,
        static fn (array $line): bool => ($line['type'] ?? '') === $type
            && bccomp((string) ($line['amount'] ?? '0'), '0', 2) > 0,
    ));
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('exports.salary_slip.title') }} — {{ $employeeData['first_name'] }} {{ $employeeData['last_name'] }}</title>
    @include('exports._styles')
</head>
<body>
    @include('exports._header', [
        'docTitle' => __('exports.salary_slip.title'),
        'docPeriod' => str_pad($slip->period_month, 2, '0', STR_PAD_LEFT).'/'.$slip->period_year,
        'docRef' => $employeeData['first_name'].' '.$employeeData['last_name'],
    ])

    <div class="section">
        <div class="section-title">{{ __('exports.salary_slip.employee') }}</div>
        <table class="employee-info">
            <tr><td style="width:30%;">{{ __('exports.salary_slip.name') }}</td><td>{{ $employeeData['first_name'] }} {{ $employeeData['last_name'] }}</td></tr>
                @if($employeeData['ahv_number'])
                    <tr><td>{{ __('exports.salary_slip.ahv_number') }}</td><td>{{ $employeeData['ahv_number'] }}</td></tr>
            @endif
        </table>
    </div>

    <div class="section">
        <div class="section-title">{{ __('exports.salary_slip.salary') }}</div>
        <table>
            @if(isset($slip->adjustments['base_salary']) && bccomp($slip->adjustments['base_salary'], $slip->gross_salary, 2) !== 0)
                <tr><td>{{ __('exports.salary_slip.base_salary') }}</td><td class="right">{{ number_format((float) $slip->adjustments['base_salary'], 2, '.', "'") }}</td></tr>
            @endif
            @if(isset($slip->adjustments['thirteenth_salary']) && bccomp($slip->adjustments['thirteenth_salary'], '0', 2) > 0)
                <tr><td>{{ __('exports.salary_slip.thirteenth_salary') }}</td><td class="right">{{ number_format((float) $slip->adjustments['thirteenth_salary'], 2, '.', "'") }}</td></tr>
            @endif
            @if(isset($slip->adjustments['unpaid_leave_amount']) && bccomp($slip->adjustments['unpaid_leave_amount'], '0', 2) > 0)
                <tr><td>{{ __('exports.salary_slip.unpaid_leave') }}</td><td class="right">-{{ number_format((float) $slip->adjustments['unpaid_leave_amount'], 2, '.', "'") }}</td></tr>
            @endif
            <tr>
                <td>{{ __('exports.salary_slip.gross_salary') }}</td>
                <td class="right">{{ number_format((float) $slip->gross_salary, 2, '.', "'") }}</td>
            </tr>
        </table>
    </div>

    @if(isset($slip->adjustments['reimbursement_amount']) && bccomp($slip->adjustments['reimbursement_amount'], '0', 2) > 0)
        <div class="section">
            <table>
                <tr>
                    <td>{{ __('exports.salary_slip.expense_reimbursement') }}</td>
                    <td class="right">{{ number_format((float) $slip->adjustments['reimbursement_amount'], 2, '.', "'") }}</td>
                </tr>
            </table>
        </div>
    @endif

    <div class="section">
        <div class="section-title">{{ __('exports.salary_slip.employee_deductions') }}</div>
        <table>
            @foreach($linesOfType('employee') as $line)
                <tr><td>{{ $deductionLabel($line) }}</td><td class="right">-{{ number_format((float) $line['amount'], 2, '.', "'") }}</td></tr>
            @endforeach
            @php $sourceTax = $deductions['source_tax'] ?? $slip->source_tax_amount ?? '0.00'; @endphp
            @if(bccomp((string) $sourceTax, '0', 2) > 0)
                <tr><td>{{ __('exports.salary_slip.source_tax') }}</td><td class="right">-{{ number_format((float) $sourceTax, 2, '.', "'") }}</td></tr>
            @endif
            <tr class="total-row">
                <td>{{ __('exports.salary_slip.total_deductions') }}</td>
                <td class="right">-{{ number_format((float) ($deductions['total_employee'] ?? '0'), 2, '.', "'") }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <table>
            <tr class="net-row">
                <td>{{ __('exports.salary_slip.net_salary') }}</td>
                <td class="right">{{ number_format((float) $slip->net_salary, 2, '.', "'") }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">{{ __('exports.salary_slip.employer_charges') }}</div>
        <table>
            @foreach($linesOfType('employer') as $line)
                <tr><td>{{ $deductionLabel($line) }}</td><td class="right">{{ number_format((float) $line['amount'], 2, '.', "'") }}</td></tr>
            @endforeach
            <tr class="total-row">
                <td>{{ __('exports.salary_slip.total_employer_charges') }}</td>
                <td class="right">{{ number_format((float) ($deductions['total_employer'] ?? '0'), 2, '.', "'") }}</td>
            </tr>
        </table>
    </div>

    @include('exports._footer')
</body>
</html>
