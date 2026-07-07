<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\Database\StartClickhouse;
use App\Actions\Database\StartDragonfly;
use App\Actions\Database\StartKeydb;
use App\Actions\Database\StartMariadb;
use App\Actions\Database\StartMongodb;
use App\Actions\Database\StartMysql;
use App\Actions\Database\StartPostgresql;
use App\Actions\Database\StartRedis;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Illuminate\Database\Eloquent\Model;

/**
 * Single source of truth for the 8 standalone database engines. Before this
 * existed, adding a 9th engine meant editing 30+ places across models, jobs,
 * policies, Livewire components, Blade views, and console commands that each
 * hardcoded their own copy of "all 8 engines". Call sites that need "all
 * engines" (or "the engine matching this model/type") should go through here
 * instead of re-listing the 8 classes.
 *
 * `supportsBackup`/`supportsImport` mirror each model's own
 * `isBackupSolutionAvailable()` (from HasStandaloneDatabaseCommon) and the
 * import feature's supported set — keep those in sync if either changes.
 */
final class DatabaseEngineRegistry
{
    /** @var array<int, DatabaseEngine>|null */
    private static ?array $engines = null;

    /** @return array<int, DatabaseEngine> */
    public static function all(): array
    {
        return self::$engines ??= [
            new DatabaseEngine(
                type: 'postgresql',
                modelClass: StandalonePostgresql::class,
                relationName: 'postgresqls',
                displayName: 'PostgreSQL',
                description: 'Robust, advanced open-source database',
                startActionClass: StartPostgresql::class,
                supportsBackup: true,
                supportsImport: true,
                volumeNamePrefix: 'postgres-data-',
            ),
            new DatabaseEngine(
                type: 'redis',
                modelClass: StandaloneRedis::class,
                relationName: 'redis',
                displayName: 'Redis',
                description: 'In-memory data structure store',
                startActionClass: StartRedis::class,
                supportsBackup: false,
                supportsImport: false,
                volumeNamePrefix: 'redis-data-',
            ),
            new DatabaseEngine(
                type: 'mongodb',
                modelClass: StandaloneMongodb::class,
                relationName: 'mongodbs',
                displayName: 'MongoDB',
                description: 'Document-oriented NoSQL database',
                startActionClass: StartMongodb::class,
                supportsBackup: true,
                supportsImport: true,
                // Note: StandaloneMongodb's own created() hook actually provisions
                // "mongodb-configdb-{uuid}" and "mongodb-db-{uuid}" volumes, not this
                // prefix — ResourceOperations::cloneTo()'s volume-rename never matched
                // Mongo's real volumes even before this registry existed. Preserved
                // as-is rather than silently changed while fixing the OCP shape.
                volumeNamePrefix: 'mongodb-data-',
            ),
            new DatabaseEngine(
                type: 'mysql',
                modelClass: StandaloneMysql::class,
                relationName: 'mysqls',
                displayName: 'MySQL',
                description: 'Popular open-source relational database',
                startActionClass: StartMysql::class,
                supportsBackup: true,
                supportsImport: true,
                volumeNamePrefix: 'mysql-data-',
            ),
            new DatabaseEngine(
                type: 'mariadb',
                modelClass: StandaloneMariadb::class,
                relationName: 'mariadbs',
                displayName: 'MariaDB',
                description: 'Community-developed fork of MySQL',
                startActionClass: StartMariadb::class,
                supportsBackup: true,
                supportsImport: true,
                volumeNamePrefix: 'mariadb-data-',
            ),
            new DatabaseEngine(
                type: 'keydb',
                modelClass: StandaloneKeydb::class,
                relationName: 'keydbs',
                displayName: 'KeyDB',
                description: 'High-performance Redis alternative',
                startActionClass: StartKeydb::class,
                supportsBackup: false,
                supportsImport: false,
                volumeNamePrefix: 'keydb-data-',
            ),
            new DatabaseEngine(
                type: 'dragonfly',
                modelClass: StandaloneDragonfly::class,
                relationName: 'dragonflies',
                displayName: 'Dragonfly',
                description: 'Modern in-memory datastore',
                startActionClass: StartDragonfly::class,
                supportsBackup: false,
                supportsImport: false,
                volumeNamePrefix: 'dragonfly-data-',
            ),
            new DatabaseEngine(
                type: 'clickhouse',
                modelClass: StandaloneClickhouse::class,
                relationName: 'clickhouses',
                displayName: 'Clickhouse',
                description: 'Column-oriented database for analytics',
                startActionClass: StartClickhouse::class,
                supportsBackup: false,
                supportsImport: false,
                volumeNamePrefix: 'clickhouse-data-',
            ),
        ];
    }

    /** @return array<int, string> e.g. ['postgresql', 'mysql', ...] */
    public static function types(): array
    {
        return array_map(fn (DatabaseEngine $e) => $e->type, self::all());
    }

    /** @return array<int, string> e.g. [StandalonePostgresql::class, ...] */
    public static function modelClasses(): array
    {
        return array_map(fn (DatabaseEngine $e) => $e->modelClass, self::all());
    }

    /** @return array<int, string> e.g. ['postgresqls', 'mysqls', ...] */
    public static function relationNames(): array
    {
        return array_map(fn (DatabaseEngine $e) => $e->relationName, self::all());
    }

    /** @return array<int, string> types whose engines don't support the import feature, e.g. ['redis', 'keydb', ...] */
    public static function unsupportedImportTypes(): array
    {
        return array_values(array_map(
            fn (DatabaseEngine $e) => $e->type,
            array_filter(self::all(), fn (DatabaseEngine $e) => ! $e->supportsImport)
        ));
    }

    /** @return array<string, string> type => modelClass, e.g. STANDALONE_DATABASE_MODELS shape */
    public static function typeToModelMap(): array
    {
        $map = [];
        foreach (self::all() as $engine) {
            $map[$engine->type] = $engine->modelClass;
        }

        return $map;
    }

    public static function forType(string $type): ?DatabaseEngine
    {
        foreach (self::all() as $engine) {
            if ($engine->type === $type) {
                return $engine;
            }
        }

        return null;
    }

    public static function forModelClass(string $modelClass): ?DatabaseEngine
    {
        $modelClass = ltrim($modelClass, '\\');

        foreach (self::all() as $engine) {
            if ($engine->modelClass === $modelClass) {
                return $engine;
            }
        }

        return null;
    }

    public static function forInstance(Model|string $instance): ?DatabaseEngine
    {
        $morphClass = is_string($instance) ? $instance : $instance->getMorphClass();

        return self::forModelClass($morphClass);
    }
}
