<?php

namespace App\Services\Drafts;

use App\Models\ExtractedRecord;
use App\Models\Organization;
use App\Models\Position;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * Finds existing catalog records a draft might be a duplicate of.
 * Runs on draft load (the review show page), not at confirm time —
 * the result drives the "Merge into..." affordance that the user
 * sees alongside Confirm/Reject.
 *
 * Match rules per record type:
 *
 *   organization   — case-insensitive substring match against
 *                    organizations.name, in either direction.
 *                    ("Lightning" → "Lightning Labs" AND
 *                    "Stripe Inc" → "Stripe" both qualify.)
 *
 *   position       — exact case-insensitive title match within the
 *                    organization the draft references. Requires
 *                    that org already be in the catalog; otherwise
 *                    no candidates are possible.
 *
 *   project        — case-insensitive substring match against
 *                    projects.name, scoped to projects belonging
 *                    to the resolved organization. Same bidirectional
 *                    logic as organizations. Cross-org matches are
 *                    deliberately excluded — a project named
 *                    "Migration" at company A is not a duplicate
 *                    of one at company B.
 *
 *   accomplishment — not in scope for slice 4.5. Returns empty;
 *                    accomplishments have too much title variance
 *                    for naïve string matching to be useful.
 *
 * The bidirectional substring matching is done in PHP rather than
 * SQL to keep the query portable across MySQL (prod) and SQLite
 * (tests) without resorting to engine-specific string concatenation.
 * One fetch per detection call, filtered in memory — fine at MVP
 * scale and dwarfed by the cost of an AI API call anyway.
 */
class DuplicateDetector
{
    /**
     * @return Collection<int, Organization|Position|Project>
     */
    public function findCandidates(ExtractedRecord $draft): Collection
    {
        $payload = $draft->payload ?? [];

        return match ($draft->record_type) {
            'organization' => $this->findOrganizationCandidates(
                $payload['name'] ?? '',
            ),
            'position' => $this->findPositionCandidates(
                $payload['organization_name'] ?? '',
                $payload['title'] ?? '',
            ),
            'project' => $this->findProjectCandidates(
                $payload['organization_name'] ?? '',
                $payload['name'] ?? '',
            ),
            // Accomplishments and unknown types — no candidates.
            default => collect(),
        };
    }

    /**
     * Bidirectional case-insensitive substring match against the
     * organizations table. Loads orgs once and filters in PHP for
     * cross-DB portability — see class comment for rationale.
     */
    private function findOrganizationCandidates(string $name): Collection
    {
        $term = strtolower(trim($name));
        if ($term === '') {
            return collect();
        }

        return Organization::query()
            ->get()
            ->filter(fn (Organization $org) => $this->substringMatch($term, $org->name))
            ->values();
    }

    /**
     * Exact case-insensitive title match within the org named in
     * the draft. The parent organization must already exist in
     * the catalog; if it doesn't (e.g., the org draft hasn't been
     * confirmed yet), no candidate is possible and we return empty.
     */
    private function findPositionCandidates(string $orgName, string $title): Collection
    {
        $orgName = trim($orgName);
        $title = strtolower(trim($title));
        if ($orgName === '' || $title === '') {
            return collect();
        }

        $organization = $this->resolveOrganization($orgName);
        if (! $organization) {
            return collect();
        }

        return Position::query()
            ->where('organization_id', $organization->id)
            ->whereRaw('LOWER(title) = ?', [$title])
            ->get();
    }

    /**
     * Bidirectional case-insensitive substring match against
     * projects.name, scoped to projects belonging to the named
     * organization. Cross-org matches are deliberately excluded.
     */
    private function findProjectCandidates(string $orgName, string $projectName): Collection
    {
        $orgName = trim($orgName);
        $term = strtolower(trim($projectName));
        if ($orgName === '' || $term === '') {
            return collect();
        }

        $organization = $this->resolveOrganization($orgName);
        if (! $organization) {
            return collect();
        }

        return Project::query()
            ->where('organization_id', $organization->id)
            ->get()
            ->filter(fn (Project $project) => $this->substringMatch($term, $project->name))
            ->values();
    }

    /**
     * Resolve a draft's organization_name to a real Organization
     * record by exact case-insensitive name match. Returns null if
     * nothing matches; callers treat that as "no candidates possible
     * yet" rather than an error. Same lookup convention as
     * DraftConfirmer so the two services stay aligned on what
     * counts as a name match.
     */
    private function resolveOrganization(string $name): ?Organization
    {
        return Organization::query()
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->first();
    }

    /**
     * True when the search term and existing name are substrings
     * of each other in either direction. Inputs are normalized
     * (lowercase, trim) before comparison.
     *
     * Caller is expected to have already lowercased+trimmed `$term`
     * since the caller's normalization happens once per detection;
     * existing names come straight off the model so we normalize
     * them here.
     */
    private function substringMatch(string $term, ?string $existingName): bool
    {
        $existing = strtolower(trim((string) $existingName));
        if ($existing === '') {
            return false;
        }

        return str_contains($existing, $term)
            || str_contains($term, $existing);
    }
}