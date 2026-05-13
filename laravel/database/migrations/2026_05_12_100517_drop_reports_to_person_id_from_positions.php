<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the legacy reports_to_person_id FK column from positions.
 * Manager relationships now live in the position_collaborators pivot
 * with role_on_position set to "Manager" (or similar — the role field
 * is free text).
 *
 * Runs after `create_position_collaborators_table` so the new home
 * for the data exists before the old column goes away. In production
 * with real reports_to_person_id values, this migration would also
 * migrate the data — copy each non-null `(position_id, reports_to_person_id)`
 * pair into a position_collaborators row with role_on_position =
 * "Manager". For MVP we skip that step because:
 *
 *   - The column was never exposed in any form, so no data has
 *     flowed into it through the UI.
 *   - The seeder doesn't populate it.
 *   - A pre-flight check warns if any non-null values are present.
 *
 * If the pre-flight check finds non-null values, the migration aborts
 * loudly rather than silently dropping data. The user is expected to
 * manually port those rows into position_collaborators before re-running.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Pre-flight: refuse to drop the column if any non-null values
        // exist that would otherwise be lost. Counts soft-deleted rows
        // too, since restoring a soft-deleted position should not bring
        // back a dangling FK.
        $orphans = \DB::table('positions')
            ->whereNotNull('reports_to_person_id')
            ->count();

        if ($orphans > 0) {
            throw new \RuntimeException(
                "Cannot drop reports_to_person_id: {$orphans} position(s) still "
                . 'have non-null values. Migrate those into position_collaborators '
                . 'rows (role_on_position = "Manager") before re-running.'
            );
        }

        Schema::table('positions', function (Blueprint $table) {
            // Drop the FK constraint by column name. Laravel auto-names
            // it `positions_reports_to_person_id_foreign`.
            $table->dropForeign(['reports_to_person_id']);
            $table->dropColumn('reports_to_person_id');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->foreignId('reports_to_person_id')
                ->nullable()
                ->after('team_size_extended')
                ->constrained('people')
                ->nullOnDelete();
        });
    }
};