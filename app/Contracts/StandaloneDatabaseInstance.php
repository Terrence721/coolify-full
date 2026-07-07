<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Implemented by all 8 Standalone* database engine models (Postgresql, Mysql,
 * Mariadb, Mongodb, Redis, Keydb, Dragonfly, Clickhouse). Lets call sites that
 * only need "any standalone database" (policies, action signatures, instanceof
 * checks) depend on this single contract instead of an 8-way union type or an
 * 8-way instanceof/switch chain — adding a 9th engine then only means having its
 * model implement this interface, not editing every call site.
 */
interface StandaloneDatabaseInstance
{
    public function type(): string;
}
