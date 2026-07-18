<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\StandaloneDatabaseInstance;
use App\Models\Application;

/**
 * Builds the props ConfigurationChecker.jsx needs, extracted from ProjectLogsController
 * once ProjectMetricsController needed the identical application-side logic (member-role
 * environment-value redaction) and a second, near-identical database-side shape.
 */
trait BuildsConfigurationCheckerProps
{
    /**
     * @return array<string, mixed>
     */
    private function applicationConfigurationCheckerProps(Application $application): array
    {
        $diff = $application->pendingDeploymentConfigurationDiff();
        $redactEnvironment = ! (bool) auth()->user()?->isAdmin();

        $array = $diff->toArray();
        if ($redactEnvironment) {
            $array['changes'] = collect($array['changes'])->map(function (array $change) {
                if (data_get($change, 'section') !== 'environment') {
                    return $change;
                }
                $change['old_display_value'] = data_get($change, 'old_display_value') === '-' ? '-' : '••••••••';
                $change['new_display_value'] = data_get($change, 'new_display_value') === '-' ? '-' : '••••••••';
                $change['old_full_value'] = null;
                $change['new_full_value'] = null;
                $change['expandable'] = false;

                return $change;
            })->all();
        }

        return [
            'isConfigurationChanged' => $diff->isChanged(),
            'isExited' => $application->isExited(),
            'configHash' => $application->config_hash,
            'diff' => $array,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseConfigurationCheckerProps(StandaloneDatabaseInstance $database): array
    {
        return [
            'isConfigurationChanged' => $database->isConfigurationChanged(),
            'isExited' => str($database->status)->startsWith('exited'),
            'configHash' => $database->config_hash,
            'diff' => [],
        ];
    }
}
