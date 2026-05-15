<?php

namespace App\Services\Resolution;

use App\Models\Person;

/**
 * Resolves person names against the catalog. Same shape as TagResolver
 * but simpler — people don't have aliases. Two operations:
 *
 *   resolve($name) — find an existing person by case-insensitive name,
 *                    or create a new one with just the name. Used by
 *                    DraftConfirmer at confirm time.
 *
 *   preview($name) — find what would happen WITHOUT creating anything.
 *                    Used by the AI extraction review UI to show the
 *                    user whether a name will match an existing person
 *                    or create a new one.
 *
 * Auto-create supports the AI's "every mention is a collaborator slot"
 * output shape — people don't need to already exist in the catalog
 * before being mentioned. When a top-level Person draft is later
 * confirmed for the same name, DraftConfirmer's confirmPerson()
 * recognizes the existing record and reuses it rather than duplicating.
 */
class PersonResolver
{
    /**
     * Find an existing person by name without creating. Returns null
     * if no match exists. Used by callers that want to perform their
     * own create logic with different default fields (e.g.,
     * DraftConfirmer::confirmPerson creates with the full payload,
     * not just the name).
     */
    public function findByName(string $name): ?Person
    {
        $trimmed = trim($name);
        $lowered = strtolower($trimmed);

        return Person::query()
            ->whereRaw('LOWER(name) = ?', [$lowered])
            ->first();
    }

    /**
     * Resolve a name to a Person. Creates a new person if no
     * case-insensitive name match exists.
     */
    public function resolve(string $name): Person
    {
        $person = $this->findByName($name);
        if ($person) {
            return $person;
        }

        return Person::create(['name' => trim($name)]);
    }

    /**
     * Preview what would happen for a given name without creating
     * anything. Returns an array describing the outcome:
     *
     *   ['status' => 'existing', 'person' => Person]
     *     — exact name match
     *
     *   ['status' => 'new', 'person' => null]
     *     — no match; resolve() would create a new person
     *
     * Mirrors TagResolver::preview's shape (minus the 'alias' status,
     * since people don't have aliases) so the review UI can render
     * both with similar templates.
     */
    public function preview(string $name): array
    {
        $person = $this->findByName($name);
        if ($person) {
            return ['status' => 'existing', 'person' => $person];
        }

        return ['status' => 'new', 'person' => null];
    }
}