<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('app.steering') }} {{ $data['year'] }}</title>
    @include('exports._styles')
</head>
<body>
    @include('exports._header', [
        'docTitle' => __('app.steering').' '.$data['year'],
        'docPeriod' => __('exports.project_report.period', ['from' => $data['year'].'-01-01', 'to' => $data['year'].'-12-31']),
    ])

    <table>
        <thead>
            <tr>
                <th></th>
                @foreach ($data['people'] as $person)
                    <th class="amount">{{ $person['name'] }}</th>
                @endforeach
                <th class="amount">{{ __('app.total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($fields as $field)
                <tr class="{{ in_array($field, ['value', 'play'], true) ? 'total' : '' }}">
                    <td>{{ __('app.steering_'.$field) }}</td>
                    @foreach ($data['people'] as $person)
                        <td class="amount">{{ number_format((float) $person[$field], 2, '.', "'") }}</td>
                    @endforeach
                    <td class="amount">{{ number_format((float) $data['totals'][$field], 2, '.', "'") }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Spec 012: the expenses still expected and what is left all in all. --}}
    @php($expected = $data['expected_expenses'] ?? null)
    @if ($expected)
        <div class="section"><div class="section-title">{{ __('app.steering_expected_expenses') }} {{ $data['year'] }}</div></div>
        <table>
            <thead>
                <tr>
                    <th>{{ __('app.date') }}</th>
                    <th>{{ __('app.description') }}</th>
                    <th>{{ __('app.source') }}</th>
                    <th>{{ __('app.account') }}</th>
                    <th class="amount">{{ __('app.amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($expected['rows'] as $row)
                    <tr>
                        <td>{{ $row['date'] ?? __('app.steering_expected_undated') }}</td>
                        <td>{{ \App\Domains\Projects\Controllers\SteeringController::expectedLabel($row) }}</td>
                        <td>{{ __('app.steering_expected_source_'.$row['source']) }}</td>
                        <td>{{ $row['account'] ?? '' }}</td>
                        <td class="amount">{{ number_format((float) $row['amount'], 2, '.', "'") }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td colspan="4">{{ __('app.steering_expected_total_net') }}</td>
                    <td class="amount">{{ number_format((float) $expected['total'], 2, '.', "'") }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    @if (! empty($data['outlook']))
        <div class="section"><div class="section-title">{{ __('app.steering_outlook_months') }}</div></div>
        <table>
            <thead>
                <tr>
                    <th>{{ __('app.month') }}</th>
                    <th class="amount">{{ __('app.steering_outlook_income') }}</th>
                    <th class="amount">{{ __('app.steering_outlook_expenses') }}</th>
                    <th class="amount">{{ __('app.steering_outlook_result_cumulative') }}</th>
                    <th class="amount">{{ __('app.steering_outlook_bank') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($data['outlook']['months'] as $month)
                    <tr>
                        <td>{{ $month['key'] }}</td>
                        <td class="amount">{{ number_format((float) $month['income'], 2, '.', "'") }}</td>
                        <td class="amount">{{ number_format(-(float) $month['expenses'], 2, '.', "'") }}</td>
                        <td class="amount">{{ number_format((float) $month['result'], 2, '.', "'") }}</td>
                        <td class="amount">{{ number_format((float) $month['bank'], 2, '.', "'") }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @include('exports._footer')
</body>
</html>
