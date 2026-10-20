<?php

namespace App\Domains\Api\Requests\Concerns;

use App\Domains\Accounting\Models\VatRate;
use App\Domains\Invoicing\Enums\InvoiceTaxTreatment;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Services\CurrentOrganization;
use Illuminate\Validation\Validator;

/**
 * Invoice and quote lines may name their VAT rate by code (`NORMAL`,
 * `EXEMPT`, ...) instead of its UUID. A VAT-registered organisation must
 * name one on every item line, so a forgotten rate is an error rather than
 * a line without VAT.
 */
trait NamesLineVatRates
{
    /** @return array<string, mixed> */
    protected function lineVatRules(): array
    {
        return [
            'lines.*.vat_rate_code' => ['nullable', 'string', 'max:20'],
        ];
    }

    /** Turns each known `vat_rate_code` into the UUID the rest of the request checks. */
    protected function resolveLineVatCodes(): void
    {
        $lines = $this->input('lines');
        if (! is_array($lines)) {
            return;
        }

        $org = app(CurrentOrganization::class);
        if (! $org->isBound()) {
            return;
        }

        $codes = VatRate::query()->where('organization_id', $org->id())->pluck('uuid', 'code')
            ->mapWithKeys(fn ($uuid, $code) => [mb_strtoupper((string) $code) => $uuid]);

        foreach ($lines as $i => $line) {
            if (! is_array($line) || ! filled($line['vat_rate_code'] ?? null) || filled($line['vat_rate_id'] ?? null)) {
                continue;
            }
            $uuid = $codes[mb_strtoupper(trim((string) $line['vat_rate_code']))] ?? null;
            if ($uuid !== null) {
                $lines[$i]['vat_rate_id'] = $uuid;
                unset($lines[$i]['vat_rate_code']);
            }
        }

        $this->merge(['lines' => $lines]);
    }

    /** Adds the errors for unknown codes and missing rates. */
    protected function checkLineVatRates(Validator $validator): void
    {
        $lines = $this->input('lines');
        if (! is_array($lines)) {
            return;
        }

        $org = app(CurrentOrganization::class);
        $organization = $org->isBound() ? Organization::find($org->id()) : null;
        $treatment = $this->input('tax_treatment', $this->currentTaxTreatment());
        $needsRate = filled($organization?->vat_number) && $treatment !== InvoiceTaxTreatment::ReverseCharge->value;

        foreach ($lines as $i => $line) {
            if (! is_array($line)) {
                continue;
            }
            if (filled($line['vat_rate_code'] ?? null)) {
                $validator->errors()->add("lines.{$i}.vat_rate_code", filled($line['vat_rate_id'] ?? null)
                    ? 'Give either vat_rate_id or vat_rate_code, not both.'
                    : 'Unknown VAT rate code. GET /api/v1/vat-rates lists the codes.');

                continue;
            }
            $type = $line['type'] ?? 'item';
            if ($needsRate && $type === 'item' && ! filled($line['vat_rate_id'] ?? null)) {
                $validator->errors()->add(
                    "lines.{$i}.vat_rate_id",
                    'The organisation is VAT-registered: give each item line a vat_rate_id or vat_rate_code (e.g. NORMAL, or EXEMPT for exempt services).',
                );
            }
        }
    }

    /** The tax treatment of the document being changed, if any. */
    protected function currentTaxTreatment(): ?string
    {
        return null;
    }
}
