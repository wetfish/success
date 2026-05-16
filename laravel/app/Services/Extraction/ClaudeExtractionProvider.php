<?php

namespace App\Services\Extraction;

use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Models\Link;
use App\Models\SourceDocument;
use App\Models\Tag;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Claude implementation of ExtractionProvider. Talks to Anthropic's
 * /v1/messages endpoint directly via Laravel's Http facade — no
 * Anthropic SDK dependency.
 *
 * The system prompt is private to this class. Other providers will
 * have their own prompts shaped to their model's strengths.
 */
class ClaudeExtractionProvider implements ExtractionProvider
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

    public function name(): string
    {
        return 'claude';
    }

    public function isAvailable(): bool
    {
        if ($this->apiKey === '') {
            return false;
        }

        try {
            $response = $this->client()->post('/v1/messages/count_tokens', [
                'model' => $this->model,
                'messages' => [['role' => 'user', 'content' => 'ping']],
            ]);
            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }

    public function extract(SourceDocument $document): ExtractionResult
    {
        $messages = [['role' => 'user', 'content' => $this->buildContent($document)]];

        try {
            $response = $this->client()->post('/v1/messages', [
                'model' => $this->model,
                'max_tokens' => self::MAX_TOKENS,
                'system' => $this->systemPrompt(),
                'messages' => $messages,
            ]);
        } catch (Throwable $e) {
            throw new ExtractionException(
                "Claude API request failed: {$e->getMessage()}", 0, $e
            );
        }

        if (! $response->successful()) {
            throw new ExtractionException(
                "Claude API returned {$response->status()}: " . $response->body()
            );
        }

        $body = $response->json();
        $text = $this->extractTextFromResponse($body);
        $drafts = $this->parseDrafts($text);

        $inputTokens = (int) ($body['usage']['input_tokens'] ?? 0);
        $outputTokens = (int) ($body['usage']['output_tokens'] ?? 0);

        return new ExtractionResult(
            drafts: $drafts,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            costCents: $this->computeCost($inputTokens, $outputTokens),
            model: $this->model,
        );
    }

    public function synthesize(string $existing, string $new): SynthesisResult
    {
        $messages = [['role' => 'user', 'content' =>
            "Existing description:\n{$existing}\n\n" .
            "New description:\n{$new}\n\n" .
            "Combine these into a single unified description that captures the substantive content of both. " .
            "Return only the combined description, no preamble.",
        ]];

        try {
            $response = $this->client()->post('/v1/messages', [
                'model' => $this->model,
                'max_tokens' => 1500,
                'system' => 'You are an editor combining two descriptions of the same item into one unified version. Preserve all meaningful content. Do not invent new details.',
                'messages' => $messages,
            ]);
        } catch (Throwable $e) {
            throw new ExtractionException(
                "Claude API request failed: {$e->getMessage()}", 0, $e
            );
        }

        if (! $response->successful()) {
            throw new ExtractionException(
                "Claude API returned {$response->status()}: " . $response->body()
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

    public function summarizeTitle(string $body): SummaryResult
    {
        $messages = [['role' => 'user', 'content' =>
            "Generate a short title for these career notes. The title " .
            "will help the user identify the document later in a list.\n\n" .
            "Notes:\n{$body}\n\n" .
            "Return ONLY the title text — no quotes, no preamble, no " .
            "trailing punctuation. The title should be 3-7 words.",
        ]];

        try {
            $response = $this->client()->post('/v1/messages', [
                'model' => $this->model,
                'max_tokens' => 50,
                'system' => 'You write short, descriptive titles for career notes. Titles are 3-7 words, no quotes, no trailing punctuation. Capture the most distinctive subject matter. Examples: "Stripe interview prep", "Q3 performance review notes", "Onboarding mentor brag doc".',
                'messages' => $messages,
            ]);
        } catch (Throwable $e) {
            throw new ExtractionException(
                "Claude API request failed: {$e->getMessage()}", 0, $e
            );
        }

        if (! $response->successful()) {
            throw new ExtractionException(
                "Claude API returned {$response->status()}: " . $response->body()
            );
        }

        $responseBody = $response->json();
        $text = $this->extractTextFromResponse($responseBody);
        $inputTokens = (int) ($responseBody['usage']['input_tokens'] ?? 0);
        $outputTokens = (int) ($responseBody['usage']['output_tokens'] ?? 0);

        // Strip surrounding quotes the model sometimes adds despite
        // instructions, plus any trailing period.
        $title = trim($text);
        $title = trim($title, "\"' \t\n.");

        return new SummaryResult(
            title: $title,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            costCents: $this->computeCost($inputTokens, $outputTokens),
            model: $this->model,
        );
    }

    public function estimateTokens(SourceDocument $document): int
    {
        try {
            $response = $this->client()->post('/v1/messages/count_tokens', [
                'model' => $this->model,
                'system' => $this->systemPrompt(),
                'messages' => [['role' => 'user', 'content' => $this->buildContent($document)]],
            ]);
        } catch (Throwable $e) {
            throw new ExtractionException(
                "Token count request failed: {$e->getMessage()}", 0, $e
            );
        }

        if (! $response->successful()) {
            throw new ExtractionException(
                "Claude API returned {$response->status()}: " . $response->body()
            );
        }

        return (int) ($response->json('input_tokens') ?? 0);
    }

    /**
     * Build the user message content. For text-shaped documents, just
     * the body. For PDFs, a multipart message with the file as a
     * document block plus a brief instruction.
     */
    private function buildContent(SourceDocument $document): array|string
    {
        if ($document->isPdf() && $document->file_path) {
            $pdfData = base64_encode(Storage::disk('local')->get($document->file_path));

            return [
                [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => 'application/pdf',
                        'data' => $pdfData,
                    ],
                ],
                [
                    'type' => 'text',
                    'text' => 'Extract structured career records from this document.',
                ],
            ];
        }

        return $document->body ?? '';
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
        throw new ExtractionException('No text block found in Claude response');
    }

    /**
     * Parse the JSON array Claude returns into DraftRecord instances.
     * Tolerant of fenced code blocks (```json ... ```) since Claude
     * sometimes wraps JSON output despite instructions not to.
     */
    private function parseDrafts(string $text): Collection
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
            throw new ExtractionException(
                "Could not parse JSON from Claude response. Raw: " .
                substr($text, 0, 500)
            );
        }

        return collect($parsed)->map(function ($item) {
            if (! isset($item['type'], $item['data'])) {
                throw new ExtractionException(
                    'Draft record missing type or data field'
                );
            }
            return new DraftRecord(
                type: (string) $item['type'],
                data: (array) $item['data'],
            );
        });
    }

    private function computeCost(int $inputTokens, int $outputTokens): int
    {
        // costPerMtok is in cents. tokens / 1_000_000 * costPerMtok = cost in cents.
        $inputCost = ($inputTokens * $this->inputCostPerMtokCents) / 1_000_000;
        $outputCost = ($outputTokens * $this->outputCostPerMtokCents) / 1_000_000;
        return (int) round($inputCost + $outputCost);
    }

    /**
     * The system prompt. This is genuinely Claude-specific — other
     * models would need different instructions to produce reliable
     * structured output.
     *
     * Placeholders in the prompt body ({{organization_types}},
     * {{organization_statuses}}) are substituted from the canonical
     * enums at runtime. This keeps the prompt's accepted values in
     * sync with the rest of the application — adding a case to the
     * enum propagates to the AI's allowed values without anyone
     * touching this file.
     */
    private function systemPrompt(): string
    {
        $template = <<<'PROMPT'
You are extracting structured career records from a document. The document is the user's notes, performance review, brag doc, resume, or similar source material about their professional history.

Return a JSON array of records. Each record has a "type" and a "data" object. Possible types:

- "organization" — a company, project sponsor, or institution where the user worked
- "position" — a specific role at an organization
- "project" — a discrete body of work
- "accomplishment" — a single achievement with measurable or describable impact

For each type, the "data" object uses these fields. Omit fields you cannot determine from the document. Do not invent values.

organization data: name (required), type ({{organization_types}} — use "prospect" only for companies the user is researching or applying to, not employment history), website, tagline, description, headquarters, founded_year, size_estimate, status ({{organization_statuses}} or omit), tags (array, see below), links (array, see below)

position data: organization_name (required, references an organization in the same response or an existing one), title (required), employment_type ("full_time" | "part_time" | "contract" | "freelance" | "internship" | "advisor" | "volunteer" | "founder"), location_arrangement ("remote" | "hybrid" | "on_site"), location_text, start_date (YYYY-MM-DD), end_date (YYYY-MM-DD or null if current), team_name, team_size_immediate, team_size_extended, mandate, reason_for_leaving ("still_employed" | "laid_off" | "quit_for_opportunity" | "quit_for_personal" | "contract_ended" | "company_wound_down" | "terminated" | "other"), tags (array, see below), collaborators (array, see below), links (array, see below)

project data: organization_name (required), position_title (optional, references a position at that org), parent_project_name (optional), name (required), public_name, description, problem, constraints, approach, outcome, rationale, date_precision ("day" | "month" | "quarter" | "year"), start_date (YYYY-MM-DD), end_date (YYYY-MM-DD or null), visibility ("public" | "open_source" | "internal" | "confidential"), status ("live" | "archived" | "killed" | "prototype" | "ongoing"), contribution_level ("lead" | "core" | "contributor" | "occasional" | "reviewer"), contribution_type, team_size, tags (array, see below), collaborators (array, see below), links (array, see below)

accomplishment data: organization_name (required), project_name (optional — sets the project this accomplishment belongs to), position_title (optional — sets the position this accomplishment belongs to when no project applies), title (required), description (required), impact_metric, impact_value, impact_unit, confidence (1-5 integer), prominence (1-5 integer), date (YYYY-MM-DD) OR period_start (YYYY-MM-DD) and optional period_end (YYYY-MM-DD), tags (array, see below), collaborators (array, see below), links (array, see below)

Nested `tags` shape: an array of objects, each `{"name": "Postgres", "category": "tool"}`. The name is the tag as the document phrases it (preserve casing). The category must be one of {{tag_categories}} — pick the best fit based on how the document uses the tag. Examples: "Python" → "language", "React" → "framework", "Postgres" → "tool", "REST" → "protocol", "fintech" → "domain", "agile" → "methodology", "AWS" → "vendor", "GPU" → "hardware", "machine learning" → "concept".

Nested `collaborators` shape: an array of objects, each `{"name": "Sarah Chen", "role": "Manager"}`. The role is the person's role with respect to this specific entity — "Manager" and "Engineering Director" on a position, "Reviewer" or "Co-author" on an accomplishment. Omit the role field if the document doesn't specify one.

Nested `links` shape: an array of objects, each `{"url": "https://...", "type": "github", "title": "...", "description": "...", "is_personal_appearance": false, "date": "YYYY-MM-DD"}`. The url is required. Always populate title with a short human-readable name for the link (e.g., "Acme Corp Website", "Migration Tool Source Code", "PyCon 2023 Talk Recording"). Always populate type — classify the link using one of {{link_types}}; default to "website" if uncertain. Populate description when the surrounding text provides context about what the link contains or why it matters (1-2 sentences). Prefer including a description over omitting one — if the document mentions the link in any context (e.g., "our company website", "the project's GitHub repo", "a case study we published"), use that context to write a brief description. Only omit description when the link appears with no surrounding context at all. Set "is_personal_appearance" to true when the URL features the user themselves (a talk recording, podcast appearance, media interview, bylined article); false for general repos, docs, or company links. Include date when the document indicates when the linked content was published or presented. Examples: a GitHub repo URL → type "github", title "Project Name Source Code"; the user's personal site → type "website", title "Personal Website"; a blog post the user wrote → type "blog_post", title from the post, is_personal_appearance true; a conference talk video → type "talk", title from the talk, is_personal_appearance true.

Rules:
- Each accomplishment must include organization_name AND either project_name (preferred) or position_title — never both project_name and position_title, never neither. The organization_name is always required; it provides the context the parent project or position belongs to.
- Each accomplishment must have either a single date OR a period_start (with optional period_end), never both, never neither.
- For confidence and prominence, use 3 if you cannot determine a meaningful value. Use 4-5 only when the source explicitly indicates strong evidence or high importance. Use 1-2 only when the source explicitly indicates uncertainty or low importance.
- For date_precision on projects, choose the precision the source supports. If the source says "shipped in Q2 2023," use "quarter". If "in 2023," use "year". If a specific month, use "month". If a specific day, use "day".
- Attach tags, collaborators, and links to the most specific entity that justifies them. If a tag describes a project's technology stack, nest it under that project, not the broader position. If a link is the source code for a specific project, nest it under that project. If a link is the company's careers page, nest it under the organization.
- If the document contains no extractable career records (it's a recipe, an unrelated email, etc.), return an empty array.

Return only the JSON array. No preamble, no commentary, no code fences.
PROMPT;

        return strtr($template, [
            '{{organization_types}}' => OrganizationType::promptEnumString(),
            '{{organization_statuses}}' => OrganizationStatus::promptEnumString(),
            '{{link_types}}' => '"' . implode('" | "', Link::TYPES) . '"',
            '{{tag_categories}}' => '"' . implode('" | "', Tag::CATEGORIES) . '"',
        ]);
    }
}