<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Denormalizes team_id onto applications/services so team-scoped queries can use a
     * direct indexed column instead of a 3-hop whereRelation('environment.project.team', ...)
     * EXISTS chain. Kept nullable at the DB level even though every row gets backfilled below:
     * a hard NOT NULL constraint would turn any pre-existing orphaned environment_id (data drift
     * from outside this migration's control) into a failed deploy instead of a merely-unscoped row.
     */
    public function up(): void
    {
        foreach (['applications', 'services'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->foreignId('team_id')->nullable()->index();
            });
        }

        $teamIdByEnvironmentId = DB::table('environments')
            ->join('projects', 'projects.id', '=', 'environments.project_id')
            ->pluck('projects.team_id', 'environments.id');

        foreach (['applications', 'services'] as $table) {
            DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($table, $teamIdByEnvironmentId) {
                foreach ($rows as $row) {
                    $teamId = $teamIdByEnvironmentId->get($row->environment_id);
                    if ($teamId !== null) {
                        DB::table($table)->where('id', $row->id)->update(['team_id' => $teamId]);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['applications', 'services'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('team_id');
            });
        }
    }
};
