<?php

namespace App\Services\Resume;

use App\Models\Accomplishment;
use App\Models\CareerTheme;
use App\Models\Link;
use App\Models\Position;
use App\Models\Project;
use App\Models\Tag;

/**
 * Serializes the user's catalog into a structured text format
 * suitable for AI prompts. Pure read service — no side effects,
 * no AI calls.
 *
 * The output is token-efficient structured text rather than JSON.
 * Each entity includes its database ID so the AI can reference
 * specific records in its response, and the controller can map
 * those references back to Eloquent models for resume_selections.
 *
 * The format groups data by position (the natural resume structure):
 * positions contain projects, projects contain accomplishments.
 * Career themes, tags, and portfolio links are separate sections.
 */
class CatalogSummarizer
{
    /**
     * Build the full catalog summary as structured text.
     */
    public function summarize(): string
    {
        $sections = array_filter([
            $this->summarizeCareerThemes(),
            $this->summarizeWorkHistory(),
            $this->summarizeTags(),
            $this->summarizePortfolioLinks(),
        ]);

        if (empty($sections)) {
            return "The user's catalog is empty. No work history, themes, skills, or portfolio items have been recorded yet.";
        }

        return implode("\n\n", $sections);
    }

    private function summarizeCareerThemes(): string
    {
        $themes = CareerTheme::orderBy('display_order')->get();

        if ($themes->isEmpty()) {
            return '';
        }

        $lines = ["## Career Themes"];

        foreach ($themes as $theme) {
            $lines[] = "- [CareerTheme:{$theme->id}] \"{$theme->name}\"";
            if ($theme->description) {
                $lines[] = "  Description: {$theme->description}";
            }
        }

        return implode("\n", $lines);
    }

    private function summarizeWorkHistory(): string
    {
        $positions = Position::with([
            'organization',
            'projects' => fn ($q) => $q->whereNull('parent_project_id'),
            'projects.childProjects',
            'projects.tags',
            'projects.accomplishments',
            'projects.childProjects.tags',
            'projects.childProjects.accomplishments',
            'accomplishments',
            'tags',
        ])
            ->orderByRaw('end_date IS NULL DESC')
            ->orderBy('start_date', 'desc')
            ->get();

        if ($positions->isEmpty()) {
            return '';
        }

        $lines = ["## Work History"];

        foreach ($positions as $position) {
            $lines[] = $this->formatPosition($position);
        }

        return implode("\n", $lines);
    }

    private function formatPosition(Position $position): string
    {
        $orgName = $position->organization?->name ?? 'Unknown';
        $dates = $this->formatDateRange(
            $position->start_date,
            $position->end_date,
        );

        $lines = [];
        $lines[] = "### [Position:{$position->id}] {$position->title} at {$orgName} ({$dates})";

        $meta = [];
        if ($position->employment_type) {
            $meta[] = str_replace('_', ' ', $position->employment_type);
        }
        if ($position->location_arrangement) {
            $meta[] = str_replace('_', ' ', $position->location_arrangement);
        }
        if ($position->team_name) {
            $meta[] = "Team: {$position->team_name}";
        }
        if ($position->team_size_immediate) {
            $meta[] = "{$position->team_size_immediate} people";
        }
        if (! empty($meta)) {
            $lines[] = "  " . implode(' | ', $meta);
        }

        if ($position->mandate) {
            $lines[] = "  Mandate: {$position->mandate}";
        }

        if ($position->tags->isNotEmpty()) {
            $tagNames = $position->tags->pluck('name')->implode(', ');
            $lines[] = "  Skills: {$tagNames}";
        }

        // Direct accomplishments (not under a project).
        $directAccomplishments = $position->accomplishments()
            ->whereNull('project_id')
            ->get();

        foreach ($directAccomplishments as $accomplishment) {
            $lines[] = $this->formatAccomplishment($accomplishment, '  ');
        }

        // Projects under this position.
        foreach ($position->projects as $project) {
            $lines[] = $this->formatProject($project, '  ');
        }

        return implode("\n", $lines);
    }

