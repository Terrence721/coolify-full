<?php

declare(strict_types=1);

namespace App\Traits;

use App\Actions\Server\StartSentinel;
use App\Jobs\CheckAndStartSentinelJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Sentinel monitoring agent heartbeat/liveness checks, metrics/API-enabled
 * flags, and server metadata collection. Extracted from App\Models\Server.
 */
trait HasSentinel
{
    public function sentinelHeartbeat(bool $isReset = false): void
    {
        $this->sentinel_updated_at = $isReset ? now()->subMinutes(6000) : now();
        $this->save();
    }

    /**
     * Get the wait time for Sentinel to push before performing an SSH check.
     *
     * @return int The wait time in seconds.
     */
    public function waitBeforeDoingSshCheck(): int
    {
        $wait = $this->settings->sentinel_push_interval_seconds * 3;
        if ($wait < 120) {
            $wait = 120;
        }

        return $wait;
    }

    public function isSentinelLive(): bool
    {
        return Carbon::parse($this->sentinel_updated_at)->isAfter(now()->subSeconds($this->waitBeforeDoingSshCheck()));
    }

    public function isSentinelEnabled(): bool
    {
        return ($this->isMetricsEnabled() || $this->isServerApiEnabled()) && ! $this->isBuildServer();
    }

    public function isMetricsEnabled(): bool
    {
        return (bool) $this->settings->is_metrics_enabled;
    }

    public function isServerApiEnabled(): bool
    {
        return (bool) $this->settings->is_sentinel_enabled;
    }

    public function checkSentinel(): void
    {
        CheckAndStartSentinelJob::dispatch($this);
    }

    public function gatherServerMetadata(): ?array
    {
        if (! $this->isFunctional()) {
            return null;
        }

        try {
            $output = instant_remote_process([
                'echo "---PRETTY_NAME---" && grep PRETTY_NAME /etc/os-release | cut -d= -f2 | tr -d \'"\' && echo "---ARCH---" && uname -m && echo "---KERNEL---" && uname -r && echo "---CPUS---" && nproc && echo "---MEMORY---" && free -b | awk \'/Mem:/{print $2}\' && echo "---UPTIME_SINCE---" && uptime -s',
            ], $this, false);

            if (! $output) {
                return null;
            }

            $sections = [];
            $currentKey = null;
            foreach (explode("\n", trim($output)) as $line) {
                $line = trim($line);
                if (preg_match('/^---(\w+)---$/', $line, $m)) {
                    $currentKey = $m[1];
                } elseif ($currentKey) {
                    $sections[$currentKey] = $line;
                }
            }

            $metadata = [
                'os' => $sections['PRETTY_NAME'] ?? 'Unknown',
                'arch' => $sections['ARCH'] ?? 'Unknown',
                'kernel' => $sections['KERNEL'] ?? 'Unknown',
                'cpus' => (int) ($sections['CPUS'] ?? 0),
                'memory_bytes' => (int) ($sections['MEMORY'] ?? 0),
                'uptime_since' => $sections['UPTIME_SINCE'] ?? null,
                'collected_at' => now()->toIso8601String(),
            ];

            $this->update(['server_metadata' => $metadata]);

            return $metadata;
        } catch (\Throwable $e) {
            Log::debug('Failed to gather server metadata', [
                'server_id' => $this->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function restartSentinel(?string $customImage = null, bool $async = true): mixed
    {
        try {
            if ($async) {
                StartSentinel::dispatch($this, true, null, $customImage);
            } else {
                StartSentinel::run($this, true, null, $customImage);
            }
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in restartSentinel().', ['error' => $e->getMessage()]);

            return handleError($e);
        }

        return null;
    }
}
