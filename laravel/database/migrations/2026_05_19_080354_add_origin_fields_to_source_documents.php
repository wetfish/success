<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds origin tracking to source_documents. When a user enters
 * freeform text during the per-requirement review wizard (Screen 2),
 * that text becomes a source document processed through the existing
 * extraction pipeline. These columns provide the audit trail:
 *
 *   origin — where the document came from. Default 'career_input'
 *     matches the existing flow (home page paste). The new value
 *     'requirement_response' indicates text entered during the
 *     resume wizard's per-requirement review.
 *
 *   job_listing_requirement_id — which specific requirement
 *     prompted this text. Only populated when origin is
 *     'requirement_response'. Nullable FK with nullOnDelete so
 *     the document survives if the requirement is later removed
 *     (e.g., via re-analysis).
 *
 * Every catalog entry created from a requirement_response document
 * traces back to the specific requirement that prompted it. This
 * closes the lifecycle loop: applying to jobs grows the catalog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_documents', function (Blueprint $table) {
            $table->string('origin')->default('career_input')
                ->after('kind');
            $table->foreignId('job_listing_requirement_id')->nullable()
                ->after('origin')
                ->constrained('job_listing_requirements')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('source_documents', function (Blueprint $table) {
            $table->dropForeign(['job_listing_requirement_id']);
            $table->dropColumn(['origin', 'job_listing_requirement_id']);
        });
    }
};