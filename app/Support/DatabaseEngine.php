<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Immutable descriptor for one of the 8 standalone database engines. Built by
 * DatabaseEngineRegistry — see that class for why this exists.
 */
final class DatabaseEngine
{
    public function __construct(
        public readonly string $type,
        public readonly string $modelClass,
        public readonly string $relationName,
        public readonly string $displayName,
        public readonly string $description,
        public readonly string $startActionClass,
        public readonly bool $supportsBackup,
        public readonly bool $supportsImport,
        public readonly string $volumeNamePrefix,
    ) {}
}
