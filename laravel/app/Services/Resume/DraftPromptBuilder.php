<?php

namespace App\Services\Resume;

use App\Models\Accomplishment;
use App\Models\CareerTheme;
use App\Models\JobListingRequirement;
use App\Models\Link;
use App\Models\Position;
use App\Models\Project;
use App\Models\ResumeDraft;
use App\Models\ResumeSelection;
use App\Models\Tag;
use Illuminate\Support\Collection;

/**
 * Serializes a confirmed ResumeDraft into structured text for the
 * AI generation prompt. Pure read service — no side effects.
 *
 * The output groups data by requirement (the structure the user
 * curated during the wizard), not by position hierarchy (the
 * structure CatalogSummarizer uses for the initial analysis).
 * Each requirement section includes the user's included selections
 * with their evidence details, AI reasoning, and user notes.
 *
 * Duplicate requirements are folded into their primary's section
 * so the AI knows to address both in the same resume content.
 */
class DraftPromptBuilder
{
    /**
     * Build the full prompt context for draft generation.
     */
    public function build(ResumeDraft $draft): string
    {
        $draft->load([
            'jobListing.organization',
            'jobListing.requirements',
            'selections' => fn ($q) => $q->where('selected', true)->orderBy('display_order'),
            'selections.selectable',
        ]);

        $sections = array_filter([
            $this->buildListingContext($draft),
            $this->buildStrategySection($draft),
            $this->buildRequirementSections($draft),
        ]);

        return implode("\n\n---\n\n", $sections);
    }

    private function buildListingContext(ResumeDraft $draft): string
    {
        $listing = $draft->jobListing;
        $orgName = $listing->organization?->name ?? 'Unknown';

        return implode("\n", [
            '## Target Role',
            '',
            "**Position:** {$listing->role_title}",
            "**Organization:** {$orgName}",
            '',
            '### Listing',
            '',
            $listing->body,
        ]);
    }

    private function buildStrategySection(ResumeDraft $draft): string
    {
        return implode("\n", [
            '## Resume Strategy',
            '',
            $draft->strategy_summary,
        ]);
    }

