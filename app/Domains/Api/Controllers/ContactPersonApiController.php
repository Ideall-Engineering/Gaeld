<?php

namespace App\Domains\Api\Controllers;

use App\Domains\Api\Requests\ContactPersonApiRequest;
use App\Domains\Api\Resources\ContactPersonResource;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Models\ContactPerson;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * JSON API access to the contact persons of a contact. Changing a person
 * counts as changing its contact, as in the web interface.
 *
 * @group Contacts
 */
class ContactPersonApiController extends Controller
{
    /**
     * List contact persons
     *
     * Returns all contact persons of a contact, the primary one first.
     *
     * @urlParam contact string required The contact UUID. Example: 9c8f1b2a-3d4e-5f67-8901-abcdef123456
     */
    public function index(Contact $contact, CurrentOrganization $currentOrganization): AnonymousResourceCollection
    {
        $this->ensureOrganization($contact, $currentOrganization->id());
        $this->authorize('view', $contact);

        return ContactPersonResource::collection(
            $contact->contactPersons()->orderByDesc('is_primary')->orderBy('last_name')->orderBy('first_name')->get()
        );
    }

    /**
     * Create contact person
     *
     * Adds a contact person to a contact. With `is_primary` true the person
     * becomes the only primary contact person.
     *
     * @urlParam contact string required The contact UUID. Example: 9c8f1b2a-3d4e-5f67-8901-abcdef123456
     */
    public function store(ContactPersonApiRequest $request, Contact $contact, CurrentOrganization $currentOrganization): JsonResponse
    {
        $this->ensureOrganization($contact, $currentOrganization->id());
        $this->authorize('update', $contact);

        $person = DB::transaction(function () use ($request, $contact): ContactPerson {
            $validated = $request->validated();
            if ($validated['is_primary'] ?? false) {
                $contact->contactPersons()->update(['is_primary' => false]);
            }

            return $contact->contactPersons()->create($validated);
        });

        return (new ContactPersonResource($person->fresh()))->response()->setStatusCode(201);
    }

    /**
     * Show contact person
     *
     * @urlParam contact string required The contact UUID. Example: 9c8f1b2a-3d4e-5f67-8901-abcdef123456
     * @urlParam person string required The contact person ID.
     */
    public function show(Contact $contact, ContactPerson $person, CurrentOrganization $currentOrganization): ContactPersonResource
    {
        $this->ensureBelongs($contact, $person, $currentOrganization->id());
        $this->authorize('view', $contact);

        return new ContactPersonResource($person);
    }

    /**
     * Update contact person
     *
     * Changes only the fields that are sent.
     *
     * @urlParam contact string required The contact UUID. Example: 9c8f1b2a-3d4e-5f67-8901-abcdef123456
     * @urlParam person string required The contact person ID.
     */
    public function update(ContactPersonApiRequest $request, Contact $contact, ContactPerson $person, CurrentOrganization $currentOrganization): ContactPersonResource
    {
        $this->ensureBelongs($contact, $person, $currentOrganization->id());
        $this->authorize('update', $contact);

        DB::transaction(function () use ($request, $contact, $person): void {
            $validated = $request->validated();
            if ($validated['is_primary'] ?? false) {
                $contact->contactPersons()->whereKeyNot($person->id)->update(['is_primary' => false]);
            }
            $person->update($validated);
        });

        return new ContactPersonResource($person->fresh());
    }

    /**
     * Delete contact person
     *
     * @urlParam contact string required The contact UUID. Example: 9c8f1b2a-3d4e-5f67-8901-abcdef123456
     * @urlParam person string required The contact person ID.
     */
    public function destroy(Contact $contact, ContactPerson $person, CurrentOrganization $currentOrganization): JsonResponse
    {
        $this->ensureBelongs($contact, $person, $currentOrganization->id());
        $this->authorize('update', $contact);
        $person->delete();

        return response()->json(null, 204);
    }

    private function ensureOrganization(Contact $contact, string $organizationId): void
    {
        abort_unless($contact->organization_id === $organizationId, 404);
    }

    private function ensureBelongs(Contact $contact, ContactPerson $person, string $organizationId): void
    {
        $this->ensureOrganization($contact, $organizationId);
        abort_unless(
            $person->contactable_type === $contact->getMorphClass() && (string) $person->contactable_id === (string) $contact->id,
            404,
        );
    }
}
