<?php

namespace App\Domains\Invoicing\Support;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Models\ContactPerson;
use Closure;

/**
 * The contact person a quote or invoice is addressed to (spec 011). It must
 * belong to the document's customer; a person of another contact counts as
 * none.
 */
final class DocumentContactPerson
{
    public static function of(?Contact $customer, ?string $personId): ?ContactPerson
    {
        if ($customer === null || blank($personId)) {
            return null;
        }

        return ContactPerson::query()
            ->whereKey($personId)
            ->where('contactable_type', $customer->getMorphClass())
            ->where('contactable_id', $customer->id)
            ->first();
    }

    /**
     * The person a new document gets when none was named: the customer's
     * primary person, or its only one.
     */
    public static function primaryOf(?Contact $customer): ?ContactPerson
    {
        if ($customer === null) {
            return null;
        }

        $persons = ContactPerson::query()
            ->where('contactable_type', $customer->getMorphClass())
            ->where('contactable_id', $customer->id)
            ->orderByDesc('is_primary')
            ->limit(2)
            ->get();
        $first = $persons->first();

        return $first !== null && ($first->is_primary || $persons->count() === 1) ? $first : null;
    }

    /**
     * Validation rule: the person belongs to the customer the request names,
     * found by the given lookup.
     *
     * @param  Closure(): (Contact|null)  $customer
     */
    public static function rule(Closure $customer): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($customer): void {
            if (blank($value)) {
                return;
            }
            if (! is_string($value) || self::of($customer(), $value) === null) {
                $fail(__('app.contact_person_not_of_customer'));
            }
        };
    }

    /** @return array<int, array{id: string, contact_id: int, name: string, department: string|null, is_primary: bool}> */
    public static function options(): array
    {
        return ContactPerson::query()
            ->where('contactable_type', (new Contact)->getMorphClass())
            ->whereIn('contactable_id', Contact::query()->select('id'))
            ->orderByDesc('is_primary')->orderBy('last_name')->orderBy('first_name')
            ->get()
            ->map(fn (ContactPerson $p) => [
                'id' => $p->id,
                'contact_id' => (int) $p->contactable_id,
                'name' => $p->full_name,
                'department' => $p->department,
                'is_primary' => $p->is_primary,
            ])->values()->all();
    }
}
