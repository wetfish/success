<?php

namespace App\Services\Drafts;

use RuntimeException;

/**
 * Thrown when a draft cannot be confirmed. The most common cause
 * is an unresolvable parent reference — for example, a position
 * draft naming an organization that doesn't exist in the catalog
 * yet. The exception message is user-facing and explains what
 * the user needs to do.
 */
class DraftConfirmationException extends RuntimeException
{
}