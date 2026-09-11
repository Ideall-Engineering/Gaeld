{{--
    Swiss salary certificate — layout of the official Form 11 (Lohnausweis).

    The numbered boxes come from the action as $certificate['chiffres']; the
    `chiffre` cell style is the same one the VAT form uses for its line codes.

    Boxes this payroll module cannot fill (fringe benefits, irregular payments,
    capital payments, employee shares, board fees) are absent rather than zero:
    a printed zero asserts nothing of the kind was paid, which the module has
    no way of knowing.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('exports.salary_certificate_form11.title') }} — {{ $certificate['employee']->fullName() }}</title>
    @include('exports._styles')
    <style>
        .parties { display: table; width: 100%; margin-bottom: 6mm; }
        .party { display: table-cell; width: 50%; vertical-align: top; padding-right: 8mm; }
        .party .party-label { font-size: 8pt; text-transform: uppercase; letter-spacing: 0.04em; color: #4f5b53; margin-bottom: 1.5mm; }
        .party .party-body { font-size: 9.5pt; line-height: 1.45; }
        .field-grid { width: 100%; margin-top: 0; }
        .field-grid td { border-bottom: none; padding: 1.5px 0; }
        .field-grid td.label { color: #4f5b53; width: 42%; }
        .signature { margin-top: 12mm; display: table; width: 100%; }
        .signature > div { display: table-cell; width: 50%; padding-right: 10mm; }
        .signature .rule { border-bottom: 1px solid #1f2a24; height: 10mm; }
        .signature .caption { font-size: 8pt; color: #666; padding-top: 1.5mm; }
    </style>
</head>
<body>
    @include('exports._header', [
        'docTitle' => __('exports.salary_certificate_form11.title'),
        'docPeriod' => __('exports.salary_certificate_form11.period', [
            'from' => $certificate['period_from']->format('d.m.Y'),
            'to' => $certificate['period_to']->format('d.m.Y'),
        ]),
        'docRef' => $certificate['employee']->fullName(),
    ])

    <div class="parties">
        <div class="party">
            <div class="party-label">{{ __('exports.salary_certificate_form11.employee') }}</div>
            <div class="party-body">
                <strong>{{ $certificate['employee']->fullName() }}</strong>
                @if($certificate['employee']->address)
                    <br>{{ $certificate['employee']->address }}
                @endif
                @if($certificate['employee']->postal_code || $certificate['employee']->city)
                    <br>{{ implode(' ', array_filter([
                        $certificate['employee']->postal_code,
                        $certificate['employee']->city,
                    ])) }}
                @endif
            </div>
        </div>
        <div class="party">
            <div class="party-label">{{ __('exports.salary_certificate_form11.details') }}</div>
            <table class="field-grid">
                <tr>
                    <td class="label">{{ __('exports.salary_certificate_form11.ahv_number') }}</td>
                    <td>{{ $certificate['employee']->ahv_number }}</td>
                </tr>
                <tr>
                    <td class="label">{{ __('exports.salary_certificate_form11.date_of_birth') }}</td>
                    <td>{{ $certificate['employee']->date_of_birth?->format('d.m.Y') }}</td>
                </tr>
                @if($certificate['employee']->place_of_origin)
                    <tr>
                        <td class="label">{{ __('exports.salary_certificate_form11.place_of_origin') }}</td>
                        <td>{{ $certificate['employee']->place_of_origin }}</td>
                    </tr>
                @endif
            </table>
        </div>
    </div>

    <div class="section">
        <table>
            <thead>
                <tr>
                    <th class="num">{{ __('exports.salary_certificate_form11.chiffre') }}</th>
                    <th>{{ __('exports.salary_certificate_form11.description') }}</th>
                    <th class="r">{{ __('exports.salary_certificate_form11.amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($certificate['chiffres'] as $row)
                    <tr @class([
                        'subtotal' => ($row['emphasis'] ?? null) === 'subtotal',
                        'total' => ($row['emphasis'] ?? null) === 'total',
                    ])>
                        <td class="chiffre">{{ $row['chiffre'] }}</td>
                        <td>{{ __('exports.salary_certificate_form11.chiffres.'.$row['key']) }}</td>
                        <td class="amount">{{ number_format((float) $row['amount'], 2, '.', "'") }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section">
        <div class="section-title">
            {{ __('exports.salary_certificate_form11.chiffres.remarks') }} ({{ __('exports.salary_certificate_form11.chiffre') }} 15)
        </div>
        <table class="field-grid" style="margin-top: 3mm;">
            @if($certificate['employee']->job_title)
                <tr>
                    <td class="label">{{ __('exports.salary_certificate_form11.job_title') }}</td>
                    <td>{{ $certificate['employee']->job_title }}</td>
                </tr>
            @endif
            @if($certificate['employee']->employment_rate)
                <tr>
                    <td class="label">{{ __('exports.salary_certificate_form11.employment_rate') }}</td>
                    <td>{{ number_format((float) $certificate['employee']->employment_rate, 0) }}%</td>
                </tr>
            @endif
            <tr>
                <td class="label">{{ __('exports.salary_certificate_form11.months_covered') }}</td>
                <td>{{ $certificate['months_covered'] }}</td>
            </tr>
        </table>
    </div>

    <div class="signature">
        <div>
            <div class="rule"></div>
            <div class="caption">{{ __('exports.salary_certificate_form11.place_and_date') }}</div>
        </div>
        <div>
            <div class="rule"></div>
            <div class="caption">{{ __('exports.salary_certificate_form11.signature') }}</div>
        </div>
    </div>

    @include('exports._footer')
</body>
</html>
