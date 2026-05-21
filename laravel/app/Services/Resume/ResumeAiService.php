<?php

namespace App\Services\Resume;

use App\Enums\RequirementCategory;
use App\Services\Extraction\SynthesisResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Handles AI calls for the resume generation pipeline. Separate from
 * ExtractionProvider because it's a different task (relevance matching
 * vs. data extraction) with its own system prompt and response format.
 *
 * Shares the same API key and model config via the `services.extraction`
 * config block — same provider, same billing, different purpose.
 *
 * Supports two operations:
 *   - analyzeRelevance: extract requirements from a listing, produce
 *     a strategy summary, and map catalog entries to requirements
 *   - generateDraft: produce a markdown resume from accepted selections
 *
 * Future operations (5.4):
 *   - formatDocument: convert approved draft to .docx/.pdf
 */
class ResumeAiService
{
    private const API_BASE = 'https://api.anthropic.com';
    private const API_VERSION = '2023-06-01';
    private const MAX_TOKENS = 8000;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $inputCostPerMtokCents,
        private readonly int $outputCostPerMtokCents,
    ) {}

    /**
     * Full resume analysis: extract requirements, produce strategy,
     * and map catalog entries to requirements. Returns a structured
     * RelevanceResult the controller uses to populate requirements,
     * draft strategy, and selections.
     */
    public function analyzeRelevance(
        string $catalogSummary,
        string $jobListingBody,
        string $roleTitle,
    ): RelevanceResult {
        $userMessage = $this->buildRelevanceMessage(
            $catalogSummary,
            $jobListingBody,
            $roleTitle,
        );

        $messages = [['role' => 'user', 'content' => $userMessage]];

        try {
            $response = $this->client()->post('/v1/messages', [
                'model' => $this->model,
                'max_tokens' => self::MAX_TOKENS,
                'system' => $this->relevanceSystemPrompt(),
                'messages' => $messages,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Resume AI request failed: {$e->getMessage()}", 0, $e
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                "Resume AI returned {$response->status()}: " . $response->body()
            );
        }

        $body = $response->json();
        $text = $this->extractTextFromResponse($body);
        $parsed = $this->parseStructuredResponse($text);

        $inputTokens = (int) ($body['usage']['input_tokens'] ?? 0);
        $outputTokens = (int) ($body['usage']['output_tokens'] ?? 0);

        return new RelevanceResult(
            requirements: $parsed['requirements'],
            strategySummary: $parsed['strategy_summary'],
            selections: $parsed['selections'],
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            costCents: $this->computeCost($inputTokens, $outputTokens),
            model: $this->model,
        );
    }

    /**
     * Generate a markdown resume draft from the user's confirmed
     * selections, strategy, and requirement context. The prompt
     * text is built externally by DraftPromptBuilder; this method
     * handles the API call and response extraction.
     *
     * Returns raw markdown — no JSON parsing needed, since the
     * system prompt instructs the AI to produce prose directly.
     */
    public function generateDraft(string $promptContext): DraftResult
    {
        $messages = [['role' => 'user', 'content' => $promptContext]];

        try {
            $response = $this->client()->post('/v1/messages', [
                'model' => $this->model,
                'max_tokens' => self::MAX_TOKENS,
                'system' => $this->draftSystemPrompt(),
                'messages' => $messages,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Resume draft generation failed: {$e->getMessage()}", 0, $e
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                "Resume draft AI returned {$response->status()}: " . $response->body()
            );
        }

        $body = $response->json();
        $markdown = $this->extractTextFromResponse($body);

        $inputTokens = (int) ($body['usage']['input_tokens'] ?? 0);
        $outputTokens = (int) ($body['usage']['output_tokens'] ?? 0);

        return new DraftResult(
            markdown: $markdown,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            costCents: $this->computeCost($inputTokens, $outputTokens),
            model: $this->model,
        );
    }

    /**
     * Synthesize the user's relevance notes from the review process
     * into an updated strategy summary. Takes the current strategy
     * and all user notes, and produces a refined strategy that
     * incorporates the context the user added during review.
     *
     * Returns a SynthesisResult (reusing the extraction value object
     * since it has the right shape: text + tokens + cost).
     */
    public function synthesizeNotesIntoStrategy(
        string $currentStrategy,
        string $notesContext,
        string $roleTitle,
    ): SynthesisResult {
        $userMessage = <<<MESSAGE
        ## Current Strategy

        {$currentStrategy}

        ---

        ## User Notes from Review

        These are the candidate's own notes explaining how their experience connects to each requirement for the "{$roleTitle}" role:

        {$notesContext}
        MESSAGE;

        $system = <<<'PROMPT'
You are a career strategist refining a resume strategy. The candidate has reviewed their experience against a job listing's requirements and written notes explaining how each piece of evidence connects. Your job is to produce an updated strategy summary that incorporates the candidate's framing.

Rules:
- Produce 3-6 sentences describing the recommended narrative angle for the resume.
- Incorporate specific connections, framing, and emphasis from the user's notes — they've done the analytical work of linking their experience to requirements.
- Preserve anything from the current strategy that still holds, but update or extend it with what the notes reveal.
- Be specific — reference actual experiences and actual requirements, not generic strengths.
- Return only the updated strategy text. No preamble, no explanation, no quotes.
PROMPT;

        $messages = [['role' => 'user', 'content' => $userMessage]];

        try {
            $response = $this->client()->post('/v1/messages', [
                'model' => $this->model,
                'max_tokens' => 1500,
                'system' => $system,
                'messages' => $messages,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Strategy synthesis failed: {$e->getMessage()}", 0, $e
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                "Strategy synthesis returned {$response->status()}: " . $response->body()
            );
        }

        $body = $response->json();
        $text = $this->extractTextFromResponse($body);
        $inputTokens = (int) ($body['usage']['input_tokens'] ?? 0);
        $outputTokens = (int) ($body['usage']['output_tokens'] ?? 0);

        return new SynthesisResult(
            description: trim($text),
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            costCents: $this->computeCost($inputTokens, $outputTokens),
            model: $this->model,
        );
    }

    private function draftSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a professional resume writer. You will receive a job listing, a resume strategy, and a set of requirements with the candidate's curated evidence for each. Your job is to produce a polished, tailored resume in markdown.

## Priority of inputs

The candidate has manually reviewed every piece of evidence and written their own notes explaining how their experience connects to each requirement. These inputs are your primary source of truth, in this order:

1. **Strategy summary** — the overall narrative angle. This is the structural spine of the resume. Every section should serve this story.
2. **User notes** (labeled "User note:" in the evidence) — the candidate's own framing of how each piece of evidence relates to the requirement. These override AI reasoning. When a user note says to emphasize, reframe, or connect something in a specific way, do exactly that.
3. **Evidence details** (accomplishment descriptions, project outcomes, impact metrics) — the raw material for resume bullets.
4. **AI reasoning** (labeled "Relevance:" in the evidence) — useful for context but subordinate to user notes. When a user note and AI reasoning disagree on framing, the user note wins.

## Output structure

Produce the resume as clean markdown with the following sections in order:

1. **Header** — candidate name as an H1 (use "{{NAME}}" as a placeholder — the user will fill this in). No contact info — the user adds that during formatting.

2. **Professional Summary** — 3-5 sentences, tightly aligned with the strategy summary provided. This is the narrative spine of the resume. Don't repeat the strategy verbatim — translate it into first-person professional prose that a hiring manager reads in 10 seconds.

3. **Experience** — the core section. Group by position (company + title + dates), with bullet points for accomplishments and project highlights under each. Rules:
   - Only include positions, projects, and accomplishments that appear in the provided evidence. Do not invent or embellish.
   - Prioritize accomplishments with concrete impact metrics — lead with the number.
   - Each bullet should be 1-2 sentences. Concise, active voice, past tense for completed work, present tense for current roles.
   - Tailor the framing to the target role using the user notes as your guide. The user has already done the work of connecting their experience to the requirements — translate their framing into polished resume language.
   - When multiple requirements map to the same position, weave them together naturally rather than repeating the position.
   - Omit positions that have no selected evidence beneath them.

4. **Skills** — a concise list of relevant skills/technologies drawn from the Tag evidence and from skills mentioned in the experience bullets. Group by category if there are enough (e.g., Languages, Frameworks, Tools, Platforms). Don't list every skill the candidate has — only those relevant to this specific role.

5. **Additional** (optional) — career themes, portfolio links, or other evidence that doesn't fit neatly into Experience or Skills. Only include this section if there's meaningful content for it. Label it appropriately (e.g., "Publications", "Open Source", "Speaking", or just "Additional").

## Formatting rules

- Use standard markdown: `#` for the name, `##` for section headers, `###` for position headers, `-` for bullets.
- Position headers should follow the pattern: `### Title, Organization` with dates on the next line in italics: `*Month Year – Month Year*` (or `*Month Year – Present*`).
- No bold within bullets unless genuinely needed for emphasis. Clean prose beats heavy formatting.
- No markdown links in the experience section — URLs go in the Additional section if included at all.
- Aim for 1-2 pages of content when rendered. Be selective rather than comprehensive — a tight resume beats a thorough one.

## What NOT to do

- Do not fabricate accomplishments, metrics, or experiences not present in the evidence.
- Do not include generic filler bullets ("Collaborated with cross-functional teams" with no specifics).
- Do not add a cover letter, objective statement, or references section.
- Do not include explanatory comments or meta-text — output only the resume markdown.
- Do not wrap the output in code fences.
- Do not ignore user notes. If a user note provides specific framing, numbers, or context not in the raw evidence, incorporate it — the user is adding information they know to be true about their own experience.
PROMPT;
    }

    private function buildRelevanceMessage(
        string $catalogSummary,
        string $jobListingBody,
        string $roleTitle,
    ): string {
        return <<<MESSAGE
        ## Job Listing

        **Role:** {$roleTitle}

        {$jobListingBody}

        ---

        ## Candidate's Career Catalog

        {$catalogSummary}
        MESSAGE;
    }

    private function relevanceSystemPrompt(): string
    {
        $categories = RequirementCategory::promptEnumString();

        return <<<PROMPT
You are a career strategist helping a job applicant build a tailored resume. You will receive a job listing and the applicant's career catalog.

You must do three things:

## 1. Extract requirements from the listing

Parse the job listing into individual requirements. Each requirement is a specific thing the employer is looking for — a skill, a technology, years of experience, a responsibility, a domain expertise, etc.

For each requirement, provide:
- "ref": a short unique label you assign (e.g. "REQ-1", "REQ-2"). Used to link selections below.
- "category": one of {$categories}
- "title": short label (e.g. "Fraud detection systems", "5+ years Python", "Team leadership")
- "description": the relevant sentence or context from the listing where this appeared. Keep it brief.
- "section": which part of the listing this came from:
  - "required" — hard requirements, must-haves
  - "preferred" — nice-to-haves, bonus qualifications
  - "responsibility" — day-to-day duties, what you'll be doing
- "order": display order within the section (1 = first)

Deduplicate where requirements overlap across sections. If a skill appears as both a requirement and a responsibility, keep the more specific one and note both contexts in the description.

## 2. Produce a strategy summary

Write 2-4 sentences describing the recommended narrative angle for this application. What makes this candidate a strong match? Which experiences should be the centerpiece? What story should the resume tell? Be specific — reference actual entries from the catalog and actual requirements from the listing.

## 3. Map catalog entries to requirements

For each requirement you extracted, identify which catalog entries best demonstrate that the candidate meets it. Focus on "this is evidence of X requirement" rather than generic relevance.

For each selection, provide:
- "type": one of "Position", "Project", "Accomplishment", "CareerTheme", "Tag", "Link"
- "id": the numeric ID from the catalog (the number after the colon in brackets like [Position:42])
- "requirement_ref": the "ref" of the requirement this addresses (e.g. "REQ-1"). Use null for entries that strengthen the resume generally but don't map to a specific requirement.
- "reason": 1-2 sentences explaining specifically HOW this entry demonstrates the requirement. Don't just say "relevant experience" — describe the connection. For example: "Led a team of 6 building a real-time fraud scoring engine, directly matching the listing's requirement for fraud detection system experience."
- "order": display order within the requirement group (1 = most relevant)

Selection guidelines:
- A catalog entry CAN appear under multiple requirements if it genuinely demonstrates both.
- Prioritize accomplishments with concrete impact metrics — these make the strongest resume bullets.
- Include portfolio links (is_personal_appearance = true) where they provide tangible evidence of a requirement.
- Be selective within each requirement. The 2-3 strongest pieces of evidence beat 10 weak ones.
- Include career themes that align with the role's overall direction.
- It's okay if some requirements have no matching catalog entries — that gap is useful information for the applicant. Don't force weak matches.
- When in doubt, include the entry — the user can exclude it. It's easier to remove than to discover something was missing.

## Response format

Return a single JSON object with three keys. No preamble, no commentary, no code fences.

{
  "requirements": [...],
  "strategy_summary": "...",
  "selections": [...]
}
PROMPT;
    }

    /**
     * Parse the AI's structured JSON response into requirements,
     * strategy summary, and selections. Tolerant of code fences.
     */
    private function parseStructuredResponse(string $text): array
    {
        $cleaned = trim($text);

        // Strip code fences if present.
        if (str_starts_with($cleaned, '```')) {
            $cleaned = preg_replace('/^```(?:json)?\s*/', '', $cleaned);
            $cleaned = preg_replace('/```\s*$/', '', $cleaned);
            $cleaned = trim($cleaned);
        }

        $parsed = json_decode($cleaned, true);

        if (! is_array($parsed)) {
            throw new RuntimeException(
                'Could not parse JSON from resume AI response. Raw: ' .
                substr($text, 0, 500)
            );
        }

        return [
            'requirements' => $this->validateRequirements($parsed['requirements'] ?? []),
            'strategy_summary' => (string) ($parsed['strategy_summary'] ?? ''),
            'selections' => $this->validateSelections($parsed['selections'] ?? []),
        ];
    }

    private function validateRequirements(array $raw): Collection
    {
        $validSections = ['required', 'preferred', 'responsibility'];
        $validCategories = array_map(
            fn (RequirementCategory $c) => $c->value,
            RequirementCategory::cases(),
        );

        return collect($raw)
            ->filter(fn (array $item) => isset($item['ref'], $item['title'])
                && isset($item['category'], $item['section'])
            )
            ->map(fn (array $item) => [
                'ref' => (string) $item['ref'],
                'category' => in_array($item['category'], $validCategories, true)
                    ? (string) $item['category']
                    : 'other',
                'title' => (string) $item['title'],
                'description' => (string) ($item['description'] ?? ''),
                'section' => in_array($item['section'], $validSections, true)
                    ? (string) $item['section']
                    : 'required',
                'order' => (int) ($item['order'] ?? 0),
            ])
            ->values();
    }

    private function validateSelections(array $raw): Collection
    {
        $validTypes = ['Position', 'Project', 'Accomplishment', 'CareerTheme', 'Tag', 'Link'];

        return collect($raw)
            ->filter(fn (array $item) => isset($item['type'], $item['id'])
                && in_array($item['type'], $validTypes, true)
            )
            ->map(fn (array $item) => [
                'type' => (string) $item['type'],
                'id' => (int) $item['id'],
                'requirement_ref' => isset($item['requirement_ref'])
                    ? (string) $item['requirement_ref']
                    : null,
                'reason' => (string) ($item['reason'] ?? ''),
                'order' => (int) ($item['order'] ?? 0),
            ])
            ->values();
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::API_BASE)
            ->withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type' => 'application/json',
            ])
            ->timeout(120)
            ->acceptJson();
    }

    private function extractTextFromResponse(array $body): string
    {
        $blocks = $body['content'] ?? [];
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'text') {
                return $block['text'] ?? '';
            }
        }
        throw new RuntimeException('No text block found in resume AI response');
    }

    private function computeCost(int $inputTokens, int $outputTokens): int
    {
        $inputCost = ($inputTokens * $this->inputCostPerMtokCents) / 1_000_000;
        $outputCost = ($outputTokens * $this->outputCostPerMtokCents) / 1_000_000;
        return (int) round($inputCost + $outputCost);
    }
}