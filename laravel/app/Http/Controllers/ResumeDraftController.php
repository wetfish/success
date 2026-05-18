<?php

namespace App\Http\Controllers;

use App\Models\JobListing;
use App\Models\JobListingRequirement;
use App\Models\ResumeDraft;
use App\Models\ResumeSelection;
use App\Services\AiUsageTracker;
use App\Services\Resume\CatalogSummarizer;
use App\Services\Resume\ResumeAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * Orchestrates the resume generation wizard. The flow is
 * requirement-centric: the AI extracts requirements from the
 * listing, produces a strategy summary, and maps catalog entries
 * to specific requirements.
 *
 * Routes:
 *   create         — POST: run AI analysis, persist requirements +
 *                    strategy + selections, redirect to review
 *   show           — GET: render the selection review page (step 1)
 *   toggle         — POST/AJAX: flip a selection's `selected` boolean
 *   updateStrategy — POST/AJAX: save edited strategy summary
 *   updateNote     — POST/AJAX: save a selection's user_relevance_note
 *   confirm        — POST: lock in selections, advance to `drafting`
 *
 * Future methods (5.3, 5.4) will handle draft generation, editing,
 * and formatted document output.
 */
class ResumeDraftController extends Controller
{
    /**
     * Shared model→type mapping. Maps AI response type strings to
     * fully-qualified model classes.
     */
    private const MODEL_MAP = [
        'Position' => \App\Models\Position::class,
        'Project' => \App\Models\Project::class,
        'Accomplishment' => \App\Models\Accomplishment::class,
        'CareerTheme' => \App\Models\CareerTheme::class,
        'Tag' => \App\Models\Tag::class,
        'Link' => \App\Models\Link::class,
    ];

    /**
     * Create a new resume draft for a job listing. Runs the full AI
     * analysis synchronously: extracts requirements from the listing,
     * produces a strategy summary, and maps catalog entries to
     * requirements. Persists everything in a transaction, then
     * redirects to the selection review page.
     *
     * Requirements are persisted on the job listing (shared across
     * drafts). If the listing already has requirements from a prior
     * draft, they're reused — the AI only extracts requirements once
     * per listing. A fresh draft still gets its own strategy and
     * selections.
     *
     * If the listing already has a draft in `selecting` status, we
     * redirect to that instead of creating a duplicate.
     */
    public function create(
        JobListing $jobListing,
        CatalogSummarizer $summarizer,
        ResumeAiService $aiService,
        AiUsageTracker $tracker,
    ): RedirectResponse {
        // Resume an existing in-progress draft if one exists.
        $existingDraft = $jobListing->resumeDrafts()
            ->where('status', 'selecting')
            ->first();

        if ($existingDraft) {
            return redirect()->route('resume-drafts.show', $existingDraft);
        }

        $catalogSummary = $summarizer->summarize();

        try {
            $result = $aiService->analyzeRelevance(
                $catalogSummary,
                $jobListing->body,
                $jobListing->role_title,
            );
        } catch (Throwable $e) {
            Log::error('Resume relevance analysis failed', [
                'job_listing_id' => $jobListing->id,
                'exception' => $e->getMessage(),
            ]);

            $tracker->recordFailure(
                provider: 'claude',
                model: config('services.extraction.model', 'claude-sonnet-4-6'),
                operation: 'analyze_relevance',
                errorMessage: $e->getMessage(),
            );

            return redirect()
                ->route('job-listings.show', $jobListing)
                ->with('error', 'Resume analysis failed — please try again. If the problem persists, the document may be too large for synchronous processing.');
        }

        $draft = DB::transaction(function () use ($jobListing, $result, $tracker) {
            // Persist requirements on the listing if they don't
            // already exist. Build a ref→id map for linking selections.
            $refToRequirementId = $this->persistRequirements(
                $jobListing,
                $result->requirements,
            );

            // Create the draft with strategy summary.
            $draft = ResumeDraft::create([
                'job_listing_id' => $jobListing->id,
                'strategy_summary_generated' => $result->strategySummary,
                'strategy_summary' => $result->strategySummary,
                'status' => 'selecting',
            ]);

            $tracker->recordResumeAi(
                result: $result,
                provider: 'claude',
                operation: 'analyze_relevance',
                resumeDraft: $draft,
            );

            // Persist selections, resolving requirement_ref to real IDs.
            $this->persistSelections(
                $draft,
                $result->selections,
                $refToRequirementId,
            );

            return $draft;
        });

        return redirect()->route('resume-drafts.show', $draft);
    }

