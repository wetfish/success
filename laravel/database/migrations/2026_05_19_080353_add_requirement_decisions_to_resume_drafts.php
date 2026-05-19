<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the wizard triage state to resume_drafts. The JSON column
 * maps requirement IDs to accept/reject decisions made on Screen 1
 * of the multi-page wizard:
 *
 *   {"14": "accepted", "15": "rejected", "16": "accepted", ...}
 *
 * Accepted requirements proceed to Screen 2 (per-requirement
 * selection review). Rejected requirements are skipped — their
 * AI-suggested selections remain in the database but don't appear
 * in the wizard or feed into draft generation.
 *
 * Nullable because the column is empty until the user starts
 * making triage decisions on Screen 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resume_drafts', function (Blueprint $table) {
            $table->json('requirement_decisions')->nullable()
                ->after('strategy_summary');
        });
    }

    public function down(): void
    {
        Schema::table('resume_drafts', function (Blueprint $table) {
            $table->dropColumn('requirement_decisions');
        });
    }
};