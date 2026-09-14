<?php

namespace App\Domains\Accounting\Controllers;

use App\Domains\Accounting\Enums\TaxDeclarationStatus;
use App\Domains\Accounting\Models\TaxDeclaration;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Models\VatEntry;
use App\Domains\Accounting\Requests\StoreTaxDeclarationRequest;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Accounting\Services\VatReportService;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TaxDeclarationController extends Controller
{
    public function __construct(
        private VatReportService $vatReports,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', TaxDeclaration::class);

        $declarations = TaxDeclaration::query()
            ->orderByDesc('fiscal_year')
            ->orderBy('canton')
            ->get();

        return Inertia::render('Accounting/TaxDeclarations/Index', [
            'declarations' => $declarations,
            'canManage' => request()->user()->can('create', TaxDeclaration::class),
        ]);
    }

    public function store(StoreTaxDeclarationRequest $request, CurrentOrganization $currentOrg): RedirectResponse
    {
        $this->authorize('create', TaxDeclaration::class);

        $validated = $request->validated();
        $validated['canton'] = strtoupper($validated['canton']);

        $declaration = TaxDeclaration::firstOrCreate(
            [
                'organization_id' => $currentOrg->id(),
                'fiscal_year' => $validated['fiscal_year'],
                'canton' => $validated['canton'],
            ],
            [
                'status' => TaxDeclarationStatus::Draft,
                'data' => $this->buildSummaryData($currentOrg->id(), $validated['fiscal_year']),
            ],
        );

        if ($declaration->status === TaxDeclarationStatus::Draft && empty($declaration->data)) {
            $declaration->update([
                'data' => $this->buildSummaryData($currentOrg->id(), $validated['fiscal_year']),
            ]);
        }

        return redirect()->route('accounting.tax-declarations.show', $declaration)
            ->with('success', __('app.saved'));
    }

    public function show(TaxDeclaration $taxDeclaration, CurrentOrganization $currentOrg): Response
    {
        $this->authorize('view', $taxDeclaration);

        if ($taxDeclaration->status === TaxDeclarationStatus::Draft) {
            $taxDeclaration->update([
                'data' => $this->buildSummaryData($currentOrg->id(), $taxDeclaration->fiscal_year),
            ]);
            $taxDeclaration->refresh();
        }

        return Inertia::render('Accounting/TaxDeclarations/Show', [
            'declaration' => $taxDeclaration,
            'canManage' => request()->user()->can('update', $taxDeclaration),
        ]);
    }

    public function finalize(TaxDeclaration $taxDeclaration, CurrentOrganization $currentOrg): RedirectResponse
    {
        $this->authorize('update', $taxDeclaration);

        if ($taxDeclaration->status === TaxDeclarationStatus::Draft) {
            $taxDeclaration->update([
                'status' => TaxDeclarationStatus::Finalized,
                'finalized_at' => now(),
                'locked_at' => now(),
                'locked_by_user_id' => request()->user()->id,
                'data' => $this->buildSummaryData($currentOrg->id(), $taxDeclaration->fiscal_year),
            ]);
        }

        return back()->with('success', __('app.saved'));
    }

    /**
     * @return array<string, float>
     */
    private function buildSummaryData(string $organizationId, int $fiscalYear): array
    {
        $lines = TransactionLine::query()
            ->select([
                'accounts.type as account_type',
                'journal_entries.type as entry_type',
                DB::raw('SUM(transaction_lines.debit) as total_debit'),
                DB::raw('SUM(transaction_lines.credit) as total_credit'),
            ])
            ->join('accounts', 'accounts.id', '=', 'transaction_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'transaction_lines.journal_entry_id')
            ->where('journal_entries.organization_id', $organizationId)
            ->where('journal_entries.is_posted', true)
            ->whereYear('journal_entries.date', $fiscalYear)
            ->groupBy('accounts.type', 'journal_entries.type')
            ->toBase()
            ->get();

        $totals = [
            'revenue' => 0.0,
            'expenses' => 0.0,
            'assets' => 0.0,
            'liabilities' => 0.0,
            'equity' => 0.0,
            'profit' => 0.0,
            'net_result' => 0.0,
        ];

        foreach ($lines as $line) {
            /** @var object{account_type:string,entry_type:string|null,total_credit:float|int|string,total_debit:float|int|string} $line */
            $isStructural = in_array($line->entry_type, LedgerQueryService::STRUCTURAL_ENTRY_TYPES, true);

            // What the year traded, which is what a tax return asks about. The
            // year-end closing empties every revenue and expense account by
            // design, so counting it in reported a closed year as nothing earned
            // and nothing spent. It stays counted for the balance-sheet
            // categories below, where it is what carries the result into equity.
            if ((string) $line->account_type === 'revenue' && ! $isStructural) {
                $totals['revenue'] += (float) $line->total_credit - (float) $line->total_debit;
            }

            if ((string) $line->account_type === 'expense' && ! $isStructural) {
                $totals['expenses'] += (float) $line->total_debit - (float) $line->total_credit;
            }

            if ((string) $line->account_type === 'asset') {
                $totals['assets'] += (float) $line->total_debit - (float) $line->total_credit;
            }

            if ((string) $line->account_type === 'liability') {
                $totals['liabilities'] += (float) $line->total_credit - (float) $line->total_debit;
            }

            if ((string) $line->account_type === 'equity') {
                $totals['equity'] += (float) $line->total_credit - (float) $line->total_debit;
            }
        }

        $totals['profit'] = $totals['revenue'] - $totals['expenses'];
        $totals['net_result'] = $totals['profit'];

        return $totals + $this->vatFigures($organizationId, "{$fiscalYear}-01-01", "{$fiscalYear}-12-31");
    }

    /**
     * VAT as settled rather than as guessed.
     *
     * This used to be one line — 8.1% of revenue — which named the output tax on
     * the standard rate and nothing else. It owed no account of input tax, so it
     * overstated what was payable by whatever had been reclaimed and could never
     * show a credit; it applied the standard rate to turnover taxed at 2.6% or
     * 3.8% and to turnover not taxed at all; and it presented a liability to
     * organizations that are not registered.
     *
     * {@see VatReportService} already computes the Swiss settlement from the VAT
     * recorded on each posting, which is what the VAT report shows. Reading it
     * here means the two agree by construction instead of by coincidence.
     *
     * `vat_output` is the tax owed before deduction, acquisition tax included,
     * so that output − input lands exactly on payable or credit.
     *
     * @return array<string, float>
     */
    private function vatFigures(string $organizationId, string $fromDate, string $toDate): array
    {
        // Nothing recorded is not the same as nothing owed. An organization that
        // books no VAT would otherwise read 0.00 as though it had been worked
        // out, which is the reading this whole change exists to avoid.
        $recordsVat = VatEntry::query()
            ->whereHas('journalEntry', fn ($query) => $query
                ->where('organization_id', $organizationId)
                ->where('is_posted', true)
                ->whereBetween('date', [$fromDate, $toDate]))
            ->exists();

        if (! $recordsVat) {
            return [];
        }

        $report = $this->vatReports->generate($organizationId, $fromDate, $toDate);

        return [
            'vat_output' => (float) $report['total_tax_owed'],
            'vat_input' => (float) $report['total_input_vat'],
            'vat_payable' => (float) $report['vat_payable'],
            'vat_credit' => (float) $report['vat_credit'],
        ];
    }
}
