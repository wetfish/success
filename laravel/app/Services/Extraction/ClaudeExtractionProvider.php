<?php

namespace App\Services\Extraction;

use App\Models\SourceDocument;
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
     */
    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are extracting structured career records from a document. The document is the user's notes, performance review, brag doc, resume, or similar source material about their professional history.

Return a JSON array of records. Each record has a "type" and a "data" object. Possible types:

- "organization" — a company, project sponsor, or institution where the user worked
- "position" — a specific role at an organization
- "project" — a discrete body of work
- "accomplishment" — a single achievement with measurable or describable impact

For each type, the "data" object uses these fields. Omit fields you cannot determine from the document. Do not invent values.

organization data: name (required), type ("employer" | "client" | "personal" | "open_source" | "volunteer" | "educational"), website, tagline, description, headquarters, founded_year, size_estimate, status

position data: organization_name (required, references an organization in the same response or an existing one), title (required), employment_type ("full_time" | "part_time" | "contract" | "freelance" | "internship" | "advisor" | "volunteer" | "founder"), location_arrangement ("remote" | "hybrid" | "on_site"), location_text, start_date (YYYY-MM-DD), end_date (YYYY-MM-DD or null if current), team_name, team_size_immediate, team_size_extended, mandate, reason_for_leaving ("still_employed" | "laid_off" | "quit_for_opportunity" | "quit_for_personal" | "contract_ended" | "company_wound_down" | "terminated" | "other")

project data: organization_name (required), position_title (optional, references a position at that org), parent_project_name (optional), name (required), public_name, description, problem, constraints, approach, outcome, rationale, date_precision ("day" | "month" | "quarter" | "year"), start_date (YYYY-MM-DD), end_date (YYYY-MM-DD or null), visibility ("public" | "open_source" | "internal" | "confidential"), status ("live" | "archived" | "killed" | "prototype" | "ongoing"), contribution_level ("lead" | "core" | "contributor" | "occasional" | "reviewer"), contribution_type, team_size

accomplishment data: project_name (optional), position_title (optional, with organization_name), title (required), description (required), impact_metric, impact_value, impact_unit, confidence (1-5 integer), prominence (1-5 integer), date (YYYY-MM-DD) OR period_start (YYYY-MM-DD) and optional period_end (YYYY-MM-DD)

Rules:
- Each accomplishment must have either a project (via project_name) or a position (via organization_name + position_title), never both, never neither.
- Each accomplishment must have either a single date OR a period_start (with optional period_end), never both, never neither.
- For confidence and prominence, use 3 if you cannot determine a meaningful value. Use 4-5 only when the source explicitly indicates strong evidence or high importance. Use 1-2 only when the source explicitly indicates uncertainty or low importance.
- For date_precision on projects, choose the precision the source supports. If the source says "shipped in Q2 2023," use "quarter". If "in 2023," use "year". If a specific month, use "month". If a specific day, use "day".
- If the document contains no extractable career records (it's a recipe, an unrelated email, etc.), return an empty array.

Return only the JSON array. No preamble, no commentary, no code fences.
PROMPT;
    }
}