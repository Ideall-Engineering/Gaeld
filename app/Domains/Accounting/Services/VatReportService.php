<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\Enums\VatEntryType;
use App\Domains\Accounting\Models\VatEntry;
use App\Domains\Accounting\Models\VatRate;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Generates Swiss VAT declaration data (AFC/ESTV form chiffres 200–510)
 * from posted journal entries for a given reporting period.
 */
class VatReportService
{
    /** @var array<string, string> */
    private const OUTPUT_LINES_BY_RATE_CODE = [
        'NORMAL' => '303',
        'REDUCED' => '313',
        'ACCOMMODATION' => '343',
    ];

    /** @var string[] */
    private const DEDUCTION_LINES = ['220', '221', '225', '230', '235', '280'];

    /**
     * Generate a VAT report for the given period, matching Swiss AFC/ESTV form structure.
     *
     * @return array<string, mixed>
     */
    public function generate(string $orgId, string $fromDate, string $toDate): array
    {
        $cacheKey = "vat_report:{$orgId}:{$fromDate}:{$toDate}";
        $orgTag = "org:{$orgId}:reports";

        return Cache::tags([$orgTag])->remember($cacheKey, now()->addMinutes(30), function () use ($orgId, $fromDate, $toDate): array {
            $entries = VatEntry::query()
                ->with('vatRate')
                ->whereHas('journalEntry', function ($query) use ($orgId, $fromDate, $toDate): void {
                    $query->where('organization_id', $orgId)
                        ->where('is_posted', true)
                        ->whereBetween('date', [$fromDate, $toDate]);
                })
                ->get();

            $outputEntries = $entries->where('type', VatEntryType::Output);
            $inputEntries = $entries->where('type', VatEntryType::Input);
            $inputInvestmentEntries = $entries->where('type', VatEntryType::InputInvestment);
            $acquisitionEntries = $entries->where('type', VatEntryType::Acquisition);

            [$revenueByRate, $outputVatByRate] = $this->aggregateOutputByRate($outputEntries);
            $totalRevenue = $this->sumEntries($outputEntries, 'base_amount');
            $totalOutputVat = $this->sumEntries($outputEntries, 'vat_amount');

            $deductionsByFigure = array_fill_keys(self::DEDUCTION_LINES, '0.00');
            foreach ($outputEntries as $entry) {
                if ($entry->figure !== null && array_key_exists($entry->figure, $deductionsByFigure)) {
                    $deductionsByFigure[$entry->figure] = Money::add(
                        $deductionsByFigure[$entry->figure],
                        (string) $entry->base_amount,
                    );
                }
            }

            $totalDeductions = array_reduce(
                $deductionsByFigure,
                fn (string $total, string $amount): string => Money::add($total, $amount),
                '0.00',
            );
            $totalTaxable = Money::subtract($totalRevenue, $totalDeductions);

            $outputVatRows = $this->buildOutputVatRows($orgId, $outputEntries);
            $totalInputVat400 = $this->sumEntries($inputEntries, 'vat_amount');
            $totalInputVat405 = $this->sumEntries($inputInvestmentEntries, 'vat_amount');
            $totalInputVat = Money::add($totalInputVat400, $totalInputVat405);
            $totalAcquisitionTax = $this->sumEntries($acquisitionEntries, 'vat_amount');
            $acquisitionBase = $this->sumEntries($acquisitionEntries, 'base_amount');
            $totalTaxOwed = Money::add($totalOutputVat, $totalAcquisitionTax);
            $netVat = Money::subtract($totalTaxOwed, $totalInputVat);
            $line500 = Money::isNegative($netVat) ? '0.00' : $netVat;
            $line510 = Money::isNegative($netVat) ? Money::negate($netVat) : '0.00';

            $turnoverRows = [['line' => '200', 'amount' => $totalRevenue]];
            foreach ($deductionsByFigure as $line => $amount) {
                $turnoverRows[] = ['line' => $line, 'amount' => $amount];
            }
            $turnoverRows[] = ['line' => '289', 'amount' => $totalDeductions];
            $turnoverRows[] = ['line' => '299', 'amount' => $totalTaxable];

            return [
                'period' => ['from' => $fromDate, 'to' => $toDate],
                'revenue_by_rate' => $revenueByRate,
                'revenue_rows' => $turnoverRows,
                'turnover_rows' => $turnoverRows,
                'total_revenue' => $totalRevenue,
                'deductions_by_figure' => $deductionsByFigure,
                'total_deductions' => $totalDeductions,
                'output_vat_by_rate' => $outputVatByRate,
                'output_vat_rows' => $outputVatRows,
                'total_taxable' => $totalTaxable,
                'total_output_vat' => $totalOutputVat,
                'acquisition_tax_base' => $acquisitionBase,
                'acquisition_tax' => $totalAcquisitionTax,
                'acquisition_rows' => [
                    ['line' => '383', 'taxable' => $acquisitionBase, 'vat' => $totalAcquisitionTax],
                ],
                'total_tax_owed' => $totalTaxOwed,
                'input_vat' => $totalInputVat400,
                'input_investment_vat' => $totalInputVat405,
                'total_input_vat' => $totalInputVat,
                'input_vat_rows' => [
                    ['line' => '400', 'amount' => $totalInputVat400],
                    ['line' => '405', 'amount' => $totalInputVat405],
                    ['line' => '479', 'amount' => $totalInputVat],
                ],
                'net_vat' => $netVat,
                'vat_payable' => $line500,
                'vat_credit' => $line510,
                'settlement_rows' => [
                    ['line' => '500', 'amount' => $line500],
                    ['line' => '510', 'amount' => $line510],
                ],
            ];
        });
    }

