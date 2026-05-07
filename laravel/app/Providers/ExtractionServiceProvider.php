<?php

namespace App\Providers;

use App\Services\Extraction\ClaudeExtractionProvider;
use App\Services\Extraction\ExtractionProvider;
use App\Services\Extraction\FakeExtractionProvider;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class ExtractionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ExtractionProvider::class, function ($app) {
            $driver = config('services.extraction.driver', 'claude');

            return match ($driver) {
                'claude' => new ClaudeExtractionProvider(
                    apiKey: (string) config('services.extraction.api_key', ''),
                    model: (string) config('services.extraction.model', 'claude-sonnet-4-6'),
                    inputCostPerMtokCents: (int) config('services.extraction.input_cost_per_mtok_cents', 300),
                    outputCostPerMtokCents: (int) config('services.extraction.output_cost_per_mtok_cents', 1500),
                ),
                'fake' => new FakeExtractionProvider(),
                default => throw new InvalidArgumentException(
                    "Unknown extraction driver: {$driver}"
                ),
            };
        });
    }

    public function boot(): void
    {
        //
    }
}