<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('position_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('parent_project_id')
                ->nullable()
                ->constrained('projects')
                ->nullOnDelete();
            $table->string('name');
            $table->string('public_name')->nullable();
            $table->text('description')->nullable();
            $table->text('problem')->nullable();
            $table->text('constraints')->nullable();
            $table->text('approach')->nullable();
            $table->text('outcome')->nullable();
            $table->text('rationale')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('date_precision')->default('month');
            $table->string('visibility');
            $table->string('status')->nullable();
            $table->string('contribution_level');
            $table->string('contribution_type')->nullable();
            $table->smallInteger('team_size')->nullable();
            $table->text('user_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};