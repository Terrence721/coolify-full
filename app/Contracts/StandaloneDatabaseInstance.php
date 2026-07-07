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
 *
 * Note for static analysis: every implementor also has the members declared on
 * HasStandaloneDatabaseCommon (destination, uuid, name, status, is_public,
 * sslCertificates(), scheduledBackups(), tags(), persistentStorages(),
 * fileStorages(), environment_variables(), ...). PHPStan/Larastan does not
 * resolve @property/@method/@mixin PHPDoc declared on a plain interface (only on
 * classes), so those members show up as "undefined" through this type even
 * though every real instance has them; consumers commonly pair this interface
 * with a Model& intersection to get native Model methods (save, update,
 * replicate, ...) recognized, and the rest is accepted as a known gap tracked in
 * phpstan-baseline.neon rather than papered over with per-file suppressions.
 */
interface StandaloneDatabaseInstance
{
    public function type(): string;
}
