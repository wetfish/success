<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->string('website')->nullable();
            $table->string('tagline')->nullable();
            $table->text('description')->nullable();
            $table->string('headquarters')->nullable();
            $table->smallInteger('founded_year')->nullable();
            $table->string('size_estimate')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('enriched_at')->nullable();
            $table->text('user_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};