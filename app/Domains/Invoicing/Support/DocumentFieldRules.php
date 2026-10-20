<?php

namespace App\Domains\Invoicing\Support;

use App\Domains\Contacts\Models\Contact;
use Closure;

/**
 * Validation of the fields quotes and invoices share since spec 011: the
 * contact person, delivery note, intro text and the unit of each line.
 */
final class DocumentFieldRules
{
    /**
     * @param  Closure(): (Contact|null)  $customer  the customer the request names
     * @return array<string, mixed>
     */
    public static function for(Closure $customer): array
    {
        return [
            'contact_person_id' => ['nullable', 'uuid', DocumentContactPerson::rule($customer)],
            'delivery_note' => ['nullable', 'string', 'max:255'],
            'intro_text' => ['nullable', 'string', 'max:5000'],
            'lines.*.unit' => ['nullable', 'string', 'max:20'],
        ];
    }
}