    /**
     * The selection review page (step 1). Organized by requirement:
     * strategy summary at the top, then sections grouped by
     * requirement section (required → preferred → responsibility),
     * each requirement showing its mapped selections. Unlinked
     * selections appear in an "Other" section at the bottom.
     */
    public function show(ResumeDraft $resumeDraft): View
    {
        $resumeDraft->load([
            'jobListing.organization',
            'jobListing.requirements',
            'selections.selectable',
            'selections.requirement',
        ]);

        $jobListing = $resumeDraft->jobListing;
        $requirements = $jobListing->requirements->sortBy('display_order');

        // Group requirements by section for the view.
        $sectionOrder = ['required', 'preferred', 'responsibility'];
        $sectionLabels = [
            'required' => 'Requirements',
            'preferred' => 'Nice to Have',
            'responsibility' => 'Responsibilities',
        ];

        $sections = [];
        foreach ($sectionOrder as $section) {
            $reqs = $requirements->where('section', $section);
            if ($reqs->isNotEmpty()) {
                $sections[$section] = [
                    'label' => $sectionLabels[$section],
                    'requirements' => $reqs->values(),
                ];
            }
        }

        // Index selections by requirement ID for fast lookup.
        $selectionsByRequirement = $resumeDraft->selections
            ->sortBy('display_order')
            ->groupBy('job_listing_requirement_id');

        // Unlinked selections (requirement_id = null) go in "Other".
        $unlinkedSelections = $selectionsByRequirement->get('', collect())
            ->merge($selectionsByRequirement->get(null, collect()))
            ->values();

        return view('resume-drafts.selections', [
            'draft' => $resumeDraft,
            'jobListing' => $jobListing,
            'sections' => $sections,
            'selectionsByRequirement' => $selectionsByRequirement,
            'unlinkedSelections' => $unlinkedSelections,
        ]);
    }

    /**
     * AJAX: toggle a selection's `selected` state.
     */
    public function toggle(ResumeDraft $resumeDraft, ResumeSelection $selection): JsonResponse
    {
        if ($selection->resume_draft_id !== $resumeDraft->id) {
            return response()->json(['error' => 'Selection does not belong to this draft.'], 404);
        }

        if (! $resumeDraft->isSelecting()) {
            return response()->json(['error' => 'Selections are locked — this draft has already been confirmed.'], 422);
        }

        $selection->update(['selected' => ! $selection->selected]);

        return response()->json([
            'ok' => true,
            'selected' => $selection->selected,
        ]);
    }

    /**
     * AJAX: save the user-edited strategy summary.
     */
    public function updateStrategy(ResumeDraft $resumeDraft, Request $request): JsonResponse
    {
        if (! $resumeDraft->isSelecting()) {
            return response()->json(['error' => 'Strategy is locked — this draft has already been confirmed.'], 422);
        }

        $validated = $request->validate([
            'strategy_summary' => ['required', 'string', 'max:5000'],
        ]);

        $resumeDraft->update(['strategy_summary' => $validated['strategy_summary']]);

        return response()->json(['ok' => true]);
    }

