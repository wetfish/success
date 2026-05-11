<?php

namespace App\Services\Drafts;

use App\Models\ExtractedRecord;
use App\Models\Organization;
use App\Models\Position;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Executes a merge of an ExtractedRecord into an existing catalog
 * record. The draft itself doesn't become a new record — instead,
 * the existing target is updated with the user's chosen per-field
 * values, the draft is marked as merged with a pointer to the
 * target, and any pending dependent drafts have their parent-name
 * references rewritten to the target's canonical name so they
 * continue resolving at confirmation time.
 *
 * Parallel to DraftConfirmer in shape: a single public `merge()`
 * method, an internal transaction, exception conversion at the
 * boundary, and the same filterFillable() approach to dropping
 * non-mappable payload keys.
 *
 * The rewrite step (step 4 below) is what makes the merge stick
 * across the rest of the review queue. Without it, a position
 * draft that referenced "Lightning Labs" by name would still fail
 * confirmation with "not in your catalog" even after the org was
 * merged into "Lightning Labs Inc." — because the position
 * payload's organization_name string was never updated.
 *
 * Dependent walk is delegated to ExtractedRecord::findDependents(),
 * which is the same walk used by cascade rejection. Keeping merge
 * and reject aligned on what counts as a dependency means the two
 * features can't drift out of sync.
 */
