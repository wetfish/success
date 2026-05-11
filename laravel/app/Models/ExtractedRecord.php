<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * A draft record produced by AI extraction. Stays in this staging
 * table until the user confirms (becoming a real record), rejects
 * (discarded), or merges (combined with an existing record).
 */
#[Fillable([
    'source_document_id',
    'record_type',
    'payload',
    'status',
    'match_record_type',
    'match_record_id',
])]
class ExtractedRecord extends Model
{
    public const RECORD_TYPES = ['organization', 'position', 'project', 'accomplishment'];
    public const STATUSES = ['pending', 'confirmed', 'rejected', 'merged'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(SourceDocument::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Find all pending drafts in the same source document that
     * transitively depend on this draft. "Depend on" means the
     * dependent draft references this one by name in its payload.
     *
     *   organization → positions/projects/accomplishments with matching
     *                  organization_name
     *   position     → projects/accomplishments with matching
     *                  organization_name + position_title
     *   project      → accomplishments with matching project_name
     *   accomplishment → no dependents (leaf type)
     *
     * Returns a Collection of ExtractedRecord, not including self.
     * Pending-only — confirmed/rejected/merged drafts are not affected
     * by cascade. Same-source-document only — drafts from other
     * documents are unrelated.
     *
     * Used by cascade rejection: rejecting an org also rejects the
     * positions/projects/accomplishments that would have referenced it.
     */
    public function findDependents(): Collection
    {
        // Direct dependents only — recursion happens via repeated calls
        // accumulated below.
        $direct = $this->directDependents();

        $all = collect();
        $queue = $direct->all();
        $seenIds = [$this->id];

        while ($queue) {
            $current = array_shift($queue);
            if (in_array($current->id, $seenIds, true)) {
                continue;
            }
            $seenIds[] = $current->id;
            $all->push($current);

            foreach ($current->directDependents() as $next) {
                if (! in_array($next->id, $seenIds, true)) {
                    $queue[] = $next;
                }
            }
        }

        return $all;
    }

    /**
     * Drafts that directly reference this one in their payload.
     * Same-source-document, pending-only. Helper for findDependents.
     */
    private function directDependents(): Collection
    {
        $payload = $this->payload ?? [];

        return match ($this->record_type) {
            'organization' => $this->findByOrganizationName($payload['name'] ?? null),
            'position' => $this->findByPositionRef(
                $payload['organization_name'] ?? null,
                $payload['title'] ?? null,
            ),
            'project' => $this->findByProjectName($payload['name'] ?? null),
            default => collect(),
        };
    }

    private function findByOrganizationName(?string $name): Collection
    {
        if (! $name) {
            return collect();
        }

        return self::query()
            ->where('source_document_id', $this->source_document_id)
            ->where('status', 'pending')
            ->whereIn('record_type', ['position', 'project', 'accomplishment'])
            ->get()
            ->filter(fn ($r) => strcasecmp(
                $r->payload['organization_name'] ?? '',
                $name,
            ) === 0);
    }

    private function findByPositionRef(?string $orgName, ?string $title): Collection
    {
        if (! $orgName || ! $title) {
            return collect();
        }

        return self::query()
            ->where('source_document_id', $this->source_document_id)
            ->where('status', 'pending')
            ->whereIn('record_type', ['project', 'accomplishment'])
            ->get()
            ->filter(fn ($r) =>
                strcasecmp($r->payload['organization_name'] ?? '', $orgName) === 0
                && strcasecmp($r->payload['position_title'] ?? '', $title) === 0
            );
    }

    private function findByProjectName(?string $name): Collection
    {
        if (! $name) {
            return collect();
        }

        return self::query()
            ->where('source_document_id', $this->source_document_id)
            ->where('status', 'pending')
            ->where('record_type', 'accomplishment')
            ->get()
            ->filter(fn ($r) => strcasecmp(
                $r->payload['project_name'] ?? '',
                $name,
            ) === 0);
    }
}