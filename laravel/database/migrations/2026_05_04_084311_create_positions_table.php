<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('informal_title')->nullable();
            $table->string('employment_type');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('location_arrangement');
            $table->string('location_text')->nullable();
            $table->string('team_name')->nullable();
            $table->smallInteger('team_size_immediate')->nullable();
            $table->smallInteger('team_size_extended')->nullable();
            $table->foreignId('reports_to_person_id')
                ->nullable()
                ->constrained('people')
                ->nullOnDelete();
            $table->text('mandate')->nullable();
            $table->string('reason_for_leaving')->nullable();
            $table->text('reason_for_leaving_notes')->nullable();
            $table->text('user_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};