<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks which catalog entries the AI suggested for a resume and
 * whether the user chose to include each one. This is the step 1
 * output — the curated set of experience that feeds into draft
 * generation.
 *
 * Polymorphic across six entity types: Position, Project,
 * Accomplishment, CareerTheme, Tag, and Link (specifically links
 * with is_personal_appearance = true, surfaced as portfolio items).
 *
 * The review UI groups selections by position (the natural resume
 * structure), with projects and accomplishments nested under their
 * parent position via existing relationships — that grouping is
 * derived at render time, not stored here.
 *
 * `ai_reasoning` holds the AI's explanation for why it suggested
 * each entry, displayed in the review UI to help the user decide.
 *
 * No soft deletes — these are lightweight decision records with
 * a simple lifecycle, similar to accomplishment_collaborators.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resume_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resume_draft_id')->constrained()->cascadeOnDelete();
            $table->string('selectable_type');
            $table->unsignedBigInteger('selectable_id');
            $table->boolean('selected')->default(true);
            $table->text('ai_reasoning')->nullable();
            $table->integer('display_order')->default(0);
            $table->timestamps();

            $table->index(['resume_draft_id', 'selectable_type']);
            $table->index(['selectable_type', 'selectable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resume_selections');
    }
};