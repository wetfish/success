<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accomplishments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('position_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();
            $table->text('description');
            $table->string('impact_metric')->nullable();
            $table->string('impact_value')->nullable();
            $table->string('impact_unit')->nullable();
            $table->tinyInteger('confidence')->nullable();
            $table->tinyInteger('prominence')->nullable();
            $table->text('context_notes')->nullable();
            $table->date('date')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accomplishments');
    }
};