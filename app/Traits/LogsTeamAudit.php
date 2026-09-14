<?php

declare(strict_types=1);

namespace App\Traits;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * The activity_log table is already used elsewhere in this codebase to store raw SSH/deployment
 * command output under Spatie's default log name (see bootstrap/helpers/remoteProcess.php) - a
 * completely different semantic use from a change-history audit trail. Every model using this
 * trait logs under the distinct 'team-audit' log name instead, so the two streams never mix:
 * ActivityController's SSH-output polling filters by properties (server_uuid/type_uuid), while
 * TeamController::auditLog() filters by log name via Activity::inLog('team-audit').
 *
 * auditLogAttributes() is an explicit allow-list, not a deny-list - several models using this
 * trait have real secrets among their fillable fields (webhook secrets, basic-auth passwords,
 * database passwords), and an allow-list can't silently start logging a newly added secret field
 * the way a deny-list would if someone forgot to extend it.
 */
trait LogsTeamAudit
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('team-audit')
            ->logOnly($this->auditLogAttributes())
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * team_id isn't a real activity_log column - stored in properties instead, matching the
     * convention remote_process() already established for scoping SSH activity to a team.
     */
    public function tapActivity(Activity $activity, string $eventName): void
    {
        $activity->properties = $activity->properties->put('team_id', $this->resolveAuditTeamId());
    }

    /**
     * Deliberately just team_id, not a team()-based fallback - team() isn't a consistent
     * contract across models in this codebase (Server::team() is a real BelongsTo relation
     * method, returning the relation builder rather than a Team, while other models' team()
     * already resolves and returns the actual ?Team). A model without a team_id column should
     * override this method itself, using whatever its own team() actually returns, rather than
     * this trait guessing at a shape that varies per model.
     */
    protected function resolveAuditTeamId(): ?int
    {
        // team_id isn't cast to int on every model that has it (e.g. Server) - found live via
        // the API create path, which stores it as a string from token data (getTeamIdFromToken()),
        // crashing this method's strict ?int contract with a TypeError. Cast explicitly rather
        // than trust the raw attribute type, since this value both feeds a JSON properties column
        // and later gets compared against a real int (Team::$id) in TeamController::auditLog()'s
        // own where('properties->team_id', $team->id) filter - a silent string/int mismatch there
        // would break that filter too, not just crash here.
        //
        // The null check is real for models whose team_id is nullable (e.g. Application, before
        // an environment is assigned) even though PHPStan proves it dead for Server specifically
        // (declared non-nullable there) - a shared trait method can't be narrowed per consumer.
        // @phpstan-ignore notIdentical.alwaysTrue
        return $this->team_id !== null ? (int) $this->team_id : null;
    }

    /**
     * @return array<int, string>
     */
    abstract protected function auditLogAttributes(): array;
}
