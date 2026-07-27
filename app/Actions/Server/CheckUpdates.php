<?php

declare(strict_types=1);

namespace App\Actions\Server;

use App\Models\Server;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

class CheckUpdates
{
    use AsAction;

    public string $jobQueue = 'high';

    /**
     * @return array<string, mixed>
     */
    public function handle(Server $server): array
    {
        $osId = 'unknown';
        $packageManager = null;

        try {
            if ($server->serverStatus() === false) {
                return [
                    'error' => 'Server is not reachable or not ready.',
                ];
            }

            // Try first method - using instant_remote_process
            $output = instant_remote_process(['cat /etc/os-release'], $server);

            // Parse os-release into an associative array
            $osInfo = [];
            foreach (explode("\n", $output) as $line) {
                if (empty($line)) {
                    continue;
                }
                if (strpos($line, '=') === false) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $osInfo[$key] = trim($value, '"');
            }

            // Get the main OS identifier
            $osId = $osInfo['ID'] ?? '';
            // $osIdLike = $osInfo['ID_LIKE'] ?? '';
            // $versionId = $osInfo['VERSION_ID'] ?? '';

            // Normalize OS types based on install.sh logic
            switch ($osId) {
                case 'manjaro':
                case 'manjaro-arm':
                case 'endeavouros':
                    $osType = 'arch';
                    break;
                case 'pop':
                case 'linuxmint':
                case 'zorin':
                    $osType = 'ubuntu';
                    break;
                case 'fedora-asahi-remix':
                    $osType = 'fedora';
                    break;
                default:
                    $osType = $osId;
            }

            // Determine package manager based on OS type
            $packageManager = match ($osType) {
                'arch' => 'pacman',
                'alpine' => 'apk',
                'ubuntu', 'debian', 'raspbian' => 'apt',
                'centos', 'fedora', 'rhel', 'ol', 'rocky', 'almalinux', 'amzn' => 'dnf',
                'sles', 'opensuse-leap', 'opensuse-tumbleweed' => 'zypper',
                default => null
            };

            switch ($packageManager) {
                case 'zypper':
                    $output = instant_remote_process(['LANG=C zypper -tx list-updates'], $server);
                    $out = $this->parseZypperOutput($output);
                    $out['osId'] = $osId;
                    $out['package_manager'] = $packageManager;

                    return $out;
                case 'dnf':
                    $output = instant_remote_process(['LANG=C dnf list -q --updates --refresh'], $server);
                    $out = $this->parseDnfOutput($output);
                    $out['osId'] = $osId;
                    $out['package_manager'] = $packageManager;

                    return $out;
                case 'apt':
                    instant_remote_process(['apt-get update -qq'], $server);
                    $output = instant_remote_process(['LANG=C apt list --upgradable 2>/dev/null'], $server);

                    $out = $this->parseAptOutput($output);
                    $out['osId'] = $osId;
                    $out['package_manager'] = $packageManager;

                    return $out;
                case 'pacman':
                    // Sync database first, then check for updates
                    // Using -Sy to refresh package database before querying available updates
                    instant_remote_process(['pacman -Sy'], $server);
                    $output = instant_remote_process(['pacman -Qu 2>/dev/null'], $server);
                    $out = $this->parsePacmanOutput($output);
                    $out['osId'] = $osId;
                    $out['package_manager'] = $packageManager;

                    return $out;
                case 'apk':
                    instant_remote_process(['apk update -q'], $server);
                    $output = instant_remote_process(['LANG=C apk list --upgradable 2>/dev/null'], $server);
                    $out = $this->parseApkOutput($output);
                    $out['osId'] = $osId;
                    $out['package_manager'] = $packageManager;

                    return $out;
                default:
                    return [
                        'osId' => $osId,
                        'error' => 'Unsupported package manager',
                        'package_manager' => $packageManager,
                    ];
            }
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in handle().', ['error' => $e->getMessage()]);

            return [
                'osId' => $osId,
                'package_manager' => $packageManager,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ];
        }
    }

