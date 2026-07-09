<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Spatie\Activitylog\Models\Activity;

/**
 * JSON polling endpoint backing ActivityLog.jsx — the React port of ActivityMonitor.php's
 * polling loop, scoped to what ServerNavbar.jsx's proxy-startup-log slide-over needs (this
 * migration's first use of ActivityMonitor from a React page; the original Livewire component
 * stays in place, still used by 9 other still-Livewire pages).
 */
class ActivityController extends Controller
{
    public function show(int $id): JsonResponse
    {
        $activity = Activity::find($id);

        if (! $activity || ! $this->ownedByCurrentTeam($activity)) {
            return response()->json(['output' => '', 'exitCode' => null, 'found' => false]);
        }

        return response()->json([
            'output' => RunRemoteProcess::decodeOutput($activity),
            'exitCode' => data_get($activity, 'properties.exitCode'),
            'found' => true,
        ]);
    }

    private function ownedByCurrentTeam(Activity $activity): bool
    {
        $currentTeamId = currentTeam()?->id;

        $activityTeamId = data_get($activity, 'properties.team_id');
        if ($activityTeamId !== null) {
            return (int) $activityTeamId === (int) $currentTeamId;
        }

        $serverUuid = data_get($activity, 'properties.server_uuid');
        if ($serverUuid) {
            $server = Server::query()->where('uuid', $serverUuid)->first();

            return $server && (int) $server->team_id === (int) $currentTeamId;
        }

        return false;
    }
}
