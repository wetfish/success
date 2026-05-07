<?php

namespace App\Services\Extraction;

use Exception;

/**
 * Thrown by ExtractionProvider implementations on any failure:
 * network errors, API errors, malformed responses, unparseable
 * JSON, or rate limits.
 */
class ExtractionException extends Exception
{
}