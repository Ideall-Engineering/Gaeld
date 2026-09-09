<?php

namespace App\Domains\Accounting\Enums;

/**
 * Distinguishes input VAT (purchases) from output VAT (sales), and both from
 * acquisition tax on services bought abroad, which is owed alongside output VAT
 * but reported in its own figure of the FTA return.
 */
enum VatEntryType: string
{
    case Input = 'input';   // VAT on purchases (Vorsteuer)
    case Output = 'output'; // VAT on sales (Umsatzsteuer)
    case Acquisition = 'acquisition'; // VAT owed on foreign services (Bezugsteuer)

    public function label(): string
    {
        return match ($this) {
            self::Input => __('app.vat_entry_type_input'),
            self::Output => __('app.vat_entry_type_output'),
            self::Acquisition => __('app.vat_entry_type_acquisition'),
        };
    }
}
