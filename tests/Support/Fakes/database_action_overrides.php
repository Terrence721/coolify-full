<?php

declare(strict_types=1);

// database_configuration_dir(), defaultDatabaseLabels(), generate_fluentd_configuration(),
// convertDockerRunToCompose(), generateCustomDockerRunOptionsForDatabases(),
// generate_readme_file(), remote_process(), database_proxy_dir(), isDev() and
// instant_remote_process() are plain global helper functions (not container-resolved
// services), so app()->bind() cannot intercept them in tests. Declaring same-named
// functions inside the callee's namespace lets PHP resolve these unqualified calls here
// first, before falling back to the real global implementation.
//
// This file declares functions (not a class), so it isn't PSR-4 autoloadable — require
// it explicitly (require_once) from any test whose subject lives in App\Actions\Database
// and calls these helpers, then configure/reset Tests\Support\Fakes\DatabaseActionFake
// (and Tests\Support\Fakes\RemoteProcessFake, shared with the App\Actions\Application
// overrides) per test.

namespace App\Actions\Database;

use Tests\Support\Fakes\DatabaseActionFake;
use Tests\Support\Fakes\RemoteProcessFake;

if (! function_exists(__NAMESPACE__.'\database_configuration_dir')) {
    function database_configuration_dir(): string
    {
        return DatabaseActionFake::$configurationDir;
    }
}

if (! function_exists(__NAMESPACE__.'\defaultDatabaseLabels')) {
    function defaultDatabaseLabels($database)
    {
        return DatabaseActionFake::$defaultLabels;
    }
}

if (! function_exists(__NAMESPACE__.'\generate_fluentd_configuration')) {
    function generate_fluentd_configuration(): array
    {
        return DatabaseActionFake::$fluentdConfiguration;
    }
}

if (! function_exists(__NAMESPACE__.'\convertDockerRunToCompose')) {
    function convertDockerRunToCompose(?string $custom_docker_run_options = null)
    {
        return DatabaseActionFake::$customDockerRunOptions;
    }
}

if (! function_exists(__NAMESPACE__.'\generateCustomDockerRunOptionsForDatabases')) {
    function generateCustomDockerRunOptionsForDatabases($docker_run_options, $docker_compose, $container_name, $network)
    {
        return $docker_compose;
    }
}

if (! function_exists(__NAMESPACE__.'\generate_readme_file')) {
    function generate_readme_file(string $name, string $updated_at): string
    {
        return DatabaseActionFake::$readmeContent;
    }
}

if (! function_exists(__NAMESPACE__.'\remote_process')) {
    function remote_process(...$args)
    {
        DatabaseActionFake::$remoteProcessCalls[] = $args;

        return DatabaseActionFake::$remoteProcessOutput;
    }
}

if (! function_exists(__NAMESPACE__.'\database_proxy_dir')) {
    function database_proxy_dir(string $uuid): string
    {
        return DatabaseActionFake::$proxyDir."/$uuid/proxy";
    }
}

if (! function_exists(__NAMESPACE__.'\isDev')) {
    function isDev(): bool
    {
        return DatabaseActionFake::$isDev;
    }
}

if (! function_exists(__NAMESPACE__.'\instant_remote_process')) {
    function instant_remote_process(...$args)
    {
        RemoteProcessFake::$instantRemoteProcessCalls[] = $args;

        $throwError = $args[2] ?? true;
        if ($throwError !== false && RemoteProcessFake::$instantRemoteProcessException) {
            throw RemoteProcessFake::$instantRemoteProcessException;
        }

        return RemoteProcessFake::$output;
    }
}
