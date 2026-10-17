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

    @include('exports._footer')
</body>
</html>
