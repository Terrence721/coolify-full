<?php

declare(strict_types=1);

use App\Models\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Regression coverage for a real, scheduled-daily bug (app/Console/Kernel.php runs
// `cleanup:database --yes` every day): the activity_log/application_deployment_queues cleanup
// blocks chained ->orderBy(...)->skip(10) before both ->count() and ->delete(). Neither works as
// intended on this query builder - ->skip(N)->count() compiles to a single-row aggregate query
// with `OFFSET N`, which discards that one row and always returns 0 regardless of real data, and
// ->skip(N)->delete() silently drops the offset entirely (Postgres's grammar only special-cases
// ->limit() on DELETE), deleting every matching row instead of preserving the intended buffer.
// Fixed by resolving the "keep" ids explicitly and filtering via whereNotIn() instead.

uses(TestCase::class, RefreshDatabase::class);

function seedOldActivityLogRow(string $description, int $daysAgo): void
{
    DB::table('activity_log')->insert([
        'description' => $description,
        'created_at' => now()->subDays($daysAgo),
        'updated_at' => now()->subDays($daysAgo),
    ]);
}

it('reports the real count to delete, not always zero, and preserves the 10 most-recent-of-the-old rows', function () {
    // 3 recent rows, well within the retention window - must never be touched.
    seedOldActivityLogRow('recent-1', 5);
    seedOldActivityLogRow('recent-2', 4);
    seedOldActivityLogRow('recent-3', 3);

    // 15 old rows (older than the 60-day keep window), spaced a day apart so ordering by
    // created_at desc is deterministic. old-90 (90 days ago) is the most recent of the old rows,
    // old-104 is the oldest.
    for ($i = 0; $i < 15; $i++) {
        $daysAgo = 90 + $i;
        seedOldActivityLogRow("old-{$daysAgo}", $daysAgo);
    }

    ob_start();
    $this->artisan('cleanup:database', ['--yes' => true, '--keep-days' => 60]);
    $output = ob_get_clean();

    // Before the fix this always printed "Delete 0 entries from activity_log." regardless of the
    // real data, because ->skip(10)->count() always returns 0.
    expect($output)->toContain('Delete 5 entries from activity_log.');

    $remaining = DB::table('activity_log')->pluck('description')->all();

    // The 3 recent rows always survive.
    expect($remaining)->toContain('recent-1', 'recent-2', 'recent-3');

    // Among the 15 old rows, the 10 most recent (closest to the retention cutoff: old-90
    // through old-99) must survive as the intended buffer.
    for ($daysAgo = 90; $daysAgo < 100; $daysAgo++) {
        expect($remaining)->toContain("old-{$daysAgo}");
    }

    // The 5 oldest (old-100 through old-104) must actually be deleted.
    for ($daysAgo = 100; $daysAgo < 105; $daysAgo++) {
        expect($remaining)->not->toContain("old-{$daysAgo}");
    }
});

it('does not delete anything when --yes is omitted, even though the count is reported', function () {
    for ($i = 0; $i < 15; $i++) {
        seedOldActivityLogRow("old-{$i}", 90 + $i);
    }

    ob_start();
    $this->artisan('cleanup:database', ['--keep-days' => 60]);
    $output = ob_get_clean();

    expect($output)->toContain('Delete 5 entries from activity_log.');
    expect(DB::table('activity_log')->count())->toBe(15);
});

it('applies the same fix to application_deployment_queues', function () {
    $application = Application::factory()->create();

    for ($i = 0; $i < 15; $i++) {
        DB::table('application_deployment_queues')->insert([
            'application_id' => $application->id,
            'deployment_uuid' => (string) Str::uuid(),
            'created_at' => now()->subDays(90 + $i),
            'updated_at' => now()->subDays(90 + $i),
        ]);
    }

    ob_start();
    $this->artisan('cleanup:database', ['--yes' => true, '--keep-days' => 60]);
    $output = ob_get_clean();

    expect($output)->toContain('Delete 5 entries from application_deployment_queues.');
    expect(DB::table('application_deployment_queues')->count())->toBe(10);
});
