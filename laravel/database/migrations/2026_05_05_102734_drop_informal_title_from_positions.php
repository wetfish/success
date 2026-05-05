<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the informal_title column from positions.
 *
 * The field was originally intended to capture cases where a formal job
 * title undersold the actual scope of work (e.g., "Software Engineer III"
 * doing the work of a tech lead). On reflection, this information is
 * better captured naturally through accomplishments, project context,
 * and user_notes rather than a dedicated field. Removing it simplifies
 * the form and the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('informal_title');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->string('informal_title')->nullable()->after('title');
        });
    }
};