class DraftMerger
{
    /**
     * Execute the merge.
     *
     * @param  ExtractedRecord  $draft  the pending draft being merged
     * @param  Model  $target  the existing catalog record to merge into
     * @param  array<string, mixed>  $fieldChoices  payload-keyed array
     *         of the user's chosen value per field. Whatever the user
     *         picked or synthesized in the UI; the service doesn't
     *         care which "side" each value came from. Keys that aren't
     *         fillable on the target are filtered out.
     *
     * @return Model  the freshly-loaded target reflecting the merged state
     *
     * @throws DraftMergerException  when the draft isn't pending, the
     *         target is the wrong type, or a model invariant rejects
     *         the chosen values
     */
    public function merge(ExtractedRecord $draft, Model $target, array $fieldChoices): Model
    {
        if (! $draft->isPending()) {
            throw new DraftMergerException(
                'This draft has already been reviewed.'
            );
        }

        $expectedClass = $this->modelClassFor($draft->record_type);
        if (! $target instanceof $expectedClass) {
            throw new DraftMergerException(
                "Can't merge a {$draft->record_type} draft into a " .
                class_basename($target) . " record."
            );
        }

        // Defensive: never merge into a soft-deleted target. Eloquent's
        // default query excludes trashed records, but a programmer
        // could pass one in directly.
        if (method_exists($target, 'trashed') && $target->trashed()) {
            throw new DraftMergerException(
                "Can't merge into a deleted record. Restore it first."
            );
        }

        try {
            return DB::transaction(function () use ($draft, $target, $fieldChoices, $expectedClass) {
                // Collect dependents BEFORE applying any changes. The
                // walk reads the draft's current payload to figure out
                // what depends on it; if we updated the draft first
                // the dependency edges would be stale.
                $dependents = $draft->findDependents();

                // Apply chosen field values to the target. Filter to
                // fillable so payload keys that don't map to columns
                // on the target (like organization_name on a position
                // payload, which is resolved separately) are dropped.
                $attributes = $this->filterFillable($fieldChoices, $expectedClass);
                if (! empty($attributes)) {
                    $target->update($attributes);
                }

                // Mark the draft merged with a pointer to the target,
                // mirroring the way DraftConfirmer sets match_record_*
                // — except this time the pointer is to an existing
                // record, not a newly-created one.
                $draft->update([
                    'status' => 'merged',
                    'match_record_type' => $expectedClass,
                    'match_record_id' => $target->id,
                ]);

                // Rewrite parent-name references in dependent drafts
                // so they confirm against the merged target rather
                // than the now-stale draft name. Reload once and use
                // the same instance for the rewrite and the return so
                // we don't pay for two SELECTs.
                $merged = $target->fresh();
                $this->rewriteDependents($dependents, $draft, $merged);

                return $merged;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // DB constraint failure — most often a NOT NULL violation
            // if the user cleared a required field via the merge UI.
            // Surface the column name in the message; that's what the
            // user can act on.
            throw new DraftMergerException(
                "Can't merge this {$draft->record_type} — the target " .
                "record has a constraint that the chosen values would " .
                "violate. (Database error: {$e->getMessage()})"
            );
        } catch (\InvalidArgumentException $e) {
            // Model invariant guard — for example, Project's
            // cross-org parent check. Models raise these with
            // already-readable messages.
            throw new DraftMergerException(
                "Can't merge this {$draft->record_type}: {$e->getMessage()}"
            );
        }
    }

    /**
     * Map a draft's record_type to the corresponding model class.
     * Accomplishments aren't supported here — the duplicate detector
     * doesn't surface them as candidates, so the merger should never
     * be invoked with one.
     */
    private function modelClassFor(string $recordType): string
    {
        return match ($recordType) {
            'organization' => Organization::class,
            'position' => Position::class,
            'project' => Project::class,
            default => throw new DraftMergerException(
                "Merge isn't supported for record type \"{$recordType}\"."
            ),
        };
    }

    /**
     * Walk each pending dependent draft and rewrite the field that
     * points at this merged draft. Which field depends on what was
     * merged:
     *
     *   - merged org      → dependent's `organization_name`
     *   - merged position → dependent's `position_title`
     *   - merged project  → dependent's `project_name`
     *
     * The rewrite uses the target's canonical name (after any merge
     * edits) so subsequent confirmation lookups against the target
     * succeed via exact-name match.
     *
     * Note: this matches the dependency shape that
     * ExtractedRecord::findDependents() walks. The walk does NOT
     * include parent_project_name references (a sub-project draft
     * pointing at this draft as its parent). That mirrors the cascade
     * rejection behavior — if it changes there it should change here
     * too, to keep the two flows in sync.
     */
    private function rewriteDependents(Collection $dependents, ExtractedRecord $draft, Model $target): void
    {
        $field = $this->dependentRewriteField($draft->record_type);
        $canonicalName = $this->canonicalNameOf($target);

        if ($field === null || $canonicalName === null) {
            // Nothing to rewrite — either an unsupported draft type
            // (shouldn't happen after the modelClassFor check) or the
            // target lacks a usable name attribute. Bail quietly.
            return;
        }

        foreach ($dependents as $dependent) {
            $payload = $dependent->payload ?? [];
            $payload[$field] = $canonicalName;
            $dependent->update(['payload' => $payload]);
        }
    }

    private function dependentRewriteField(string $recordType): ?string
    {
        return match ($recordType) {
            'organization' => 'organization_name',
            'position' => 'position_title',
            'project' => 'project_name',
            default => null,
        };
    }

    /**
     * Pull the display name off the target. Orgs and projects use
     * `name`; positions use `title`. This is the value dependent
     * drafts will reference going forward.
     */
    private function canonicalNameOf(Model $target): ?string
    {
        if ($target instanceof Position) {
            return $target->title;
        }
        // Organization and Project both use `name`.
        return $target->name ?? null;
    }

    /**
     * Filter a payload to only the keys that are fillable on the
     * target model. Same logic as DraftConfirmer::filterFillable —
     * drops keys that don't map to columns (organization_name,
     * project_name, position_title, etc.) and any extraneous
     * fields beyond our schema.
     */
    private function filterFillable(array $payload, string $modelClass): array
    {
        /** @var Model $instance */
        $instance = new $modelClass;
        $fillable = $instance->getFillable();

        return array_intersect_key($payload, array_flip($fillable));
    }
}