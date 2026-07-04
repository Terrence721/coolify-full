<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EncryptedArrayCast;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'Project model',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer'],
        'application_id' => ['type' => 'string'],
        'deployment_uuid' => ['type' => 'string'],
        'pull_request_id' => ['type' => 'integer'],
        'docker_registry_image_tag' => ['type' => 'string', 'nullable' => true],
        'configuration_hash' => ['type' => 'string', 'nullable' => true],
        'configuration_snapshot' => ['type' => 'object', 'nullable' => true],
        'configuration_diff' => ['type' => 'object', 'nullable' => true],
        'force_rebuild' => ['type' => 'boolean'],
        'commit' => ['type' => 'string'],
        'status' => ['type' => 'string'],
        'is_webhook' => ['type' => 'boolean'],
        'is_api' => ['type' => 'boolean'],
        'created_at' => ['type' => 'string'],
        'updated_at' => ['type' => 'string'],
        'logs' => ['type' => 'string'],
        'current_process_id' => ['type' => 'string'],
        'restart_only' => ['type' => 'boolean'],
        'git_type' => ['type' => 'string'],
        'server_id' => ['type' => 'integer'],
        'application_name' => ['type' => 'string'],
        'server_name' => ['type' => 'string'],
        'deployment_url' => ['type' => 'string'],
        'destination_id' => ['type' => 'string'],
        'only_this_server' => ['type' => 'boolean'],
        'rollback' => ['type' => 'boolean'],
        'commit_message' => ['type' => 'string'],
    ],
)]
/**
 * @property-read Application|null $application
 * @property int $id
 * @property string $application_id
 * @property string $deployment_uuid
 * @property int $pull_request_id
 * @property bool $force_rebuild
 * @property string $commit
 * @property string $status
 * @property bool $is_webhook
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $logs
 * @property string|null $current_process_id
 * @property bool $restart_only
 * @property string|null $git_type
 * @property int|null $server_id
 * @property string|null $application_name
 * @property string|null $server_name
 * @property string|null $deployment_url
 * @property string|null $destination_id
 * @property bool $only_this_server
 * @property bool $rollback
 * @property string|null $commit_message
 * @property bool $is_api
 * @property int|null $build_server_id
 * @property string|null $horizon_job_id
 * @property string|null $horizon_job_worker
 * @property Carbon|null $finished_at
 * @property string|null $docker_registry_image_tag
 * @property string|null $configuration_hash
 * @property array|null $configuration_snapshot
 * @property array|null $configuration_diff
 * @property-read mixed $server
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereApplicationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereApplicationName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereBuildServerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereCommit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereCommitMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereConfigurationDiff($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereConfigurationHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereConfigurationSnapshot($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereCurrentProcessId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereDeploymentUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereDeploymentUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereDestinationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereDockerRegistryImageTag($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereFinishedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereForceRebuild($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereGitType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereHorizonJobId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereHorizonJobWorker($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereIsApi($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereIsWebhook($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereLogs($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereOnlyThisServer($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue wherePullRequestId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereRestartOnly($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereRollback($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereServerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereServerName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationDeploymentQueue whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class ApplicationDeploymentQueue extends Model
{
    protected $fillable = [
        'application_id',
        'deployment_uuid',
        'pull_request_id',
        'docker_registry_image_tag',
        'configuration_hash',
        'configuration_snapshot',
        'configuration_diff',
        'force_rebuild',
        'commit',
        'status',
        'is_webhook',
        'logs',
        'current_process_id',
        'restart_only',
        'git_type',
        'server_id',
        'application_name',
        'server_name',
        'deployment_url',
        'destination_id',
        'only_this_server',
        'rollback',
        'commit_message',
        'is_api',
        'build_server_id',
        'horizon_job_id',
        'horizon_job_worker',
        'finished_at',
    ];

    /**
     * The configuration snapshot/diff hold full (decrypted on read) configuration,
     * including unlocked environment variable values. They are only meant for the
     * in-app diff modal (which redacts per role) and must never be serialized by the
     * API, so hide them globally as defense in depth.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'configuration_snapshot',
        'configuration_diff',
    ];

    protected $casts = [
        'pull_request_id' => 'integer',
        'finished_at' => 'datetime',
        'configuration_snapshot' => EncryptedArrayCast::class,
        'configuration_diff' => EncryptedArrayCast::class,
    ];

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function server(): Attribute
    {
        return Attribute::make(
            get: fn () => Server::find($this->server_id),
        );
    }

    public function setStatus(string $status)
    {
        $this->update([
            'status' => $status,
        ]);
    }

    public function getOutput(string $name): ?string
    {
        if (! $this->logs) {
            return null;
        }

        return collect(json_decode($this->logs))->where('name', $name)->first()?->output ?? null;
    }

    public function getHorizonJobStatus(): string
    {
        return getJobStatus($this->horizon_job_id);
    }

    public function commitMessage()
    {
        if (empty($this->commit_message) || is_null($this->commit_message)) {
            return null;
        }

        return str($this->commit_message)->value();
    }

    private function redactSensitiveInfo(string $text)
    {
        $text = remove_iip($text);

        $app = $this->application()->first();
        if (! $app) {
            return $text;
        }

        $lockedVars = collect([]);

        if ($app->environment_variables) {
            $lockedVars = $lockedVars->merge(
                $app->environment_variables
                    ->where('is_shown_once', true)
                    ->pluck('real_value', 'key')
                    ->filter()
            );
        }

        if ($this->pull_request_id !== 0 && $app->environment_variables_preview) {
            $lockedVars = $lockedVars->merge(
                $app->environment_variables_preview
                    ->where('is_shown_once', true)
                    ->pluck('real_value', 'key')
                    ->filter()
            );
        }

        foreach ($lockedVars as $key => $value) {
            $escapedValue = preg_quote($value, '/');
            $text = preg_replace(
                '/'.$escapedValue.'/',
                REDACTED,
                $text
            );
        }

        return $text;
    }

    public function addLogEntry(string $message, string $type = 'stdout', bool $hidden = false)
    {
        if ($type === 'error') {
            $type = 'stderr';
        }
        $message = str($message)->trim();
        if ($message->startsWith('╔')) {
            $message = "\n".$message;
        }
        $newLogEntry = [
            'command' => null,
            'output' => $this->redactSensitiveInfo($message),
            'type' => $type,
            'timestamp' => Carbon::now('UTC'),
            'hidden' => $hidden,
            'batch' => 1,
        ];

        // Use a transaction to ensure atomicity
        DB::transaction(function () use ($newLogEntry) {
            // Reload the model to get the latest logs
            $this->refresh();

            if ($this->logs) {
                $previousLogs = json_decode($this->logs, associative: true, flags: JSON_THROW_ON_ERROR);
                $newLogEntry['order'] = count($previousLogs) + 1;
                $previousLogs[] = $newLogEntry;
                $this->logs = json_encode($previousLogs, flags: JSON_THROW_ON_ERROR);
            } else {
                $this->logs = json_encode([$newLogEntry], flags: JSON_THROW_ON_ERROR);
            }

            // Save without triggering events to prevent potential race conditions
            $this->saveQuietly();
        });
    }
}
