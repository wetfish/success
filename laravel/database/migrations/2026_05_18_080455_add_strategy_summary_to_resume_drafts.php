<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the strategy summary to resume_drafts. Same two-column
 * pattern as generated_content/user_content:
 *
 *   strategy_summary_generated — immutable AI output, the
 *     recommended narrative angle for the application
 *   strategy_summary — user-editable copy, starts as a clone
 *     of the generated version, user adjusts framing
 *
 * The strategy summary is produced during the initial AI analysis
 * (same call that extracts requirements and maps selections). It
 * feeds into the draft generation prompt in 5.3 as top-level
 * guidance for tone, emphasis, and narrative structure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resume_drafts', function (Blueprint $table) {
            $table->text('strategy_summary_generated')->nullable()
                ->after('job_listing_id');
            $table->text('strategy_summary')->nullable()
                ->after('strategy_summary_generated');
        });
    }

    public function down(): void
    {
        Schema::table('resume_drafts', function (Blueprint $table) {
            $table->dropColumn(['strategy_summary_generated', 'strategy_summary']);
        });
    }
};