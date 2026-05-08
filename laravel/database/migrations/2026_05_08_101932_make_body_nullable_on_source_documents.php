<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make `body` nullable on source_documents.
 *
 * The original migration created body as `text NOT NULL` because at
 * the time, body was the only way content entered the system (pasted
 * text). With file upload support, PDFs leave body null — the file
 * itself is stored at `file_path` and sent directly to the extraction
 * provider, with no plain-text fallback in the body column.
 *
 * Text and markdown uploads still populate body (file contents are
 * read in at upload time), but PDFs cannot reasonably be converted
 * to plain text without a PDF parsing library, and we send the
 * binary directly to Claude anyway.
 *
 * Should have been part of the original "add file upload" migration.
 * Surfaced when slice 3.2 added the first end-to-end PDF tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_documents', function (Blueprint $table) {
            $table->text('body')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('source_documents', function (Blueprint $table) {
            $table->text('body')->nullable(false)->change();
        });
    }
};