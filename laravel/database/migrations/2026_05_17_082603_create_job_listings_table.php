<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entry point to the resume generation flow. A job listing captures
 * a role the user is applying to, always parented by an organization
 * (typically type = 'prospect', though applying to a former employer
 * is valid).
 *
 * The raw listing text is preserved verbatim in `body` — same
 * principle as source_documents. `structured_data` holds AI-extracted
 * fields (requirements, nice-to-haves, responsibilities) as JSON
 * rather than dedicated columns because the shape will evolve during
 * dogfooding. JSON→columns migration is cheaper than wrong-columns→
 * right-columns migration.
 *
 * `compensation_range` is free text because listing formats vary
 * wildly ("$120-150k", "competitive", "$80/hr") and this data is
 * for AI context, not arithmetic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('role_title');
            $table->text('body');
            $table->json('structured_data')->nullable();
            $table->string('source_url')->nullable();
            $table->string('location')->nullable();
            $table->string('compensation_range')->nullable();
            $table->date('date_posted')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index('organization_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_listings');
    }
};