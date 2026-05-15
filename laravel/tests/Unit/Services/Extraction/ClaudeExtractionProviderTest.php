<?php

namespace Tests\Unit\Services\Extraction;

use App\Models\SourceDocument;
use App\Services\Extraction\ClaudeExtractionProvider;
use App\Services\Extraction\ExtractionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * These tests use Laravel's Http::fake() to simulate the Anthropic
 * API. They verify the request shape, response parsing, error
 * handling, and cost computation without making real API calls.
 *
 * For real API integration testing, run the test:extraction artisan
 * command manually with a configured API key.
 */
class ClaudeExtractionProviderTest extends TestCase
{
    private function makeProvider(string $apiKey = 'test-key'): ClaudeExtractionProvider
    {
        return new ClaudeExtractionProvider(
            apiKey: $apiKey,
            model: 'claude-sonnet-4-6',
            inputCostPerMtokCents: 300,
            outputCostPerMtokCents: 1500,
        );
    }

    private function makeDocument(string $body = 'Some career notes'): SourceDocument
    {
        return new SourceDocument([
            'title' => 'Test',
            'kind' => 'other',
            'file_type' => 'text',
            'body' => $body,
        ]);
    }

    #[Test]
    public function is_available_returns_false_when_api_key_is_empty(): void
    {
        $provider = $this->makeProvider(apiKey: '');
        $this->assertFalse($provider->isAvailable());
    }

