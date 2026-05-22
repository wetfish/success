<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User-provided title for the generated document. Displayed on
 * the artifact card and used as the download filename.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resume_artifacts', function (Blueprint $table) {
            $table->string('title')->nullable()->after('resume_draft_id');
        });
    }

    public function down(): void
    {
        Schema::table('resume_artifacts', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};