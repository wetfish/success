<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_theme_projects', function (Blueprint $table) {
            $table->foreignId('career_theme_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->primary(['career_theme_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_theme_projects');
    }
};