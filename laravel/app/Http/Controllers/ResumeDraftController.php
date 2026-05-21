<?php

namespace App\Http\Controllers;

use App\Models\JobListing;
use App\Models\JobListingRequirement;
use App\Models\ResumeDraft;
use App\Models\ResumeSelection;
use App\Models\SourceDocument;
use App\Services\AiUsageTracker;
use App\Services\Extraction\ExtractionException;
use App\Services\Extraction\ExtractionProvider;
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
 * Orchestrates the resume generation wizard. The flow is a
 * three-screen wizard, all within the `selecting` status:
 *
 *   Screen 1  — Strategy & requirements triage (show)
 *   Screen 2  — Per-requirement selection review (showRequirement)
 *   Screen 3  — Confirm & generate (confirmPage / confirm)
 *
 * Routes:
 *   create            — POST: run AI analysis, persist requirements +
 *                       strategy + selections, redirect to Screen 1
 *   show              — GET: Screen 1 — strategy editor + requirement
 *                       triage with accept/reject and match counts
 *   decideRequirement — POST/AJAX: accept or reject a requirement
 *   showRequirement   — GET: Screen 2 — one requirement per page with
 *                       selection cards, catalog search, freeform text
 *   addSelection      — POST: add a catalog entry to a requirement
 *   submitExperience  — POST: submit freeform text, create source doc
 *   toggle            — POST/AJAX: flip a selection's `selected` boolean
 *   updateStrategy    — POST/AJAX: save edited strategy summary
 *   updateNote        — POST/AJAX: save a selection's user_relevance_note
 *   confirmPage       — GET: Screen 3 — summary of all decisions
 *   confirm           — POST: lock in selections, advance to `drafting`
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
     * Selectable types for the catalog search on Screen 2. Maps
     * short type slugs (used in form values) to model classes.
     */
    private const SELECTABLE_TYPES = [
        'position' => \App\Models\Position::class,
        'project' => \App\Models\Project::class,
        'accomplishment' => \App\Models\Accomplishment::class,
    ];

    // -----------------------------------------------------------------
    // Screen 1: Strategy & Requirements Triage
    // -----------------------------------------------------------------

    /**
     * Create a new resume draft for a job listing. Runs the full AI
     * analysis synchronously: extracts requirements from the listing,
     * produces a strategy summary, and maps catalog entries to
     * requirements. Persists everything in a transaction, then
     * redirects to Screen 1.
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
     * Screen 1: Strategy & requirements triage. Shows the editable
     * strategy summary and a list of requirements grouped by section,
     * each with a match count and accept/reject buttons. No selection
     * cards here — just the requirement and how many catalog entries
     * the AI mapped to it.
     */
    public function show(ResumeDraft $resumeDraft): View
    {
        $resumeDraft->load([
            'jobListing.organization',
            'jobListing.requirements',
        ]);

        $jobListing = $resumeDraft->jobListing;
        $requirements = $jobListing->requirements->sortBy('display_order');
        $decisions = $resumeDraft->requirement_decisions ?? [];

        // Count selections per requirement for the match count display.
        $matchCounts = $resumeDraft->selections()
            ->whereNotNull('job_listing_requirement_id')
            ->selectRaw('job_listing_requirement_id, count(*) as total')
            ->groupBy('job_listing_requirement_id')
            ->pluck('total', 'job_listing_requirement_id')
            ->all();

        // Group requirements by section for the view.
        $sections = $this->groupRequirementsBySection($requirements);

        // All requirements must be decided before proceeding.
        $allDecided = $requirements->count() > 0
            && $requirements->every(fn ($r) => isset($decisions[$r->id]));

        return view('resume-drafts.triage', [
            'draft' => $resumeDraft,
            'jobListing' => $jobListing,
            'sections' => $sections,
            'decisions' => $decisions,
            'matchCounts' => $matchCounts,
            'allDecided' => $allDecided,
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
     * AJAX: synthesize the AI-generated strategy with the user's
     * own framing. Uses the same ExtractionProvider::synthesize()
     * as the merge UI — takes two text values and produces a
     * combined result via AI.
     *
     * The user types their own strategy description (informed by
     * the requirements they can now see), then clicks Synthesize
     * to merge it with the AI's generated version. This addresses
     * the gap where the AI's strategy is limited by what's in the
     * catalog — the user brings context the AI doesn't have.
     */
    public function synthesizeStrategy(
        ResumeDraft $resumeDraft,
        Request $request,
        ExtractionProvider $provider,
        AiUsageTracker $tracker,
    ): JsonResponse {
        if (! $resumeDraft->isSelecting()) {
            return response()->json(['error' => 'Strategy is locked — this draft has already been confirmed.'], 422);
        }

        $validated = $request->validate([
            'ai_strategy' => ['present', 'nullable', 'string'],
            'user_strategy' => ['present', 'nullable', 'string'],
        ]);

        try {
            $result = $provider->synthesize(
                $validated['ai_strategy'] ?? '',
                $validated['user_strategy'] ?? '',
            );
        } catch (ExtractionException $e) {
            $tracker->recordFailure(
                provider: $provider->name(),
                model: 'unknown',
                operation: 'synthesize',
                errorMessage: $e->getMessage(),
            );

            return response()->json([
                'error' => 'Synthesis failed — try again, or edit the strategy manually.',
            ], 502);
        }

        $tracker->recordSynthesis($result, $provider->name());

        return response()->json([
            'ok' => true,
            'synthesized' => $result->description,
        ]);
    }

    /**
     * AJAX: accept or reject a requirement. Stores the decision in
     * the draft's `requirement_decisions` JSON column.
     */
    public function decideRequirement(
        ResumeDraft $resumeDraft,
        JobListingRequirement $requirement,
        Request $request,
    ): JsonResponse {
        if (! $resumeDraft->isSelecting()) {
            return response()->json(['error' => 'Decisions are locked — this draft has already been confirmed.'], 422);
        }

        // Verify the requirement belongs to this draft's listing.
        if ($requirement->job_listing_id !== $resumeDraft->job_listing_id) {
            return response()->json(['error' => 'Requirement does not belong to this listing.'], 404);
        }

        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:accepted,rejected'],
        ]);

        $decisions = $resumeDraft->requirement_decisions ?? [];
        $decisions[$requirement->id] = $validated['decision'];
        $resumeDraft->update(['requirement_decisions' => $decisions]);

        // Check whether all requirements are now decided.
        $totalRequirements = $resumeDraft->jobListing->requirements()->count();
        $allDecided = count($decisions) >= $totalRequirements;

        return response()->json([
            'ok' => true,
            'decision' => $validated['decision'],
            'all_decided' => $allDecided,
        ]);
    }

    // -----------------------------------------------------------------
    // Screen 2: Per-Requirement Review
    // -----------------------------------------------------------------

    /**
     * JSON: search across catalog entities for the picker on Screen 2.
     * Searches organizations, positions, projects, and accomplishments
     * by name/title. Returns a flat list with type slugs matching
     * SELECTABLE_TYPES so the picker can submit directly to addSelection.
     */
    public function catalogSearch(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if ($query === '') {
            return response()->json([]);
        }

        $like = '%' . addcslashes($query, '%_\\') . '%';
        $results = collect();

        // Positions — search by title OR parent organization name.
        $positions = \App\Models\Position::with('organization')
            ->where(function ($q) use ($like) {
                $q->where('title', 'LIKE', $like)
                    ->orWhereHas('organization', fn ($oq) => $oq->where('name', 'LIKE', $like));
            })
            ->take(4)->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'type' => 'position',
                'name' => $p->title . ' at ' . ($p->organization?->name ?? 'Unknown'),
                'context' => implode(' · ', array_filter([
                    $p->start_date?->format('M Y'),
                    $p->end_date ? $p->end_date->format('M Y') : 'Present',
                ])),
            ]);
        $results = $results->merge($positions);

        // Projects — search by name OR parent organization/position org name.
        $projects = \App\Models\Project::with(['organization', 'position.organization'])
            ->where(function ($q) use ($like) {
                $q->where('name', 'LIKE', $like)
                    ->orWhereHas('organization', fn ($oq) => $oq->where('name', 'LIKE', $like))
                    ->orWhereHas('position.organization', fn ($oq) => $oq->where('name', 'LIKE', $like));
            })
            ->take(4)->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'type' => 'project',
                'name' => $p->name,
                'context' => $p->position
                    ? ($p->position->title . ' at ' . ($p->position->organization?->name ?? 'Unknown'))
                    : ($p->organization?->name ?? ''),
            ]);
        $results = $results->merge($projects);

        // Accomplishments — search by title OR parent position/project org name.
        $accomplishments = \App\Models\Accomplishment::with(['position.organization', 'project.organization'])
            ->where(function ($q) use ($like) {
                $q->where('title', 'LIKE', $like)
                    ->orWhereHas('position.organization', fn ($oq) => $oq->where('name', 'LIKE', $like))
                    ->orWhereHas('project.organization', fn ($oq) => $oq->where('name', 'LIKE', $like));
            })
            ->take(4)->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'type' => 'accomplishment',
                'name' => \Illuminate\Support\Str::limit($a->title, 80),
                'context' => $a->position
                    ? ($a->position->title . ' at ' . ($a->position->organization?->name ?? 'Unknown'))
                    : ($a->project ? $a->project->name : ''),
            ]);
        $results = $results->merge($accomplishments);

        return response()->json($results->take(10)->values()->all());
    }

    /**
     * Screen 2: Per-requirement review. One requirement per page.
     * Shows the requirement header, AI-suggested selections with
     * include/exclude buttons, and a freeform text input for adding
     * new experience.
     */
    public function showRequirement(
        ResumeDraft $resumeDraft,
        JobListingRequirement $requirement,
    ): View|RedirectResponse {
        if (! $resumeDraft->isSelecting()) {
            return redirect()->route('resume-drafts.show', $resumeDraft);
        }

        // Verify the requirement belongs to this draft's listing.
        if ($requirement->job_listing_id !== $resumeDraft->job_listing_id) {
            abort(404);
        }

        $resumeDraft->load(['jobListing.organization', 'jobListing.requirements']);
        $jobListing = $resumeDraft->jobListing;
        $decisions = $resumeDraft->requirement_decisions ?? [];

        // Build the ordered list of accepted requirements for navigation.
        $acceptedRequirements = $jobListing->requirements
            ->sortBy('display_order')
            ->filter(fn ($r) => ($decisions[$r->id] ?? null) === 'accepted')
            ->values();

        $currentIndex = $acceptedRequirements->search(fn ($r) => $r->id === $requirement->id);

        // If this requirement isn't accepted, redirect to Screen 1.
        if ($currentIndex === false) {
            return redirect()->route('resume-drafts.show', $resumeDraft);
        }

        $previousRequirement = $currentIndex > 0
            ? $acceptedRequirements[$currentIndex - 1]
            : null;
        $nextRequirement = $currentIndex < $acceptedRequirements->count() - 1
            ? $acceptedRequirements[$currentIndex + 1]
            : null;

        // Load selections for this specific requirement.
        $selections = $resumeDraft->selections()
            ->where('job_listing_requirement_id', $requirement->id)
            ->with('selectable')
            ->orderBy('display_order')
            ->get();

        return view('resume-drafts.requirement', [
            'draft' => $resumeDraft,
            'jobListing' => $jobListing,
            'requirement' => $requirement,
            'selections' => $selections,
            'currentIndex' => $currentIndex,
            'totalAccepted' => $acceptedRequirements->count(),
            'previousRequirement' => $previousRequirement,
            'nextRequirement' => $nextRequirement,
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
     * Add a catalog entry as a selection for a specific requirement.
     * The user picks from the catalog search on Screen 2; this
     * creates a new ResumeSelection linked to both the draft and
     * the requirement.
     */
    public function addSelection(
        ResumeDraft $resumeDraft,
        JobListingRequirement $requirement,
        Request $request,
    ): RedirectResponse {
        if (! $resumeDraft->isSelecting()) {
            return redirect()->route('resume-drafts.show', $resumeDraft);
        }

        if ($requirement->job_listing_id !== $resumeDraft->job_listing_id) {
            abort(404);
        }

        $validated = $request->validate([
            'selectable_type' => ['required', 'string', 'in:' . implode(',', array_keys(self::SELECTABLE_TYPES))],
            'selectable_id' => ['required', 'integer'],
        ]);

        $modelClass = self::SELECTABLE_TYPES[$validated['selectable_type']];

        // Verify the record exists.
        if (! $modelClass::find($validated['selectable_id'])) {
            return redirect()
                ->route('resume-drafts.requirement', [$resumeDraft, $requirement])
                ->with('error', 'The selected catalog entry could not be found.');
        }

        // Don't create duplicates — check if this entry is already
        // selected for this requirement on this draft.
        $exists = $resumeDraft->selections()
            ->where('job_listing_requirement_id', $requirement->id)
            ->where('selectable_type', $modelClass)
            ->where('selectable_id', $validated['selectable_id'])
            ->exists();

        if (! $exists) {
            $maxOrder = $resumeDraft->selections()
                ->where('job_listing_requirement_id', $requirement->id)
                ->max('display_order') ?? 0;

            ResumeSelection::create([
                'resume_draft_id' => $resumeDraft->id,
                'job_listing_requirement_id' => $requirement->id,
                'selectable_type' => $modelClass,
                'selectable_id' => $validated['selectable_id'],
                'selected' => true,
                'display_order' => $maxOrder + 1,
            ]);
        }

        return redirect()->route('resume-drafts.requirement', [$resumeDraft, $requirement]);
    }

    /**
     * Submit freeform experience text for a requirement. Creates a
     * source document with origin tracking, then redirects to the
     * extraction preview page where the user can review the cost
     * estimate and run extraction. After extraction and review,
     * the user navigates back to the resume wizard.
     *
     * The modal version (keeping the user in the wizard context)
     * is a future enhancement. For now, the existing extraction
     * pipeline handles the heavy lifting.
     */
    public function submitExperience(
        ResumeDraft $resumeDraft,
        JobListingRequirement $requirement,
        Request $request,
    ): RedirectResponse {
        if (! $resumeDraft->isSelecting()) {
            return redirect()->route('resume-drafts.show', $resumeDraft);
        }

        if ($requirement->job_listing_id !== $resumeDraft->job_listing_id) {
            abort(404);
        }

        $validated = $request->validate([
            'experience_text' => ['required', 'string', 'max:10000'],
        ]);

        $document = SourceDocument::create([
            'title' => 'Experience for: ' . $requirement->title,
            'kind' => 'other',
            'origin' => 'requirement_response',
            'job_listing_requirement_id' => $requirement->id,
            'body' => $validated['experience_text'],
        ]);

        return redirect()->route('source-documents.preview', $document);
    }

    // -----------------------------------------------------------------
    // Screen 3: Confirm & Generate
    // -----------------------------------------------------------------

    /**
     * Screen 3: Confirmation summary. Shows a compact overview of
     * all accepted requirements, their included selection counts,
     * and the strategy summary (read-only at this point).
     */
    public function confirmPage(ResumeDraft $resumeDraft): View|RedirectResponse
    {
        if (! $resumeDraft->isSelecting()) {
            return redirect()->route('resume-drafts.show', $resumeDraft);
        }

        $resumeDraft->load(['jobListing.organization', 'jobListing.requirements']);
        $jobListing = $resumeDraft->jobListing;
        $decisions = $resumeDraft->requirement_decisions ?? [];

        // Accepted requirements with their included selection counts.
        $acceptedRequirements = $jobListing->requirements
            ->sortBy('display_order')
            ->filter(fn ($r) => ($decisions[$r->id] ?? null) === 'accepted')
            ->values();

        // Count included selections per accepted requirement.
        $includedCounts = $resumeDraft->selections()
            ->where('selected', true)
            ->whereIn('job_listing_requirement_id', $acceptedRequirements->pluck('id'))
            ->selectRaw('job_listing_requirement_id, count(*) as total')
            ->groupBy('job_listing_requirement_id')
            ->pluck('total', 'job_listing_requirement_id')
            ->all();

        // Count source documents created during per-requirement review.
        $experienceCounts = SourceDocument::where('origin', 'requirement_response')
            ->whereIn('job_listing_requirement_id', $acceptedRequirements->pluck('id'))
            ->selectRaw('job_listing_requirement_id, count(*) as total')
            ->groupBy('job_listing_requirement_id')
            ->pluck('total', 'job_listing_requirement_id')
            ->all();

        return view('resume-drafts.confirm', [
            'draft' => $resumeDraft,
            'jobListing' => $jobListing,
            'acceptedRequirements' => $acceptedRequirements,
            'includedCounts' => $includedCounts,
            'experienceCounts' => $experienceCounts,
        ]);
    }

    /**
     * Confirm selections and advance the draft to `drafting` status.
     * This locks in the user's choices — strategy, requirement
     * decisions, selections, and relevance notes all become read-only.
     */
    public function confirm(ResumeDraft $resumeDraft): RedirectResponse
    {
        if (! $resumeDraft->isSelecting()) {
            return redirect()
                ->route('resume-drafts.show', $resumeDraft)
                ->with('error', 'Selections have already been confirmed.');
        }

        // Require at least one accepted requirement with at least
        // one included selection across the whole draft.
        $decisions = $resumeDraft->requirement_decisions ?? [];
        $acceptedIds = collect($decisions)
            ->filter(fn ($d) => $d === 'accepted')
            ->keys()
            ->all();

        if (empty($acceptedIds)) {
            return redirect()
                ->route('resume-drafts.show', $resumeDraft)
                ->with('error', 'Accept at least one requirement before confirming.');
        }

        $selectedCount = $resumeDraft->selections()
            ->where('selected', true)
            ->whereIn('job_listing_requirement_id', $acceptedIds)
            ->count();

        if ($selectedCount === 0) {
            return redirect()
                ->route('resume-drafts.confirm-page', $resumeDraft)
                ->with('error', 'Include at least one catalog entry across your accepted requirements before confirming.');
        }

        $resumeDraft->update(['status' => 'drafting']);

        // TODO (5.3): Trigger draft generation here. For now, redirect
        // back with a status message indicating the selections are
        // locked in and draft generation is the next milestone.
        return redirect()
            ->route('job-listings.show', $resumeDraft->job_listing_id)
            ->with('status', 'Selections confirmed — draft generation is coming in the next milestone.');
    }

    // -----------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------

    /**
     * Group requirements by section for view rendering. Returns an
     * array keyed by section value, each containing a label and
     * the requirements in that section.
     */
    private function groupRequirementsBySection($requirements): array
    {
        $sectionOrder = ['required', 'preferred', 'responsibility'];
        $sections = [];

        foreach ($sectionOrder as $section) {
            $reqs = $requirements->where('section', $section);
            if ($reqs->isNotEmpty()) {
                $label = \App\Enums\RequirementSection::tryFrom($section)?->label() ?? ucfirst($section);
                $sections[$section] = [
                    'label' => $label,
                    'requirements' => $reqs->values(),
                ];
            }
        }

        return $sections;
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