    private function buildRequirementSections(ResumeDraft $draft): string
    {
        $decisions = $draft->requirement_decisions ?? [];
        $requirements = $draft->jobListing->requirements->keyBy('id');
        $selectionsByReq = $draft->selections->groupBy('job_listing_requirement_id');

        // Build a map of primary → duplicate requirement titles.
        $duplicateMap = $this->buildDuplicateMap($decisions, $requirements);

        // Collect accepted requirement IDs in display order.
        $acceptedIds = $requirements
            ->sortBy('display_order')
            ->filter(fn ($r) => ($decisions[$r->id] ?? null) === 'accepted')
            ->pluck('id');

        $lines = ['## Requirements & Evidence'];

        foreach ($acceptedIds as $reqId) {
            $requirement = $requirements->get($reqId);
            if (! $requirement) {
                continue;
            }

            $lines[] = '';
            $lines[] = $this->formatRequirementHeader($requirement, $duplicateMap[$reqId] ?? []);

            // Include selections from this requirement and any duplicates.
            $allReqIds = collect([$reqId])->merge(
                collect($decisions)
                    ->filter(fn ($d) => is_array($d) && ($d['duplicate_of'] ?? null) === $reqId)
                    ->keys()
            );

            $selections = $allReqIds
                ->flatMap(fn ($id) => $selectionsByReq->get($id, collect()))
                ->sortBy('display_order');

            if ($selections->isEmpty()) {
                $lines[] = '';
                $lines[] = '_No catalog entries selected for this requirement._';
                continue;
            }

            foreach ($selections as $selection) {
                $lines[] = '';
                $lines[] = $this->formatSelection($selection);
            }
        }

        // Selections not tied to any requirement (general resume content).
        $unlinkedSelections = $selectionsByReq->get(null, collect())
            ->merge($selectionsByReq->get('', collect()));

        if ($unlinkedSelections->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '### General Resume Content';
            $lines[] = 'These entries strengthen the resume overall without mapping to a specific requirement.';

            foreach ($unlinkedSelections as $selection) {
                $lines[] = '';
                $lines[] = $this->formatSelection($selection);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Build a map of primary requirement ID → array of duplicate
     * requirement titles, so the AI knows which requirements are
     * addressed together.
     */
    private function buildDuplicateMap(array $decisions, Collection $requirements): array
    {
        $map = [];

        foreach ($decisions as $reqId => $decision) {
            if (is_array($decision) && isset($decision['duplicate_of'])) {
                $primaryId = $decision['duplicate_of'];
                $duplicateReq = $requirements->get($reqId);
                if ($duplicateReq) {
                    $map[$primaryId][] = $duplicateReq->title;
                }
            }
        }

        return $map;
    }

    private function formatRequirementHeader(
        JobListingRequirement $requirement,
        array $duplicateTitles,
    ): string {
        $sectionLabel = \App\Enums\RequirementSection::tryFrom($requirement->section)?->label()
            ?? ucfirst($requirement->section);

        $lines = ["### {$requirement->title} ({$sectionLabel})"];

        if ($requirement->description) {
            $lines[] = $requirement->description;
        }

        if (! empty($duplicateTitles)) {
            $lines[] = 'Also addresses: ' . implode(', ', $duplicateTitles);
        }

        return implode("\n", $lines);
    }

    private function formatSelection(ResumeSelection $selection): string
    {
        $selectable = $selection->selectable;

        if (! $selectable) {
            return '- _[deleted record]_';
        }

        $lines = [$this->formatSelectable($selectable)];

        if ($selection->ai_reasoning) {
            $lines[] = "  Relevance: {$selection->ai_reasoning}";
        }

        if ($selection->user_relevance_note) {
            $lines[] = "  User note: {$selection->user_relevance_note}";
        }

        return implode("\n", $lines);
    }

    private function formatSelectable(mixed $selectable): string
    {
        return match (true) {
            $selectable instanceof Position => $this->formatPosition($selectable),
            $selectable instanceof Project => $this->formatProject($selectable),
            $selectable instanceof Accomplishment => $this->formatAccomplishment($selectable),
            $selectable instanceof CareerTheme => $this->formatCareerTheme($selectable),
            $selectable instanceof Tag => $this->formatTag($selectable),
            $selectable instanceof Link => $this->formatLink($selectable),
            default => "- {$selectable->name ?? $selectable->title ?? '[unknown]'}",
        };
    }

    private function formatPosition(Position $position): string
    {
        $position->loadMissing('organization');
        $orgName = $position->organization?->name ?? 'Unknown';
        $dates = $this->formatDateRange($position->start_date, $position->end_date);

        $lines = ["- **Position:** {$position->title} at {$orgName} ({$dates})"];

        $meta = array_filter([
            $position->employment_type ? str_replace('_', ' ', $position->employment_type) : null,
            $position->team_name ? "Team: {$position->team_name}" : null,
            $position->team_size_immediate ? "{$position->team_size_immediate} people" : null,
        ]);
        if (! empty($meta)) {
            $lines[] = '  ' . implode(' · ', $meta);
        }

        if ($position->mandate) {
            $lines[] = "  Mandate: {$position->mandate}";
        }

        return implode("\n", $lines);
    }

    private function formatProject(Project $project): string
    {
        $project->loadMissing(['organization', 'position.organization']);
        $context = $project->position
            ? "{$project->position->title} at " . ($project->position->organization?->name ?? 'Unknown')
            : ($project->organization?->name ?? '');

        $lines = ["- **Project:** {$project->name}"];

        if ($context) {
            $lines[] = "  Context: {$context}";
        }
        if ($project->description) {
            $lines[] = "  Description: {$project->description}";
        }
        if ($project->outcome) {
            $lines[] = "  Outcome: {$project->outcome}";
        }

        $meta = array_filter([
            $project->contribution_level ? "contribution: {$project->contribution_level}" : null,
            $project->team_size ? "team: {$project->team_size} people" : null,
        ]);
        if (! empty($meta)) {
            $lines[] = '  ' . implode(' · ', $meta);
        }

        return implode("\n", $lines);
    }

    private function formatAccomplishment(Accomplishment $accomplishment): string
    {
        $accomplishment->loadMissing(['position.organization', 'project']);
        $context = $accomplishment->position
            ? "{$accomplishment->position->title} at " . ($accomplishment->position->organization?->name ?? 'Unknown')
            : ($accomplishment->project ? $accomplishment->project->name : '');

        $lines = ["- **Accomplishment:** {$accomplishment->title}"];

        if ($context) {
            $lines[] = "  Context: {$context}";
        }
        if ($accomplishment->description) {
            $lines[] = "  {$accomplishment->description}";
        }

        $impact = array_filter([
            $accomplishment->impact_metric,
            $accomplishment->impact_value,
            $accomplishment->impact_unit,
        ]);
        if (! empty($impact)) {
            $lines[] = '  Impact: ' . implode(' — ', $impact);
        }

        return implode("\n", $lines);
    }

    private function formatCareerTheme(CareerTheme $theme): string
    {
        $lines = ["- **Career Theme:** \"{$theme->name}\""];
        if ($theme->description) {
            $lines[] = "  {$theme->description}";
        }

        return implode("\n", $lines);
    }

    private function formatTag(Tag $tag): string
    {
        $category = $tag->category ? " ({$tag->category})" : '';

        return "- **Skill/Tag:** {$tag->name}{$category}";
    }

    private function formatLink(Link $link): string
    {
        $type = str_replace('_', ' ', $link->type ?? 'link');
        $lines = ["- **Portfolio ({$type}):** {$link->title}"];

        if ($link->url) {
            $lines[] = "  URL: {$link->url}";
        }
        if ($link->description) {
            $lines[] = "  {$link->description}";
        }

        return implode("\n", $lines);
    }

    private function formatDateRange($start, $end): string
    {
        if (! $start) {
            return '';
        }

        $startStr = $start->format('M Y');
        $endStr = $end ? $end->format('M Y') : 'present';

        return "{$startStr} to {$endStr}";
    }
}