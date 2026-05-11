<?php

namespace App\Services\Drafts;

use RuntimeException;

/**
 * Thrown when a draft cannot be merged into an existing record.
 * Common causes: the draft was already reviewed (not pending), the
 * target record is the wrong type for the draft, or a model-level
 * invariant rejects the chosen field values. The exception message
 * is user-facing and explains what went wrong; the controller
 * surfaces it as a flash message and stays on the merge page so
 * the user can adjust their choices.
 *
 * Parallel to DraftConfirmationException — same exception-as-flash
 * pattern. Keep both in sync if the convention changes.
 */
class DraftMergerException extends RuntimeException
{
}