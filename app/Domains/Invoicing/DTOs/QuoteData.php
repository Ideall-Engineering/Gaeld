<?php

namespace App\Domains\Invoicing\DTOs;

use App\Domains\Invoicing\Enums\InvoiceTaxTreatment;
use App\Support\ValidatesFromArray;

/**
 * The editable content of a quote: header fields and lines.
 */
readonly class QuoteData
{
    use ValidatesFromArray;

    /**
     * @param  array<int, InvoiceLineData>  $lines
     */
    public function __construct(
        public string $organizationId,
        public ?int $customerId,
        public string $issueDate,
        public ?string $validUntil,
        public ?string $title,
        public string $currency,
        public ?string $notes,
        public ?string $paymentTerms,
        public array $lines,
        public InvoiceTaxTreatment $taxTreatment = InvoiceTaxTreatment::Standard,
        public ?string $contactPersonId = null,
        public ?string $deliveryNote = null,
        public ?string $introText = null,
        public bool $contactPersonNamed = true,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        self::assertRequired($data, ['organization_id', 'issue_date', 'lines']);

        return new self(
            organizationId: $data['organization_id'],
            customerId: blank($data['customer_id'] ?? null) ? null : (int) $data['customer_id'],
            issueDate: $data['issue_date'],
            validUntil: blank($data['valid_until'] ?? null) ? null : $data['valid_until'],
            title: blank($data['title'] ?? null) ? null : $data['title'],
            currency: $data['currency'] ?? 'CHF',
            notes: $data['notes'] ?? null,
            paymentTerms: blank($data['payment_terms'] ?? null) ? null : (string) $data['payment_terms'],
            lines: array_map(fn (array $line) => InvoiceLineData::fromArray($line), $data['lines']),
            taxTreatment: InvoiceTaxTreatment::tryFrom($data['tax_treatment'] ?? InvoiceTaxTreatment::Standard->value)
                ?? InvoiceTaxTreatment::Standard,
            contactPersonId: blank($data['contact_person_id'] ?? null) ? null : (string) $data['contact_person_id'],
            deliveryNote: blank($data['delivery_note'] ?? null) ? null : $data['delivery_note'],
            introText: blank($data['intro_text'] ?? null) ? null : $data['intro_text'],
            contactPersonNamed: array_key_exists('contact_person_id', $data),
        );
    }
}
