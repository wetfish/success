<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable formatted output files. Each artifact is a point-in-time
 * snapshot of a rendered resume — the user_content from the parent
 * resume_draft converted into a downloadable document.
 *
 * A draft can produce multiple artifacts: the user might generate
 * a .docx first, then also want a .pdf, or re-generate after
 * further edits.
 *
 * Soft deletes protect against accidental loss — the mission doc
 * specifies that generated resumes are immutable artifacts tied
 * to specific applications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resume_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resume_draft_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_format');
            $table->unsignedInteger('file_size_bytes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resume_artifacts');
    }
};