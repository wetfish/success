<?php

namespace App\Services\Extraction;

/**
 * A single draft extracted from a source document. The type indicates
 * which entity this draft would become (organization / position /
 * project / accomplishment) and the data is the keyed array of would-be
 * field values matching that entity's schema.
 *
 * Drafts are immutable values flowing out of an ExtractionProvider.
 * The caller decides what to do with them — persist as ExtractedRecord
 * rows, dump to console, etc.
 */
class DraftRecord
{
    public function __construct(
        public readonly string $type,
        public readonly array $data,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'data' => $this->data,
        ];
    }
}