    private function formatProject(Project $project, string $indent = ''): string
    {
        $dates = $this->formatDateRange(
            $project->start_date,
            $project->end_date,
            $project->date_precision,
        );

        $lines = [];
        $dateSuffix = $dates ? " ({$dates})" : '';
        $lines[] = "{$indent}#### [Project:{$project->id}] {$project->name}{$dateSuffix}";

        if ($project->description) {
            $lines[] = "{$indent}  Description: {$project->description}";
        }
        if ($project->problem) {
            $lines[] = "{$indent}  Problem: {$project->problem}";
        }
        if ($project->approach) {
            $lines[] = "{$indent}  Approach: {$project->approach}";
        }
        if ($project->outcome) {
            $lines[] = "{$indent}  Outcome: {$project->outcome}";
        }

        $meta = [];
        if ($project->contribution_level) {
            $meta[] = "contribution: {$project->contribution_level}";
        }
        if ($project->visibility) {
            $meta[] = "visibility: {$project->visibility}";
        }
        if ($project->team_size) {
            $meta[] = "team: {$project->team_size} people";
        }
        if (! empty($meta)) {
            $lines[] = "{$indent}  " . implode(' | ', $meta);
        }

        if ($project->tags->isNotEmpty()) {
            $tagNames = $project->tags->pluck('name')->implode(', ');
            $lines[] = "{$indent}  Tags: {$tagNames}";
        }

        // Accomplishments under this project.
        foreach ($project->accomplishments as $accomplishment) {
            $lines[] = $this->formatAccomplishment($accomplishment, $indent . '  ');
        }

        // Sub-projects.
        foreach ($project->childProjects as $child) {
            $lines[] = $this->formatProject($child, $indent . '  ');
        }

        return implode("\n", $lines);
    }

    private function formatAccomplishment(Accomplishment $accomplishment, string $indent = ''): string
    {
        $lines = [];
        $lines[] = "{$indent}- [Accomplishment:{$accomplishment->id}] {$accomplishment->title}";

        if ($accomplishment->description) {
            $lines[] = "{$indent}  {$accomplishment->description}";
        }

        // Impact metrics.
        $impact = array_filter([
            $accomplishment->impact_metric,
            $accomplishment->impact_value,
            $accomplishment->impact_unit,
        ]);
        if (! empty($impact)) {
            $lines[] = "{$indent}  Impact: " . implode(' — ', $impact);
        }

        $meta = [];
        if ($accomplishment->confidence) {
            $meta[] = "confidence: {$accomplishment->confidence}/5";
        }
        if ($accomplishment->prominence) {
            $meta[] = "prominence: {$accomplishment->prominence}/5";
        }
        if (! empty($meta)) {
            $lines[] = "{$indent}  " . implode(' | ', $meta);
        }

        return implode("\n", $lines);
    }

    private function summarizeTags(): string
    {
        // Tags with usage counts across all taggable entities.
        $tags = Tag::withCount([
            'projects',
            'accomplishments',
            'positions',
        ])->orderBy('name')->get();

        if ($tags->isEmpty()) {
            return '';
        }

        $lines = ["## Skills & Tags"];

        foreach ($tags as $tag) {
            $totalUsage = $tag->projects_count
                + $tag->accomplishments_count
                + $tag->positions_count;

            if ($totalUsage === 0) {
                continue;
            }

            $category = $tag->category ? " ({$tag->category})" : '';
            $lines[] = "- [Tag:{$tag->id}] {$tag->name}{$category} — used {$totalUsage}×";
        }

        // If all tags had zero usage, return empty.
        if (count($lines) === 1) {
            return '';
        }

        return implode("\n", $lines);
    }

    private function summarizePortfolioLinks(): string
    {
        $links = Link::where('is_personal_appearance', true)
            ->with('linkable')
            ->get();

        if ($links->isEmpty()) {
            return '';
        }

        $lines = ["## Portfolio & Personal Appearances"];

        foreach ($links as $link) {
            $parentName = $this->getLinkParentName($link);
            $type = str_replace('_', ' ', $link->type);
            $lines[] = "- [Link:{$link->id}] {$type}: \"{$link->title}\"";
            if ($link->url) {
                $lines[] = "  URL: {$link->url}";
            }
            if ($link->description) {
                $lines[] = "  {$link->description}";
            }
            if ($parentName) {
                $lines[] = "  Context: {$parentName}";
            }
        }

        return implode("\n", $lines);
    }

    private function getLinkParentName(Link $link): ?string
    {
        $parent = $link->linkable;

        if (! $parent) {
            return null;
        }

        return match (true) {
            $parent instanceof \App\Models\Organization => $parent->name,
            $parent instanceof \App\Models\Position => "{$parent->title} at " . ($parent->organization?->name ?? 'Unknown'),
            $parent instanceof \App\Models\Project => $parent->name,
            $parent instanceof \App\Models\Accomplishment => $parent->title,
            default => null,
        };
    }

    private function formatDateRange(
        $start,
        $end,
        ?string $precision = null,
    ): string {
        if (! $start) {
            return '';
        }

        $format = match ($precision) {
            'year' => 'Y',
            'quarter' => function ($date) {
                $q = (int) ceil($date->month / 3);
                return "Q{$q} {$date->year}";
            },
            'month' => 'M Y',
            default => 'M Y',
        };

        if (is_callable($format)) {
            $startStr = $format($start);
            $endStr = $end ? $format($end) : 'present';
        } else {
            $startStr = $start->format($format);
            $endStr = $end ? $end->format($format) : 'present';
        }

        return "{$startStr} to {$endStr}";
    }
}