<?php

namespace App\Domains\Expenses\Enums;

/**
 * How an expense is treated for Swiss VAT purposes.
 *
 * The counterpart of InvoiceTaxTreatment on the input side. Where the invoice
 * enum decides whether Swiss VAT is charged to a customer, this one decides
 * where the VAT on a purchase comes from and how it is posted:
 *
 *   Standard      Supplier charged Swiss VAT. Input tax is deductible and the
 *                 gross payment to the supplier includes it.
 *   ReverseCharge Foreign supplier billed without Swiss VAT (services acquired
 *                 from abroad, MWSTG Art. 45). The company owes acquisition tax
 *                 and — under the effective method — deducts it in the same
 *                 breath, so the payment stays net.
 *   ImportTax     Goods imported across the border. Import VAT is assessed by
 *                 customs, not by the supplier, so it cannot be derived from the
 *                 invoice and must be booked from the assessment decision.
 *   None          No VAT involved at all: bank charges, insurance premiums,
 *                 salaries, exempt turnover.
 *
 * Only Standard and ReverseCharge produce a VAT line by themselves.
 */
enum ExpenseTaxTreatment: string
{
    case Standard = 'standard';
    case ReverseCharge = 'reverse_charge';
    case ImportTax = 'import_tax';
    case None = 'none';

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }

    public function label(): string
    {
        return __('app.expense_tax_treatment_'.$this->value);
    }

    /**
     * Does the supplier invoice itself carry deductible Swiss input VAT?
     *
     * False for ReverseCharge — there the input tax exists, but it is derived
     * from the acquisition tax we owe ourselves, not from the supplier.
     */
    public function hasSupplierInputVat(): bool
    {
        return $this === self::Standard;
    }

    /**
     * Does the VAT amount increase what we actually pay the supplier?
     *
     * Only Standard. Acquisition tax is owed to the FTA, not to the creditor,
     * and import VAT is settled with customs — in both cases the bank movement
     * is the net amount.
     */
    public function increasesPayableAmount(): bool
    {
        return $this === self::Standard;
    }

    /**
     * Does posting require the acquisition-tax pair (2202 against 1170)?
     */
    public function requiresAcquisitionTaxEntry(): bool
    {
        return $this === self::ReverseCharge;
    }

    /**
     * Can the deductible VAT only be established from an external document?
     *
     * Import VAT sits on the customs assessment decision, so no rule and no
     * supplier invoice can supply it. The expense stays incomplete until the
     * document is filed.
     */
    public function requiresCustomsDocument(): bool
    {
        return $this === self::ImportTax;
    }
}
