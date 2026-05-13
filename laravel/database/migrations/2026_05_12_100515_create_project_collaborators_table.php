<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of accomplishment_collaborators for projects. See the
 * companion `create_position_collaborators_table` migration for the
 * convergence rationale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_collaborators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained()->cascadeOnDelete();
            $table->string('role_on_project')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'person_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_collaborators');
    }
};