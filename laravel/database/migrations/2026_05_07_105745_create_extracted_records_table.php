<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holds drafts produced by AI extraction. Drafts are intentionally
 * kept in this separate table rather than mixed with confirmed records
 * via a status column, so the rest of the app can ignore them entirely
 * until the user reviews and confirms.
 *
 *   record_type  — 'organization', 'position', 'project', or
 *                  'accomplishment'. The shape of the payload depends
 *                  on this.
 *   payload      — JSON blob of the draft's would-be field values.
 *                  Validated only when the user confirms (since drafts
 *                  may be incomplete or contain values the schema
 *                  doesn't accept yet).
 *   status       — 'pending' (awaiting review), 'confirmed' (became
 *                  a real record), 'rejected' (discarded), 'merged'
 *                  (combined with an existing record).
 *   match_record_type, match_record_id — when duplicate detection
 *                  finds a candidate match in the existing catalog,
 *                  these point at it. Polymorphic-style without a
 *                  formal morph relation since we only need read
 *                  access from app code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extracted_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_document_id')->constrained()->cascadeOnDelete();
            $table->string('record_type');
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->string('match_record_type')->nullable();
            $table->unsignedBigInteger('match_record_id')->nullable();
            $table->timestamps();

            $table->index(['source_document_id', 'status']);
            $table->index('record_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extracted_records');
    }
};