    /**
     * AJAX: save the user's relevance note on a selection.
     */
    public function updateNote(ResumeDraft $resumeDraft, ResumeSelection $selection, Request $request): JsonResponse
    {
        if ($selection->resume_draft_id !== $resumeDraft->id) {
            return response()->json(['error' => 'Selection does not belong to this draft.'], 404);
        }

        if (! $resumeDraft->isSelecting()) {
            return response()->json(['error' => 'Notes are locked — this draft has already been confirmed.'], 422);
        }

        $validated = $request->validate([
            'user_relevance_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $selection->update(['user_relevance_note' => $validated['user_relevance_note']]);

        return response()->json(['ok' => true]);
    }

    /**
     * Confirm selections and advance the draft to `drafting` status.
     * This locks in the user's choices — strategy, selections, and
     * relevance notes all become read-only.
     */
    public function confirm(ResumeDraft $resumeDraft): RedirectResponse
    {
        if (! $resumeDraft->isSelecting()) {
            return redirect()
                ->route('resume-drafts.show', $resumeDraft)
                ->with('error', 'Selections have already been confirmed.');
        }

        $selectedCount = $resumeDraft->selections()->where('selected', true)->count();
        if ($selectedCount === 0) {
            return redirect()
                ->route('resume-drafts.show', $resumeDraft)
                ->with('error', 'Select at least one item to include in your resume before confirming.');
        }

        $resumeDraft->update(['status' => 'drafting']);

        // TODO (5.3): Trigger draft generation here. For now, redirect
        // back with a status message indicating the selections are
        // locked in and draft generation is the next milestone.
        return redirect()
            ->route('job-listings.show', $resumeDraft->job_listing_id)
            ->with('status', 'Selections confirmed — draft generation is coming in the next milestone.');
    }

    /**
     * Persist AI-extracted requirements on the job listing. If the
     * listing already has requirements (from a prior draft), reuse
     * them and return their ref→id map. Otherwise, create them.
     *
     * @return array<string, int>  Map of AI ref labels → requirement IDs.
     */
    private function persistRequirements(
        JobListing $jobListing,
        \Illuminate\Support\Collection $aiRequirements,
    ): array {
        // If requirements already exist, build a map by matching
        // on title (requirements don't change between drafts for
        // the same listing).
        $existing = $jobListing->requirements;
        if ($existing->isNotEmpty()) {
            $refMap = [];
            $existingByTitle = $existing->keyBy(
                fn (JobListingRequirement $r) => mb_strtolower($r->title)
            );

            foreach ($aiRequirements as $req) {
                $key = mb_strtolower($req['title']);
                if ($existingByTitle->has($key)) {
                    $refMap[$req['ref']] = $existingByTitle->get($key)->id;
                }
            }

            return $refMap;
        }

        // First time — create all requirements.
        $refMap = [];
        foreach ($aiRequirements as $req) {
            $record = JobListingRequirement::create([
                'job_listing_id' => $jobListing->id,
                'category' => $req['category'],
                'title' => $req['title'],
                'description' => $req['description'],
                'section' => $req['section'],
                'display_order' => $req['order'],
            ]);
            $refMap[$req['ref']] = $record->id;
        }

        return $refMap;
    }

    /**
     * Persist AI-suggested selections, resolving requirement_ref
     * labels to real job_listing_requirements IDs. Validates that
     * each referenced catalog record exists before creating the
     * selection.
     */
    private function persistSelections(
        ResumeDraft $draft,
        \Illuminate\Support\Collection $aiSelections,
        array $refToRequirementId,
    ): void {
        foreach ($aiSelections as $suggestion) {
            $modelClass = self::MODEL_MAP[$suggestion['type']] ?? null;
            if (! $modelClass) {
                continue;
            }

            // Verify the referenced catalog record exists.
            if (! $modelClass::find($suggestion['id'])) {
                continue;
            }

            // Resolve the requirement ref to a real ID.
            $requirementId = null;
            if ($suggestion['requirement_ref'] !== null) {
                $requirementId = $refToRequirementId[$suggestion['requirement_ref']] ?? null;
            }

            ResumeSelection::create([
                'resume_draft_id' => $draft->id,
                'job_listing_requirement_id' => $requirementId,
                'selectable_type' => $modelClass,
                'selectable_id' => $suggestion['id'],
                'selected' => true,
                'ai_reasoning' => $suggestion['reason'],
                'display_order' => $suggestion['order'],
            ]);
        }
    }
}