    /**
     * @return array{total_updates: int, updates: array<int, array<string, string>>, error?: string}
     */
    private function parseZypperOutput(string $output): array
    {
        $updates = [];

        try {
            $xml = simplexml_load_string($output);
            if ($xml === false) {
                return [
                    'total_updates' => 0,
                    'updates' => [],
                    'error' => 'Failed to parse XML output',
                ];
            }

            // Navigate to the update-list node
            $updateList = $xml->xpath('//update-list/update');

            foreach ($updateList as $update) {
                $updates[] = [
                    'package' => (string) $update['name'],
                    'new_version' => (string) $update['edition'],
                    'current_version' => (string) $update['edition-old'],
                    'architecture' => (string) $update['arch'],
                    'repository' => (string) $update->source['alias'],
                    'summary' => (string) $update->summary,
                    'description' => (string) $update->description,
                ];
            }

            return [
                'total_updates' => count($updates),
                'updates' => $updates,
            ];
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in parseZypperOutput().', ['error' => $e->getMessage()]);

            return [
                'total_updates' => 0,
                'updates' => [],
                'error' => 'Error parsing zypper output: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array{total_updates: int, updates: array<int, array<string, string>>}
     */
    private function parseDnfOutput(string $output): array
    {
        $updates = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }

            // Split by multiple spaces/tabs and filter out empty elements
            $parts = array_values(array_filter(preg_split('/\s+/', $line)));

            if (count($parts) >= 3) {
                $package = $parts[0];
                $new_version = $parts[1];
                $repository = $parts[2];

                // Extract architecture from package name (e.g., "cloud-init.noarch" -> "noarch")
                $architecture = str_contains($package, '.') ? explode('.', $package)[1] : 'noarch';

                $updates[] = [
                    'package' => $package,
                    'new_version' => $new_version,
                    'repository' => $repository,
                    'architecture' => $architecture,
                    'current_version' => 'unknown', // DNF doesn't show current version in check-update output
                ];
            }
        }

        return [
            'total_updates' => count($updates),
            'updates' => $updates,
        ];
    }

    /**
     * @return array{total_updates: int, updates: array<int, array<string, string>>}
     */
    private function parseAptOutput(string $output): array
    {
        $updates = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            // Skip the "Listing... Done" line and empty lines
            if (empty($line) || str_contains($line, 'Listing...')) {
                continue;
            }

            // Example line: package/stable 2.0-1 amd64 [upgradable from: 1.0-1]
            if (preg_match('/^(.+?)\/(\S+)\s+(\S+)\s+(\S+)\s+\[upgradable from: (.+?)\]/', $line, $matches)) {
                $updates[] = [
                    'package' => $matches[1],
                    'repository' => $matches[2],
                    'new_version' => $matches[3],
                    'architecture' => $matches[4],
                    'current_version' => $matches[5],
                ];
            }
        }

        return [
            'total_updates' => count($updates),
            'updates' => $updates,
        ];
    }

    /**
     * @return array{total_updates: int, updates: array<int, array<string, string>>, unparsed_lines?: array<int, string>}
     */
    private function parsePacmanOutput(string $output): array
    {
        $updates = [];
        $unparsedLines = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }
            // Format: package current_version -> new_version
            if (preg_match('/^(\S+)\s+(\S+)\s+->\s+(\S+)$/', $line, $matches)) {
                $updates[] = [
                    'package' => $matches[1],
                    'current_version' => $matches[2],
                    'new_version' => $matches[3],
                    'architecture' => 'unknown',
                    'repository' => 'unknown',
                ];
            } else {
                // Log unmatched lines for debugging purposes
                $unparsedLines[] = $line;
            }
        }

        $result = [
            'total_updates' => count($updates),
            'updates' => $updates,
        ];

        // Include unparsed lines in the result for debugging if any exist
        if (! empty($unparsedLines)) {
            $result['unparsed_lines'] = $unparsedLines;
            Log::debug('Pacman output contained unparsed lines', [
                'unparsed_lines' => $unparsedLines,
            ]);
        }

        return $result;
    }

    /**
     * @return array{total_updates: int, updates: array<int, array<string, string>>, unparsed_lines?: array<int, string>}
     */
    private function parseApkOutput(string $output): array
    {
        $updates = [];
        $unparsedLines = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }

            // Example line: libcrypto3-3.1.8-r0 x86_64 {openssl} (Apache-2.0) [upgradable from: libcrypto3-3.1.0-r4]
            if (preg_match('/^(\S+)\s+(\S+)\s+\{(\S+)\}\s+\(.*?\)\s+\[upgradable from: (\S+)\]$/', $line, $matches)) {
                [$package, $newVersion] = $this->splitApkPackageAndVersion($matches[1]);
                $oldToken = $matches[4];
                $currentVersion = str_starts_with($oldToken, $package.'-') ? substr($oldToken, strlen($package) + 1) : 'unknown';

                $updates[] = [
                    'package' => $package,
                    'new_version' => $newVersion,
                    'current_version' => $currentVersion,
                    'architecture' => $matches[2],
                    'repository' => $matches[3],
                ];
            } else {
                $unparsedLines[] = $line;
            }
        }

        $result = [
            'total_updates' => count($updates),
            'updates' => $updates,
        ];

        if (! empty($unparsedLines)) {
            $result['unparsed_lines'] = $unparsedLines;
            Log::debug('Apk output contained unparsed lines', [
                'unparsed_lines' => $unparsedLines,
            ]);
        }

        return $result;
    }

    /**
     * Alpine's `apk list --upgradable` glues the package name and version together with no
     * unambiguous separator (e.g. `libcrypto3-3.1.8-r0`, `busybox-binsh-1.36.1-r7`), and
     * package names themselves may contain digits or multiple hyphens - splitting from the
     * left can't work reliably. Alpine's own version format is consistently
     * `<dotted-numeric-or-date>[-r<N>]`, so walking segments from the right and consuming
     * everything that looks version-shaped isolates the real package name. Verified against
     * real `apk list --upgradable` output from a genuinely outdated Alpine 3.18.0 container.
     *
     * @return array{0: string, 1: string}
     */
    private function splitApkPackageAndVersion(string $token): array
    {
        $parts = explode('-', $token);
        $versionParts = [];

        while (count($parts) > 1 && preg_match('/^(r\d|\d)/', end($parts))) {
            array_unshift($versionParts, array_pop($parts));
        }

        return [implode('-', $parts), implode('-', $versionParts)];
    }
}
