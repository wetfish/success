<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI extraction extension — adds review_decisions JSON column to
 * source_documents. Stores user decisions made during AI-extracted
 * tag/people/link review:
 *
 *   {
 *     "rejected_tags":          ["Postgres", "Java"],
 *     "renamed_tags":           {"Postgres 14": "Postgres"},
 *     "rejected_collaborators": ["Anonymous Mentor"],
 *     "renamed_collaborators":  {"Sarah Chen": "Sarah K Chen"},
 *     "rejected_links":         [42, 47]
 *   }
 *
 * Tag and collaborator rejections are by name (nested items don't have
 * their own IDs); link rejections are by extracted_record_id (links
 * are top-level drafts). Renames are AI-emitted-name → user-corrected-
 * name; the corrected name then goes through normal resolution.
 *
 * Catalog mutations (alias creation, tag creation, person creation,
 * person merges) happen immediately when the user makes the decision —
 * they don't live in this column. This column tracks only the
 * "negative space": names the user said NOT to use, or names to use a
 * different value for.
 *
 * Column is nullable with no default: MySQL prior to 8.0.13 disallows
 * inline DEFAULT values on JSON columns, and even on newer versions
 * the syntax is inconsistent. The model's read-side helpers
 * (rejectedTags, renamedTags, etc.) coalesce null to empty array, so
 * unseeded rows behave identically to rows with an empty object.
 *
 * See docs/03-routes-and-controllers.md for the broader rationale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_documents', function (Blueprint $table) {
            $table->json('review_decisions')->nullable()->after('context_notes');
        });
    }

    public function down(): void
    {
        Schema::table('source_documents', function (Blueprint $table) {
            $table->dropColumn('review_decisions');
        });
    }
};