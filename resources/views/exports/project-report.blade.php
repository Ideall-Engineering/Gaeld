<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('exports.project_report.title') }}</title>
    @include('exports._styles')
</head>
<body>
    @include('exports._header', [
        'docTitle' => __('exports.project_report.title').' '.$project->code.' '.$project->name,
        'docPeriod' => __('exports.project_report.period', ['from' => $period['from'], 'to' => $period['to']]),
    ])

    <table>
        <thead>
            <tr>
                <th>{{ __('exports.common.account') }}</th>
                <th></th>
                <th class="amount">{{ __('exports.common.amount') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr class="section-header">
                <td colspan="3">{{ __('exports.project_report.revenue') }}</td>
            </tr>
            @foreach ($report['revenue'] as $account)
                <tr>
                    <td>{{ $account['code'] }}</td>
                    <td>{{ $account['name'] }}</td>
                    <td class="amount">{{ number_format((float) $account['amount'], 2, '.', "'") }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="2">{{ __('exports.project_report.total_revenue') }}</td>
                <td class="amount">{{ number_format((float) $report['total_revenue'], 2, '.', "'") }}</td>
            </tr>

            <tr class="section-header">
                <td colspan="3">{{ __('exports.project_report.expenses') }}</td>
            </tr>
            @foreach ($report['expenses'] as $account)
                <tr>
                    <td>{{ $account['code'] }}</td>
                    <td>{{ $account['name'] }}</td>
                    <td class="amount">{{ number_format((float) $account['amount'], 2, '.', "'") }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="2">{{ __('exports.project_report.total_expenses') }}</td>
                <td class="amount">{{ number_format((float) $report['total_expenses'], 2, '.', "'") }}</td>
            </tr>

            <tr class="net-profit">
                <td colspan="2">{{ __('exports.project_report.contribution') }}</td>
                <td class="amount">{{ number_format((float) $report['contribution'], 2, '.', "'") }}</td>
            </tr>
        </tbody>
    </table>

    @if (count($report['projects']) > 1)
        <table>
            <thead>
                <tr>
                    <th>{{ __('exports.project_report.project') }}</th>
                    <th class="amount">{{ __('exports.project_report.revenue') }}</th>
                    <th class="amount">{{ __('exports.project_report.expenses') }}</th>
                    <th class="amount">{{ __('exports.project_report.contribution') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['projects'] as $row)
                    <tr>
                        <td>{{ $row['code'] }} {{ $row['name'] }}</td>
                        <td class="amount">{{ number_format((float) $row['revenue'], 2, '.', "'") }}</td>
                        <td class="amount">{{ number_format((float) $row['expenses'], 2, '.', "'") }}</td>
                        <td class="amount">{{ number_format((float) $row['contribution'], 2, '.', "'") }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @include('exports._footer')
</body>
</html>
