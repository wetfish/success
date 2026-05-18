<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured requirements extracted by the AI from a job listing's
 * text. These are properties of the listing itself, not of any
 * specific draft — multiple resume drafts against the same listing
 * share the same requirements.
 *
 * The `section` column captures which part of the listing the
 * requirement came from: `required` (hard requirements),
 * `preferred` (nice-to-haves), or `responsibility` (what you'll
 * do in the role). All three matter for resume framing — the user
 * wants to demonstrate they meet requirements, have bonus skills,
 * and have done similar work.
 *
 * The `category` column classifies the type of requirement:
 * technical_skill, framework, tool, experience, responsibility,
 * domain_knowledge, soft_skill, credential, or other. These map
 * naturally to resume sections and prompt instructions.
 *
 * Both columns are plain strings (not database enums) per project
 * conventions — accepted values are enforced by a PHP backed enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_listing_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_listing_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('section')->default('required');
            $table->integer('display_order')->default(0);
            $table->timestamps();

            $table->index('job_listing_id');
            $table->index(['job_listing_id', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_listing_requirements');
    }
};