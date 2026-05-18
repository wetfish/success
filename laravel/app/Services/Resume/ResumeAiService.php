<?php

namespace App\Services\Resume;

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
 * Currently supports one operation:
 *   - analyzeRelevance: compare a catalog summary against a job listing
 *     and identify which entries are most relevant
 *
 * Future operations (5.3, 5.4):
 *   - generateDraft: produce a markdown resume from accepted selections
 *   - formatDocument: convert approved draft to .docx/.pdf
 */
class ResumeAiService
{
    private const API_BASE = 'https://api.anthropic.com';
    private const API_VERSION = '2023-06-01';
    private const MAX_TOKENS = 4000;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $inputCostPerMtokCents,
        private readonly int $outputCostPerMtokCents,
    ) {}

    /**
     * Analyze which catalog entries are most relevant to a job listing.
     * Returns structured suggestions the controller uses to populate
     * resume_selections.
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
        $suggestions = $this->parseSuggestions($text);

        $inputTokens = (int) ($body['usage']['input_tokens'] ?? 0);
        $outputTokens = (int) ($body['usage']['output_tokens'] ?? 0);

        return new RelevanceResult(
            suggestions: $suggestions,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            costCents: $this->computeCost($inputTokens, $outputTokens),
            model: $this->model,
        );
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
        return <<<'PROMPT'
You are a career strategist helping a job applicant decide which parts of their work history to feature on a tailored resume. You will receive a job listing and the applicant's career catalog.

Your task: identify which catalog entries are most relevant to the job listing. For each entry you suggest, explain WHY it's relevant — connect the entry's specifics to the listing's requirements, responsibilities, or nice-to-haves.

Return a JSON array of objects. Each object has:
- "type": one of "Position", "Project", "Accomplishment", "CareerTheme", "Tag", "Link"
- "id": the numeric ID from the catalog (the number after the colon in brackets like [Position:42])
- "reason": 1-2 sentences explaining why this entry strengthens the application. Be specific — reference the listing's requirements by name.
- "order": integer for suggested display order within the type group (1 = most prominent)

Selection guidelines:
- Include ALL positions that are relevant to the role — these form the resume's backbone.
- Under each relevant position, include the projects and accomplishments that best demonstrate fit for this specific role. Skip generic or weakly relevant items.
- Prioritize accomplishments with high prominence scores and concrete impact metrics — these are the strongest resume bullets.
- Include career themes that align with the listing's description of the role or team culture.
- Include tags (skills) that directly match the listing's required or preferred qualifications.
- Include links with is_personal_appearance = true that demonstrate relevant expertise (talks, articles, open source contributions).
- Be selective. A focused resume with 3-5 strong positions and their best evidence beats a kitchen-sink approach. If an entry doesn't clearly strengthen the application, leave it out.
- When in doubt, include the entry — the user can toggle it off. It's easier to remove than to realize something was missing.

Return only the JSON array. No preamble, no commentary, no code fences.
PROMPT;
    }

    /**
     * Parse the AI's JSON response into a collection of suggestion
     * arrays. Tolerant of code fences.
     */
    private function parseSuggestions(string $text): Collection
    {
        $cleaned = trim($text);

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

        $validTypes = ['Position', 'Project', 'Accomplishment', 'CareerTheme', 'Tag', 'Link'];

        return collect($parsed)
            ->filter(fn (array $item) => isset($item['type'], $item['id'])
                && in_array($item['type'], $validTypes, true)
            )
            ->map(fn (array $item) => [
                'type' => (string) $item['type'],
                'id' => (int) $item['id'],
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