    /**
     * Generate a fresh (non-cached) VAT report by flushing the specific cache entry first.
     *
     * @return array<string, mixed>
     */
    public function generateFresh(string $orgId, string $fromDate, string $toDate): array
    {
        Cache::tags(["org:{$orgId}:reports"])->forget("vat_report:{$orgId}:{$fromDate}:{$toDate}");

        return $this->generate($orgId, $fromDate, $toDate);
    }

    /**
     * @param  Collection<int, VatEntry>  $entries
     * @return array{array<int, array<string, mixed>>, array<int, array<string, mixed>>}
     */
    private function aggregateOutputByRate(Collection $entries): array
    {
        $revenueByRate = [];
        $outputVatByRate = [];

        foreach ($entries->groupBy('vat_rate_id') as $rateId => $rateEntries) {
            /** @var VatEntry $firstEntry */
            $firstEntry = $rateEntries->first();
            // vat_entries.vat_rate_id is NOT NULL with a restricting foreign key,
            // and the relation is eager-loaded above, so it always resolves.
            $vatRate = $firstEntry->vatRate;
            $rateName = (string) $vatRate->name;
            $rateValue = (string) $vatRate->rate;
            $baseAmount = $this->sumEntries($rateEntries, 'base_amount');
            $vatAmount = $this->sumEntries($rateEntries, 'vat_amount');

            $row = [
                'rate_id' => $rateId,
                'rate_name' => $rateName,
                'rate' => $rateValue,
                'base_amount' => $baseAmount,
                'vat_amount' => $vatAmount,
            ];
            $revenueByRate[] = $row;
            $outputVatByRate[] = [...$row, 'amount' => $vatAmount];
        }

        return [$revenueByRate, $outputVatByRate];
    }

    /**
     * @param  Collection<int, VatEntry>  $entries
     * @return array<int, array{line: string, taxable: string, rate: string, vat: string}>
     */
    private function buildOutputVatRows(string $orgId, Collection $entries): array
    {
        $configuredRates = VatRate::query()
            ->where('organization_id', $orgId)
            ->whereIn('code', array_keys(self::OUTPUT_LINES_BY_RATE_CODE))
            ->get()
            ->keyBy('code');

        $rows = [];
        foreach (self::OUTPUT_LINES_BY_RATE_CODE as $rateCode => $line) {
            $vatRate = $configuredRates->get($rateCode);
            $rateEntries = $entries->filter(
                fn (VatEntry $entry): bool => $entry->vatRate?->code === $rateCode,
            );

            $rows[] = [
                'line' => $line,
                'taxable' => $this->sumEntries($rateEntries, 'base_amount'),
                'rate' => $vatRate !== null ? (string) $vatRate->rate : '0.00',
                'vat' => $this->sumEntries($rateEntries, 'vat_amount'),
            ];
        }

        return $rows;
    }

    /** @param Collection<int, VatEntry> $entries */
    private function sumEntries(Collection $entries, string $field): string
    {
        return $entries->reduce(
            fn (string $total, VatEntry $entry): string => Money::add($total, (string) $entry->getAttribute($field)),
            '0.00',
        );
    }
}