    #[Test]
    public function is_available_returns_true_when_count_tokens_succeeds(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages/count_tokens' => Http::response(['input_tokens' => 5], 200),
        ]);

        $this->assertTrue($this->makeProvider()->isAvailable());
    }

    #[Test]
    public function is_available_returns_false_when_count_tokens_fails(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages/count_tokens' => Http::response(['error' => 'invalid'], 401),
        ]);

        $this->assertFalse($this->makeProvider()->isAvailable());
    }

    #[Test]
    public function extract_parses_drafts_from_a_clean_json_response(): void
    {
        $extractedJson = json_encode([
            ['type' => 'organization', 'data' => ['name' => 'Acme']],
            ['type' => 'position', 'data' => ['title' => 'Engineer', 'organization_name' => 'Acme']],
        ]);

        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => $extractedJson]],
                'usage' => ['input_tokens' => 1000, 'output_tokens' => 200],
            ], 200),
        ]);

        $result = $this->makeProvider()->extract($this->makeDocument());

        $this->assertCount(2, $result->drafts);
        $this->assertSame('organization', $result->drafts[0]->type);
        $this->assertSame('Acme', $result->drafts[0]->data['name']);
        $this->assertSame(1000, $result->inputTokens);
        $this->assertSame(200, $result->outputTokens);
    }

    #[Test]
    public function extract_strips_code_fences_from_response(): void
    {
        $extractedJson = "```json\n" . json_encode([
            ['type' => 'organization', 'data' => ['name' => 'Acme']],
        ]) . "\n```";

        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => $extractedJson]],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            ], 200),
        ]);

        $result = $this->makeProvider()->extract($this->makeDocument());
        $this->assertCount(1, $result->drafts);
        $this->assertSame('Acme', $result->drafts[0]->data['name']);
    }

    #[Test]
    public function extract_returns_empty_drafts_for_empty_array_response(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => '[]']],
                'usage' => ['input_tokens' => 50, 'output_tokens' => 5],
            ], 200),
        ]);

        $result = $this->makeProvider()->extract($this->makeDocument());
        $this->assertCount(0, $result->drafts);
    }

    #[Test]
    public function extract_parses_entity_drafts_with_nested_tags_and_collaborators(): void
    {
        // Per the milestone 4.6 architecture, the AI emits tags and
        // people only as nested arrays on entity drafts. Top-level
        // `tag` and `person` records are derived by business logic
        // downstream, not produced by the AI. This test asserts the
        // nested shapes survive parseDrafts() unchanged.
        $extractedJson = json_encode([
            ['type' => 'project', 'data' => [
                'organization_name' => 'Acme',
                'name' => 'User DB Migration',
                'visibility' => 'internal',
                'contribution_level' => 'core',
                'tags' => [
                    ['name' => 'Postgres', 'category' => 'tool'],
                    ['name' => 'Python', 'category' => 'language'],
                ],
                'collaborators' => [
                    ['name' => 'Sarah Chen', 'role' => 'Manager'],
                ],
            ]],
        ]);

        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => $extractedJson]],
                'usage' => ['input_tokens' => 500, 'output_tokens' => 100],
            ], 200),
        ]);

        $result = $this->makeProvider()->extract($this->makeDocument());

        $this->assertCount(1, $result->drafts);
        $this->assertSame('project', $result->drafts[0]->type);

        $tags = $result->drafts[0]->data['tags'];
        $this->assertCount(2, $tags);
        $this->assertSame('Postgres', $tags[0]['name']);
        $this->assertSame('tool', $tags[0]['category']);
        $this->assertSame('Python', $tags[1]['name']);
        $this->assertSame('language', $tags[1]['category']);

        $collaborators = $result->drafts[0]->data['collaborators'];
        $this->assertCount(1, $collaborators);
        $this->assertSame('Sarah Chen', $collaborators[0]['name']);
        $this->assertSame('Manager', $collaborators[0]['role']);
    }

    #[Test]
    public function extract_parses_top_level_link_records(): void
    {
        // Links remain top-level — unlike tags and people, they aren't
        // derivable from entity drafts and need an explicit parent
        // reference (linkable_type + linkable_name) to be useful.
        $extractedJson = json_encode([
            ['type' => 'link', 'data' => [
                'url' => 'https://github.com/example/repo',
                'linkable_type' => 'project',
                'linkable_name' => 'User DB Migration',
                'organization_name' => 'Acme',
                'type' => 'github',
                'title' => 'Source repository',
            ]],
        ]);

        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => $extractedJson]],
                'usage' => ['input_tokens' => 500, 'output_tokens' => 100],
            ], 200),
        ]);

        $result = $this->makeProvider()->extract($this->makeDocument());

        $this->assertCount(1, $result->drafts);
        $this->assertSame('link', $result->drafts[0]->type);
        $this->assertSame('https://github.com/example/repo', $result->drafts[0]->data['url']);
        $this->assertSame('project', $result->drafts[0]->data['linkable_type']);
        $this->assertSame('User DB Migration', $result->drafts[0]->data['linkable_name']);
    }

    #[Test]
    public function extract_throws_on_unparseable_json(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => 'this is not json']],
                'usage' => ['input_tokens' => 50, 'output_tokens' => 10],
            ], 200),
        ]);

        $this->expectException(ExtractionException::class);
        $this->makeProvider()->extract($this->makeDocument());
    }

    #[Test]
    public function extract_throws_when_api_returns_error_status(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response(['error' => 'rate limit'], 429),
        ]);

        $this->expectException(ExtractionException::class);
        $this->makeProvider()->extract($this->makeDocument());
    }

    #[Test]
    public function extract_throws_on_malformed_draft_missing_type(): void
    {
        $extractedJson = json_encode([
            ['data' => ['name' => 'Missing type']],
        ]);

        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => $extractedJson]],
                'usage' => ['input_tokens' => 50, 'output_tokens' => 10],
            ], 200),
        ]);

        $this->expectException(ExtractionException::class);
        $this->makeProvider()->extract($this->makeDocument());
    }

    #[Test]
    public function extract_computes_cost_from_token_counts(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => '[]']],
                'usage' => ['input_tokens' => 1_000_000, 'output_tokens' => 200_000],
            ], 200),
        ]);

        $result = $this->makeProvider()->extract($this->makeDocument());

        // 1M input * 300 cents/MTok = 300 cents
        // 200K output * 1500 cents/MTok = 300 cents
        // Total: 600 cents = $6.00
        $this->assertSame(600, $result->costCents);
    }

    #[Test]
    public function estimate_tokens_returns_count_from_api(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages/count_tokens' => Http::response([
                'input_tokens' => 1234,
            ], 200),
        ]);

        $estimate = $this->makeProvider()->estimateTokens($this->makeDocument());
        $this->assertSame(1234, $estimate);
    }

    #[Test]
    public function synthesize_returns_combined_description(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => 'A unified description.']],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            ], 200),
        ]);

        $result = $this->makeProvider()->synthesize('Old', 'New');

        $this->assertSame('A unified description.', $result->description);
        $this->assertSame(100, $result->inputTokens);
        $this->assertSame(50, $result->outputTokens);
    }

    #[Test]
    public function summarize_title_returns_short_title(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Stripe interview prep']],
                'usage' => ['input_tokens' => 200, 'output_tokens' => 5],
            ], 200),
        ]);

        $result = $this->makeProvider()->summarizeTitle('Long body of career notes...');

        $this->assertSame('Stripe interview prep', $result->title);
        $this->assertSame(200, $result->inputTokens);
        $this->assertSame(5, $result->outputTokens);
    }

    #[Test]
    public function summarize_title_strips_surrounding_quotes_from_response(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => '"Stripe interview prep"']],
                'usage' => ['input_tokens' => 200, 'output_tokens' => 5],
            ], 200),
        ]);

        $result = $this->makeProvider()->summarizeTitle('Long body of career notes...');

        $this->assertSame('Stripe interview prep', $result->title);
    }

    #[Test]
    public function summarize_title_strips_trailing_period_from_response(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Stripe interview prep.']],
                'usage' => ['input_tokens' => 200, 'output_tokens' => 5],
            ], 200),
        ]);

        $result = $this->makeProvider()->summarizeTitle('Long body of career notes...');

        $this->assertSame('Stripe interview prep', $result->title);
    }

    #[Test]
    public function summarize_title_throws_when_api_returns_error_status(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response(['error' => 'rate limit'], 429),
        ]);

        $this->expectException(ExtractionException::class);
        $this->makeProvider()->summarizeTitle('Some text');
    }

    #[Test]
    public function extract_sends_correct_headers(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => '[]']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ], 200),
        ]);

        $this->makeProvider(apiKey: 'my-test-key')->extract($this->makeDocument());

        Http::assertSent(function ($request) {
            return $request->hasHeader('x-api-key', 'my-test-key')
                && $request->hasHeader('anthropic-version', '2023-06-01');
        });
    }

    #[Test]
    public function provider_name_is_claude(): void
    {
        $this->assertSame('claude', $this->makeProvider()->name());
    }
}