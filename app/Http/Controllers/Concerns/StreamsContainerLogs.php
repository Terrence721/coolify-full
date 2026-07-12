<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Helpers\SshMultiplexingHelper;
use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Process;

/**
 * Shared SSH `docker logs`/`docker service logs` fetching, ported from
 * App\Livewire\Project\Shared\GetLogs. Used by every converted page that streams a
 * single container's logs (Server\Proxy\Logs, Server\Sentinel\Logs so far).
 */
trait StreamsContainerLogs
{
    private function buildContainerLogsCommand(Server $server, string $container, int $numberOfLines, bool $showTimestamps): string
    {
        $base = $server->isSwarm() ? 'docker service logs' : 'docker logs';
        $timestampFlag = $showTimestamps ? ' -t' : '';
        $command = "{$base} -n {$numberOfLines}{$timestampFlag} {$container}";
        if ($server->isNonRoot()) {
            $command = parseCommandsByLineForSudo(collect($command), $server)[0];
        }

        return SshMultiplexingHelper::generateSshCommand($server, $command);
    }

    private function buildContainerLogsDownloadCommand(Server $server, string $container, bool $showTimestamps): string
    {
        $base = $server->isSwarm() ? 'docker service logs' : 'docker logs';
        $timestampFlag = $showTimestamps ? ' -t' : '';
        $command = "{$base}{$timestampFlag} {$container}";
        if ($server->isNonRoot()) {
            $command = parseCommandsByLineForSudo(collect($command), $server)[0];
        }

        return SshMultiplexingHelper::generateSshCommand($server, $command);
    }

    private function fetchContainerLogs(Server $server, string $container, int $numberOfLines, bool $showTimestamps): string
    {
        $sshCommand = $this->buildContainerLogsCommand($server, $container, $numberOfLines, $showTimestamps);

        $logChunks = [];
        Process::timeout(config('constants.ssh.command_timeout'))->run($sshCommand, function (string $type, string $output) use (&$logChunks) {
            $logChunks[] = removeAnsiColors($output);
        });
        $output = implode('', $logChunks);

        return $showTimestamps ? $this->sortLogLinesByTimestamp($output) : $output;
    }

    private function downloadContainerLogsResponse(Server $server, string $container, bool $showTimestamps, string $filenamePrefix): HttpResponse
    {
        $maxBytes = 50 * 1024 * 1024; // 50MB
        $sshCommand = $this->buildContainerLogsDownloadCommand($server, $container, $showTimestamps);

        $logChunks = [];
        $accumulatedBytes = 0;
        $truncated = false;
        Process::timeout(config('constants.ssh.command_timeout'))->run($sshCommand, function (string $type, string $output) use (&$logChunks, &$accumulatedBytes, &$truncated, $maxBytes) {
            if ($truncated) {
                return;
            }
            $output = removeAnsiColors($output);
            $outputBytes = strlen($output);
            if ($accumulatedBytes + $outputBytes > $maxBytes) {
                $remaining = $maxBytes - $accumulatedBytes;
                if ($remaining > 0) {
                    $logChunks[] = substr($output, 0, $remaining);
                }
                $truncated = true;

                return;
            }
            $logChunks[] = $output;
            $accumulatedBytes += $outputBytes;
        });

        $allLogs = implode('', $logChunks);
        if ($truncated) {
            $allLogs .= "\n\n[... Output truncated at 50MB limit ...]";
        }
        if ($showTimestamps) {
            $allLogs = $this->sortLogLinesByTimestamp($allLogs);
        }

        $content = sanitizeLogsForExport($allLogs);
        $filename = "{$filenamePrefix}-all-logs-".now()->format('Y-m-d-H-i-s').'.txt';

        return response($content, 200, [
            'Content-Type' => 'text/plain',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function sortLogLinesByTimestamp(string $output): string
    {
        return str($output)->split('/\n/')->sort(function ($a, $b) {
            $a = explode(' ', $a);
            $b = explode(' ', $b);

            return $a[0] <=> $b[0];
        })->join("\n");
    }

    /**
     * Parses raw docker logs output (optionally timestamp-prefixed) into structured line
     * objects, matching livewire/project/shared/get-logs.blade.php's own parsing.
     *
     * @return array<int, array{timestamp: string|null, line: string}>
     */
    private function parseContainerLogLines(string $rawOutput, Server $server): array
    {
        $serverTimezone = getServerTimezone($server);

        return collect(explode("\n", $rawOutput))
            ->filter(fn (string $line) => trim($line) !== '')
            ->map(function (string $line) use ($serverTimezone) {
                $timestamp = null;
                $content = $line;
                if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d+))?Z?\s(.*)$/', $line, $matches)) {
                    $content = $matches[3];
                    $carbonTs = Carbon::parse($matches[1], 'UTC');
                    try {
                        $carbonTs->setTimezone($serverTimezone);
                    } catch (\Exception) {
                        // keep UTC
                    }
                    $timestamp = $carbonTs->format('Y-M-d H:i:s');
                }

                return ['timestamp' => $timestamp, 'line' => $content];
            })
            ->values()
            ->all();
    }
}
