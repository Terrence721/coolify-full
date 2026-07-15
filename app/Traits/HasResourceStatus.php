<?php

declare(strict_types=1);

namespace App\Traits;

/**
 * Shared isRunning()/isExited() status-string checks for Application and
 * Service. Extracted from both models, which were checking the exact same
 * thing (str($this->status)->startsWith/contains('running'|'exited')) with a
 * minor, behavior-preserving string-method difference — standardized here on
 * contains(), which is safe for Application's own "status:health" format
 * too, since "running"/"exited" is always a strict prefix of it.
 *
 * Application's own status()/serverStatus() (a real Attribute cast on the
 * `status` DB column, with multi-server "degraded" aggregation) and
 * Service's getStatusAttribute() (aggregating container status via
 * ContainerStatusAggregator) are unrelated to this trait and stay on their
 * respective models.
 */
trait HasResourceStatus
{
    public function isRunning()
    {
        return (bool) str($this->status)->contains('running');
    }

    public function isExited()
    {
        return (bool) str($this->status)->contains('exited');
    }
}
