<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One draft per resume generation attempt against a job listing.
 * The `status` column drives the three-step wizard flow:
 *
 *   selecting  — AI has suggested catalog entries, user is reviewing
 *                which experience to include (step 1)
 *   drafting   — selections confirmed, AI generation in progress
 *   editing    — draft generated, user is reviewing/editing (step 2)
 *   approved   — user approved the draft, ready for formatting
 *   formatted  — final document generated (step 3 complete)
 *
 * Content is stored in two columns rather than a revisions table:
 *   generated_content — immutable AI output, preserved for revert
 *   user_content      — starts as a copy of generated_content,
 *                        user edits update only this column
 *
 * A listing can have multiple drafts (different angles, re-generation
 * after catalog updates). Each draft has its own selections, content,
 * and artifacts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resume_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_listing_id')->constrained()->cascadeOnDelete();
            $table->text('generated_content')->nullable();
            $table->text('user_content')->nullable();
            $table->string('format_preference')->nullable();
            $table->string('status')->default('selecting');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['job_listing_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resume_drafts');
    }
};