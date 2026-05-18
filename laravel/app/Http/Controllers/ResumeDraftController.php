<?php

namespace App\Http\Controllers;

use App\Models\JobListing;
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
 * Orchestrates the resume generation wizard. Each route maps to a
 * step in the flow:
 *
 *   create  — kick off a new draft: run AI relevance analysis,
 *             populate resume_selections, redirect to the review page
 *   show    — render the selection review page (step 1)
 *   toggle  — AJAX: flip a selection's `selected` boolean
 *   confirm — lock in selections, advance status to `drafting`
 *
 * Future methods (5.3, 5.4) will handle draft generation, editing,
 * and formatted document output.
 */
class ResumeDraftController extends Controller
{
    /**
     * Create a new resume draft for a job listing. Runs the AI
     * relevance analysis synchronously, populates resume_selections,
     * and redirects to the selection review page.
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

        // Create the draft and selections in a transaction.
        $draft = DB::transaction(function () use ($jobListing, $result, $tracker) {
            $draft = ResumeDraft::create([
                'job_listing_id' => $jobListing->id,
                'status' => 'selecting',
            ]);

            $tracker->recordResumeAi(
                result: $result,
                provider: 'claude',
                operation: 'analyze_relevance',
                resumeDraft: $draft,
            );

            // Map the AI's suggestions to resume_selections. Each
            // suggestion references a catalog entry by type and ID.
            // We validate the type maps to a real model class and
            // that the record exists before creating the selection.
            $modelMap = [
                'Position' => \App\Models\Position::class,
                'Project' => \App\Models\Project::class,
                'Accomplishment' => \App\Models\Accomplishment::class,
                'CareerTheme' => \App\Models\CareerTheme::class,
                'Tag' => \App\Models\Tag::class,
                'Link' => \App\Models\Link::class,
            ];

            foreach ($result->suggestions as $suggestion) {
                $modelClass = $modelMap[$suggestion['type']] ?? null;
                if (! $modelClass) {
                    continue;
                }

                // Verify the referenced record exists.
                if (! $modelClass::find($suggestion['id'])) {
                    continue;
                }

                ResumeSelection::create([
                    'resume_draft_id' => $draft->id,
                    'selectable_type' => $modelClass,
                    'selectable_id' => $suggestion['id'],
                    'selected' => true,
                    'ai_reasoning' => $suggestion['reason'],
                    'display_order' => $suggestion['order'],
                ]);
            }

            return $draft;
        });

        return redirect()->route('resume-drafts.show', $draft);
    }

    /**
     * The selection review page (step 1). Shows all AI-suggested
     * catalog entries grouped by type, with toggles and AI reasoning.
     */
    public function show(ResumeDraft $resumeDraft): View
    {
        $resumeDraft->load(['jobListing.organization', 'selections.selectable']);

        // Group selections by entity type for the view. Within each
        // group, respect the AI's suggested display_order.
        $typeOrder = ['Position', 'Project', 'Accomplishment', 'CareerTheme', 'Tag', 'Link'];
        $typeLabels = [
            'Position' => 'Positions',
            'Project' => 'Projects',
            'Accomplishment' => 'Accomplishments',
            'CareerTheme' => 'Career Themes',
            'Tag' => 'Skills & Tags',
            'Link' => 'Portfolio & Links',
        ];

        // Build the grouped structure. Positions get special treatment:
        // we nest projects and accomplishments under their parent
        // position for a resume-like hierarchy.
        $selections = $resumeDraft->selections
            ->sortBy('display_order');

        // Separate by type.
        $grouped = [];
        foreach ($typeOrder as $type) {
            $shortType = class_basename("App\\Models\\{$type}");
            $matching = $selections->filter(
                fn (ResumeSelection $s) => class_basename($s->selectable_type) === $shortType
            );
            if ($matching->isNotEmpty()) {
                $grouped[$type] = [
                    'label' => $typeLabels[$type],
                    'selections' => $matching->values(),
                ];
            }
        }

        // Build position→project and position→accomplishment maps
        // so the view can nest them visually.
        $projectsByPosition = [];
        $accomplishmentsByPosition = [];
        $accomplishmentsByProject = [];

        if (isset($grouped['Project'])) {
            foreach ($grouped['Project']['selections'] as $sel) {
                $project = $sel->selectable;
                if ($project && $project->position_id) {
                    $projectsByPosition[$project->position_id][] = $sel;
                }
            }
        }

        if (isset($grouped['Accomplishment'])) {
            foreach ($grouped['Accomplishment']['selections'] as $sel) {
                $accomplishment = $sel->selectable;
                if ($accomplishment) {
                    if ($accomplishment->project_id) {
                        $accomplishmentsByProject[$accomplishment->project_id][] = $sel;
                    } elseif ($accomplishment->position_id) {
                        $accomplishmentsByPosition[$accomplishment->position_id][] = $sel;
                    }
                }
            }
        }

        return view('resume-drafts.selections', [
            'draft' => $resumeDraft,
            'jobListing' => $resumeDraft->jobListing,
            'grouped' => $grouped,
            'projectsByPosition' => $projectsByPosition,
            'accomplishmentsByPosition' => $accomplishmentsByPosition,
            'accomplishmentsByProject' => $accomplishmentsByProject,
        ]);
    }

    /**
     * AJAX: toggle a selection's `selected` state.
     * Returns JSON: {ok: true, selected: bool}
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
     * Confirm selections and advance the draft to `drafting` status.
     * This locks in the user's choices — future steps will use the
     * accepted selections to generate the resume prose.
     */
    public function confirm(ResumeDraft $resumeDraft): RedirectResponse
    {
        if (! $resumeDraft->isSelecting()) {
            return redirect()
                ->route('resume-drafts.show', $resumeDraft)
                ->with('error', 'Selections have already been confirmed.');
        }

        // Must have at least one selected entry.
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
}