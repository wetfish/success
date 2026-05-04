<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accomplishment_collaborators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accomplishment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained()->cascadeOnDelete();
            $table->string('role_on_accomplishment')->nullable();
            $table->timestamps();

            $table->unique(['accomplishment_id', 'person_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accomplishment_collaborators');
    }
};