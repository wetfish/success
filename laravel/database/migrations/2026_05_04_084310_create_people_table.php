<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('current_title')->nullable();
            $table->foreignId('current_organization_id')
                ->nullable()
                ->constrained('organizations')
                ->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('relationship_type')->nullable();
            $table->text('user_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};