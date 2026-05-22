<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Freeform style guidelines for document formatting. The user
 * describes brand-specific preferences (fonts, colors, layout
 * style) that the AI incorporates when generating the document
 * spec. Nullable — omitting guidelines uses the default
 * professional resume styling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resume_drafts', function (Blueprint $table) {
            $table->text('style_guidelines')->nullable()
                ->after('user_content');
        });
    }

    public function down(): void
    {
        Schema::table('resume_drafts', function (Blueprint $table) {
            $table->dropColumn('style_guidelines');
        });
    }
};