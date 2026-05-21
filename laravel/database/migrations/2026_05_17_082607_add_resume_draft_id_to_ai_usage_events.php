<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a nullable resume_draft_id FK to ai_usage_events so resume
 * generation costs are tracked alongside extraction costs in the
 * same table.
 *
 * Nullable and nullOnDelete — same pattern as source_document_id.
 * Usage records survive deletion of the draft they were tied to
 * so cost telemetry is preserved.
 *
 * New operation values for the existing `operation` column:
 *   generate_resume — rough draft generation (step 2)
 *   format_resume   — formatted document generation (step 3)
 *   extract_listing — AI extraction of listing structured_data
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_events', function (Blueprint $table) {
            $table->foreignId('resume_draft_id')->nullable()
                ->after('source_document_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resume_draft_id');
        });
    }
};