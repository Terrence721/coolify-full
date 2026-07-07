<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use Illuminate\Support\Collection;

/**
 * Shared, resettable state backing the App\Actions\Database namespace's global-helper
 * overrides declared in tests/Support/Fakes/database_action_overrides.php.
 */
class DatabaseActionFake
{
    public static string $configurationDir = '/etc/coolify/databases';

    public static Collection $defaultLabels;

    public static array $fluentdConfiguration = [];

    public static array $customDockerRunOptions = [];

    public static string $readmeContent = 'README CONTENT';

    public static string $remoteProcessOutput = 'OK';

    /** @var array<int, array<int, mixed>> Each entry is the argument list of one remote_process() call. */
    public static array $remoteProcessCalls = [];

    public static string $proxyDir = '/etc/coolify/proxy';

    public static bool $isDev = false;

    public static function reset(): void
    {
        self::$configurationDir = '/etc/coolify/databases';
        self::$defaultLabels = collect(['label' => 'value']);
        self::$fluentdConfiguration = ['driver' => 'fluentd'];
        self::$customDockerRunOptions = [];
        self::$readmeContent = 'README CONTENT';
        self::$remoteProcessOutput = 'OK';
        self::$remoteProcessCalls = [];
        self::$proxyDir = '/etc/coolify/proxy';
        self::$isDev = false;
    }
}
