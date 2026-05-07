<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks every AI API call: which provider, what operation, how many
 * tokens, what it cost. Lets us answer "how much have I spent this
 * month" and "is text extraction or PDF extraction more expensive
 * per document" without wiring up Anthropic's billing API.
 *
 * Cost is stored in cents per the Money helper convention.
 *
 * source_document_id is nullable — some operations (like isAvailable
 * health checks) aren't tied to a specific document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('model');
            $table->string('operation');
            $table->foreignId('source_document_id')->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cost_cents')->default(0);
            $table->boolean('success')->default(true);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('provider');
            $table->index('operation');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_events');
    }
};