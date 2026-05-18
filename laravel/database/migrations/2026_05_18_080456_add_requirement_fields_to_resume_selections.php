<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds requirement-centric fields to resume_selections:
 *
 *   job_listing_requirement_id — FK to the requirement this
 *     selection addresses. Nullable because some selections are
 *     general resume content not tied to a specific requirement
 *     (e.g., career themes, general portfolio links). These
 *     render in an "Other" section on the review page. Set null
 *     on delete so selections survive if a requirement is removed.
 *
 *   user_relevance_note — the user's description of HOW this
 *     catalog entry addresses the requirement. Distinct from
 *     ai_reasoning: the AI explains why it suggested the entry,
 *     the user explains how they'd frame it for this specific
 *     application. Feeds into the draft generation prompt in 5.3
 *     alongside the catalog entry's structured data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resume_selections', function (Blueprint $table) {
            $table->foreignId('job_listing_requirement_id')->nullable()
                ->after('resume_draft_id')
                ->constrained('job_listing_requirements')
                ->nullOnDelete();

            $table->text('user_relevance_note')->nullable()
                ->after('ai_reasoning');
        });
    }

    public function down(): void
    {
        Schema::table('resume_selections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('job_listing_requirement_id');
            $table->dropColumn('user_relevance_note');
        });
    }
};