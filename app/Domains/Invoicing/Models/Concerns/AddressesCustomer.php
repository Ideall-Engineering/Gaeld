<?php

namespace App\Domains\Invoicing\Models\Concerns;

use App\Domains\Contacts\Models\ContactPerson;
use App\Domains\Invoicing\Support\DocumentContactPerson;

/**
 * Whom a quote or invoice is addressed to. A draft always shows the customer
 * as it is today, with the chosen person or else the primary one; once the
 * document leaves the draft, the snapshot taken at that moment counts.
 */
trait AddressesCustomer
{
    abstract public function isDraftDocument(): bool;

    /**
     * @return array{name: string, email: string|null, address: string|null, postal_code: string|null, city: string|null, country: string, country_name?: string|null, salutation?: string|null, vat_number: string|null, contact_person?: array{name: string, department: string|null, position: string|null, email: string|null}}|null
     */
    public function customerDetailsForDocument(): ?array
    {
        if ($this->isDraftDocument() && $this->customer !== null) {
            return $this->customer->toInvoiceSnapshot($this->addressedPerson());
        }

        return $this->customer_snapshot ?? $this->customer?->toInvoiceSnapshot($this->contactPerson);
    }

    /** The chosen person of the customer, or else its primary person. */
    public function addressedPerson(): ?ContactPerson
    {
        return DocumentContactPerson::of($this->customer, $this->contact_person_id)
            ?? DocumentContactPerson::primaryOf($this->customer);
    }

    /** Freezes the address as the draft showed it; call when the document leaves the draft. */
    public function freezeCustomerDetails(): void
    {
        if ($this->customer === null) {
            return;
        }
        $person = $this->addressedPerson();
        $this->forceFill([
            'contact_person_id' => $person?->id,
            'customer_snapshot' => $this->customer->toInvoiceSnapshot($person),
        ])->save();
    }
}
