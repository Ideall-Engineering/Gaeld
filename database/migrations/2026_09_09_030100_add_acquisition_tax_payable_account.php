<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds 2202 "Bezugsteuer geschuldet" for every existing organization.
 *
 * Acquisition tax deliberately does not share 2200 with output VAT. It is
 * declared in its own figures of the FTA return (380/381), and mixing it into
 * turnover tax makes that split unrecoverable at year end.
 */
return new class extends Migration
{
    private const CODE = '2202';

    public function up(): void
    {
        $names = [
            'de' => 'Bezugsteuer geschuldet',
            'fr' => 'Impôt sur les acquisitions dû',
            'it' => 'Imposta sull\'acquisto dovuta',
            'en' => 'Acquisition Tax Payable',
        ];

        foreach (DB::table('organizations')->get(['id', 'locale']) as $organization) {
            $locale = substr((string) ($organization->locale ?? 'en'), 0, 2);

            DB::table('accounts')->insertOrIgnore([
                'uuid' => (string) Str::uuid(),
                'organization_id' => $organization->id,
                'code' => self::CODE,
                'name' => $names[$locale] ?? $names['en'],
                'type' => 'liability',
                'is_active' => true,
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('accounts')
            ->where('code', self::CODE)
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw('1'))
                    ->from('transaction_lines')
                    ->whereColumn('transaction_lines.account_id', 'accounts.id');
            })
            ->delete();
    }
};
