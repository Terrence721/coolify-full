<?php

declare(strict_types=1);

namespace App\Traits;

/**
 * Shared by the 8 App\Actions\Database\Start* actions. Both methods were byte-identical
 * across all of them — pure reads of $this->database->persistentStorages with no
 * side effects, unlike the surrounding handle() logic (SSL provisioning, docker-compose
 * assembly, engine-specific config files), which genuinely diverges per database engine
 * (different mount paths, conf-file handling, chown mechanisms, and in Postgresql's and
 * Redis's cases, side effects that persist attributes back to the model) and was left
 * un-unified rather than forcing a leaky abstraction over real behavioral differences.
 */
trait GeneratesLocalPersistentVolumes
{
    /** @return array<int, string> */
    private function generate_local_persistent_volumes(): array
    {
        $local_persistent_volumes = [];
        foreach ($this->database->persistentStorages as $persistentStorage) {
            if ($persistentStorage->host_path !== '' && $persistentStorage->host_path !== null) {
                $local_persistent_volumes[] = $persistentStorage->host_path.':'.$persistentStorage->mount_path;
            } else {
                $volume_name = $persistentStorage->name;
                $local_persistent_volumes[] = $volume_name.':'.$persistentStorage->mount_path;
            }
        }

        return $local_persistent_volumes;
    }

    /** @return array<string, array{name: string, external: bool}> */
    private function generate_local_persistent_volumes_only_volume_names(): array
    {
        $local_persistent_volumes_names = [];
        foreach ($this->database->persistentStorages as $persistentStorage) {
            if ($persistentStorage->host_path) {
                continue;
            }
            $name = $persistentStorage->name;
            $local_persistent_volumes_names[$name] = [
                'name' => $name,
                'external' => false,
            ];
        }

        return $local_persistent_volumes_names;
    }
}
