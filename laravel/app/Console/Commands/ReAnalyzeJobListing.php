<?php

namespace App\Console\Commands;

use App\Models\JobListing;
use App\Models\JobListingRequirement;
use App\Models\ResumeDraft;
use App\Models\ResumeSelection;
use App\Services\AiUsageTracker;
use App\Services\Resume\CatalogSummarizer;
use App\Services\Resume\ResumeAiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Re-analyze a job listing: wipe its requirements and all drafts in
 * `selecting` status, then re-run the AI analysis pipeline to produce
 * fresh requirements, strategy, and selections.
 *
 * This is a destructive operation for in-progress drafts: any draft
 * still in `selecting` status (and its selections) is deleted. Drafts
 * that have been confirmed (`drafting`, `editing`, `approved`,
 * `formatted`) are preserved — they represent completed work.
 *
 * Requirements are always re-extracted because they feed into the
 * new draft's selections. If confirmed drafts reference the old
 * requirements via their selections' `job_listing_requirement_id`,
 * those FKs are set to null by the cascade rule.
 *
 * Useful for development and prompt iteration: change the AI prompt,
 * re-run, compare results — without polluting the database with
 * duplicate listings.
 *
 * Usage:
 *   php artisan resume:re-analyze --listing=3
 *     Re-analyzes a single listing. Prompts for confirmation.
 *
 *   php artisan resume:re-analyze --listing=3 --no-interaction
 *     Skips the confirmation prompt. For scripted use.
 */
class ReAnalyzeJobListing extends Command
{
    protected $signature = 'resume:re-analyze
        {--listing= : Target JobListing by id (required)}';

    protected $description = 'Delete in-progress drafts and requirements, then re-run AI analysis for a job listing';

    public function handle(
        CatalogSummarizer $summarizer,
        ResumeAiService $aiService,
        AiUsageTracker $tracker,
    ): int {
        $listingId = $this->option('listing');
        if ($listingId === null) {
            $this->error('The --listing option is required. Usage: php artisan resume:re-analyze --listing=3');
            return self::FAILURE;
        }

        $listing = JobListing::with('organization')->find($listingId);
        if (! $listing) {
            $this->error("No JobListing with id={$listingId}.");
            return self::FAILURE;
        }

        // Count what we're about to delete.
        $requirementCount = $listing->requirements()->count();
        $selectingDrafts = $listing->resumeDrafts()->where('status', 'selecting')->get();
        $confirmedDrafts = $listing->resumeDrafts()->where('status', '!=', 'selecting')->count();

        $this->info("Listing: {$listing->role_title} at {$listing->organization->name} (id={$listing->id})");
        $this->line("Requirements: {$requirementCount}");
        $this->line("In-progress drafts to delete: {$selectingDrafts->count()}");
        $this->line("Confirmed drafts (preserved): {$confirmedDrafts}");
        $this->newLine();

        if ($requirementCount > 0 || $selectingDrafts->isNotEmpty()) {
            $this->warn('This will delete ALL requirements and in-progress drafts for this listing.');
            $this->warn('Confirmed drafts and their selections are NOT affected.');
            $this->warn('AI usage events are preserved for cost tracking.');

            if ($this->input->isInteractive() && ! $this->confirm('Proceed with re-analysis?', false)) {
                $this->info('Aborted.');
                return self::SUCCESS;
            }
        }

        // Step 1: Delete in-progress drafts (cascade deletes their selections).
        if ($selectingDrafts->isNotEmpty()) {
            $deletedDrafts = ResumeDraft::whereIn('id', $selectingDrafts->pluck('id'))
                ->forceDelete();
            $this->line("Deleted {$deletedDrafts} in-progress draft(s) and their selections.");
        }

        // Step 2: Delete requirements. Confirmed draft selections that
        // reference these requirements get nulled via the FK cascade.
        if ($requirementCount > 0) {
            $deletedReqs = JobListingRequirement::where('job_listing_id', $listing->id)->delete();
            $this->line("Deleted {$deletedReqs} requirement(s).");
        }

        // Step 3: Summarize catalog.
        $this->info('Summarizing catalog...');
        $catalogSummary = $summarizer->summarize();

        if (str_contains($catalogSummary, 'catalog is empty')) {
            $this->warn('Catalog is empty — the AI will have nothing to match against.');
        }

        // Step 4: Run AI analysis.
        $this->info('Running AI analysis...');
        $start = microtime(true);

        try {
            $result = $aiService->analyzeRelevance(
                $catalogSummary,
                $listing->body,
                $listing->role_title,
            );
        } catch (Throwable $e) {
            $this->error("AI analysis failed: {$e->getMessage()}");
            $tracker->recordFailure(
                provider: 'claude',
                model: config('services.extraction.model', 'claude-sonnet-4-6'),
                operation: 'analyze_relevance',
                errorMessage: $e->getMessage(),
            );
            return self::FAILURE;
        }

        $elapsed = round(microtime(true) - $start, 2);

        // Step 5: Persist everything in a transaction.
        $draft = DB::transaction(function () use ($listing, $result, $tracker) {
            // Create requirements.
            $refMap = [];
            foreach ($result->requirements as $req) {
                $record = JobListingRequirement::create([
                    'job_listing_id' => $listing->id,
                    'category' => $req['category'],
                    'title' => $req['title'],
                    'description' => $req['description'],
                    'section' => $req['section'],
                    'display_order' => $req['order'],
                ]);
                $refMap[$req['ref']] = $record->id;
            }

            // Create draft with strategy.
            $draft = ResumeDraft::create([
                'job_listing_id' => $listing->id,
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

            // Create selections.
            $modelMap = [
                'Position' => \App\Models\Position::class,
                'Project' => \App\Models\Project::class,
                'Accomplishment' => \App\Models\Accomplishment::class,
                'CareerTheme' => \App\Models\CareerTheme::class,
                'Tag' => \App\Models\Tag::class,
                'Link' => \App\Models\Link::class,
            ];

            $selectionCount = 0;
            foreach ($result->selections as $suggestion) {
                $modelClass = $modelMap[$suggestion['type']] ?? null;
                if (! $modelClass || ! $modelClass::find($suggestion['id'])) {
                    continue;
                }

                $requirementId = null;
                if ($suggestion['requirement_ref'] !== null) {
                    $requirementId = $refMap[$suggestion['requirement_ref']] ?? null;
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
                $selectionCount++;
            }

            return [$draft, $selectionCount];
        });

        [$draft, $selectionCount] = $draft;

        $this->newLine();
        $this->info("Done in {$elapsed}s.");
        $this->line("Model: {$result->model}");
        $this->line("Input tokens: {$result->inputTokens}");
        $this->line("Output tokens: {$result->outputTokens}");
        $this->line("Requirements extracted: {$result->requirements->count()}");
        $this->line("Strategy summary: " . \Illuminate\Support\Str::limit($result->strategySummary, 100));
        $this->line("Selections created: {$selectionCount}");
        $this->line("Draft id: {$draft->id} (status: selecting)");
        $this->newLine();
        $this->info("Review at: /resume-drafts/{$draft->id}");

        return self::SUCCESS;
    }
}