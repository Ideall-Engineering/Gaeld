@php($report = \App\Domains\Accounting\Support\VatDeclarationLines::attach($report))

<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('exports.vat.title') }}</title>
    @include('exports._styles')
</head>
<body>
    @include('exports._header', [
        'docTitle' => __('exports.vat.subtitle'),
        'docPeriod' => __('exports.vat.period', ['from' => $report['period']['from'], 'to' => $report['period']['to']]),
    ])

    <div class="section">
        <div class="section-title">{{ __('exports.vat.section_1') }}</div>
        <table>
            <thead>
                <tr>
                    <th class="num">{{ __('exports.vat.code') }}</th>
                    <th>{{ __('exports.vat.description') }}</th>
                    <th class="num">{{ __('exports.vat.base_amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['turnover_rows'] as $row)
                    <tr @class(['total' => in_array($row['line'], ['200', '289', '299'], true)])>
                        <td class="chiffre">{{ $row['line'] }}</td>
                        <td>{{ $row['label'] }}</td>
                        <td class="amount">{{ number_format((float) $row['amount'], 2, '.', "'") }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section">
        <div class="section-title">{{ __('exports.vat.section_2') }}</div>
        <table>
            <thead>
                <tr>
                    <th class="num">{{ __('exports.vat.code') }}</th>
                    <th>{{ __('exports.vat.description') }}</th>
                    <th class="num">{{ __('exports.vat.base_amount') }}</th>
                    <th class="num">{{ __('exports.vat.rate') }}</th>
                    <th class="num">{{ __('exports.vat.vat_amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['output_vat_rows'] as $row)
                    <tr>
                        <td class="chiffre">{{ $row['line'] }}</td>
                        <td>{{ $row['label'] }}</td>
                        <td class="amount">{{ number_format((float) $row['taxable'], 2, '.', "'") }}</td>
                        <td class="amount">{{ $row['rate'] }}%</td>
                        <td class="amount">{{ number_format((float) $row['vat'], 2, '.', "'") }}</td>
                    </tr>
                @endforeach
                @foreach ($report['acquisition_rows'] as $row)
                    <tr>
                        <td class="chiffre">{{ $row['line'] }}</td>
                        <td>{{ $row['label'] }}</td>
                        <td class="amount">{{ number_format((float) $row['taxable'], 2, '.', "'") }}</td>
                        <td class="amount"></td>
                        <td class="amount">{{ number_format((float) $row['vat'], 2, '.', "'") }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td class="chiffre">399</td>
                    <td>{{ __('app.vat_line_399') }}</td>
                    <td class="amount"></td>
                    <td class="amount"></td>
                    <td class="amount">{{ number_format((float) $report['total_tax_owed'], 2, '.', "'") }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="section">
        <div class="section-title">{{ __('exports.vat.section_3') }}</div>
        <table>
            <tbody>
                @foreach ($report['input_vat_rows'] as $row)
                    <tr @class(['total' => $row['line'] === '479'])>
                        <td class="chiffre">{{ $row['line'] }}</td>
                        <td>{{ $row['label'] }}</td>
                        <td class="amount">{{ number_format((float) $row['amount'], 2, '.', "'") }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section">
        <div class="section-title">{{ __('exports.vat.section_4') }}</div>
        <table>
            <tbody>
                @foreach ($report['settlement_rows'] as $row)
                    <tr @class(['payable' => $row['line'] === '500', 'total' => $row['line'] === '510'])>
                        <td class="chiffre">{{ $row['line'] }}</td>
                        <td>{{ $row['label'] }}</td>
                        <td class="amount">{{ number_format((float) $row['amount'], 2, '.', "'") }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @include('exports._footer')
</body>
</html>
