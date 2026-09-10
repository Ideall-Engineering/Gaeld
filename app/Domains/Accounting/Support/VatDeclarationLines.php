<?php

namespace App\Domains\Accounting\Support;

/**
 * Labels for the lines (chiffres) of the Swiss VAT return.
 *
 * Every translation key is spelled out literally so `gaeld:check-translations`
 * can verify statically that all four languages define them. A key assembled at
 * runtime from the line number would be invisible to that check.
 *
 * The labels are resolved at render time rather than inside VatReportService,
 * because that service caches its result for 30 minutes and would otherwise
 * hand a later request the labels of whichever locale filled the cache.
 */
final class VatDeclarationLines
{
    /** The report keys whose rows carry a `line` and therefore a label. */
    private const ROW_GROUPS = [
        'turnover_rows',
        'output_vat_rows',
        'acquisition_rows',
        'input_vat_rows',
        'settlement_rows',
    ];

    public static function label(string $line): string
    {
        return match ($line) {
            '200' => (string) __('app.vat_line_200'),
            '220' => (string) __('app.vat_line_220'),
            '221' => (string) __('app.vat_line_221'),
            '225' => (string) __('app.vat_line_225'),
            '230' => (string) __('app.vat_line_230'),
            '235' => (string) __('app.vat_line_235'),
            '280' => (string) __('app.vat_line_280'),
            '289' => (string) __('app.vat_line_289'),
            '299' => (string) __('app.vat_line_299'),
            '302' => (string) __('app.vat_line_302'),
            '312' => (string) __('app.vat_line_312'),
            '342' => (string) __('app.vat_line_342'),
            '380' => (string) __('app.vat_line_380'),
            '381' => (string) __('app.vat_line_381'),
            '399' => (string) __('app.vat_line_399'),
            '400' => (string) __('app.vat_line_400'),
            '405' => (string) __('app.vat_line_405'),
            '479' => (string) __('app.vat_line_479'),
            '500' => (string) __('app.vat_line_500'),
            '510' => (string) __('app.vat_line_510'),
            default => $line,
        };
    }

    /**
     * Attach a `label` to every reported line, so CSV and PDF renderers do not
     * have to resolve translation keys themselves.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public static function attach(array $report): array
    {
        foreach (self::ROW_GROUPS as $group) {
            if (! isset($report[$group]) || ! is_array($report[$group])) {
                continue;
            }

            /** @var array<int, array<string, mixed>> $rows */
            $rows = $report[$group];

            $report[$group] = array_map(
                static fn (array $row): array => [...$row, 'label' => self::label((string) $row['line'])],
                $rows,
            );
        }

        return $report;
    }
}
