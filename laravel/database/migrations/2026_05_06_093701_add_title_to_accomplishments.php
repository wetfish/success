<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a title column to accomplishments. The description field has
 * been doing double duty as both heading and body, which works poorly
 * once descriptions get longer than a few words. The title gives us
 * a short scannable label for list views and headings.
 *
 * The column is non-nullable with a default of 'Untitled Accomplishment'
 * so existing rows get backfilled automatically. New rows are required
 * to have a real title — enforced at the validator layer in
 * AccomplishmentRules. The DB default exists only to keep the migration
 * non-destructive for already-entered data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accomplishments', function (Blueprint $table) {
            $table->string('title')
                ->default('Untitled Accomplishment')
                ->after('position_id');
        });
    }

    public function down(): void
    {
        Schema::table('accomplishments', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};