<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of accomplishment_collaborators for positions. The convergence
 * goal: every taggable-with-people entity uses the same pivot shape, so
 * the picker UI, the AI extraction prompt, and any future "show me
 * everyone I've worked with" query operate on one consistent pattern.
 *
 * Role is free text (with a UI datalist for suggestions). "Manager"
 * lives in role_on_position rather than as a dedicated FK column on
 * positions — see the schema doc's people section.
 *
 * This migration is paired with `drop_reports_to_person_id_from_positions`
 * which removes the now-redundant FK column. The two run in order so
 * the new pivot exists before any data migration logic would run; for
 * MVP that's a no-op since no reports_to_person_id values have been
 * captured through the UI (the column has no form binding today).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('position_collaborators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained()->cascadeOnDelete();
            $table->string('role_on_position')->nullable();
            $table->timestamps();

            $table->unique(['position_id', 'person_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_collaborators');
    }
};