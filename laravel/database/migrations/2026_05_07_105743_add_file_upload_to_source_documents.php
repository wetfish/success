<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds file upload support to source_documents. Two new columns:
 *
 *   file_path  — relative storage path for uploaded files
 *                (PDFs, .txt, .md). Null for pasted text.
 *   file_type  — 'text', 'markdown', or 'pdf'. Drives how the
 *                extraction provider handles the source — text and
 *                markdown read from the existing `body` column,
 *                PDFs are sent directly to Claude as base64.
 *
 * No extraction status columns are added. Status is derived from
 * related tables: 'pending' = no extracted_records yet, 'completed' =
 * extracted_records exist, 'failed' = ai_usage_events row with
 * success=false but no extracted_records. Helper methods on the
 * SourceDocument model expose this derivation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_documents', function (Blueprint $table) {
            $table->string('file_path')->nullable()->after('title');
            $table->string('file_type')->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('source_documents', function (Blueprint $table) {
            $table->dropColumn(['file_path', 'file_type']);
        });
    }
};