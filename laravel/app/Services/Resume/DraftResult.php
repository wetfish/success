<?php

namespace App\Services\Resume;

/**
 * The result of a resume draft generation call. The AI produces
 * markdown prose from the user's confirmed selections, strategy,
 * and requirement context.
 *
 * The markdown is stored twice on the ResumeDraft:
 *   generated_content — immutable AI output, preserved for revert
 *   user_content      — editable copy the user works with
 *
 * Cost is in cents per the Money helper convention.
 */
class DraftResult
{
    public function __construct(
        public readonly string $markdown,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $costCents,
        public readonly string $model,
    ) {}
}