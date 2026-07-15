<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

/**
 * Shared skeleton behind each controller's own removeSensitiveData()/
 * removeSensitiveEnvData() wrapper: hide a fixed set of fields
 * unconditionally, hide a further set unless the token carries
 * read:sensitive, then serialize. Only the two field lists differ per
 * controller/model, so each controller keeps its own thin wrapper
 * method (preserving existing call sites) delegating here.
 */
trait RedactsApiSensitiveFields
{
    /**
     * @param  array<int, string>  $alwaysHidden
     * @param  array<int, string>  $sensitiveHidden
     */
    private function redactApiFields(mixed $model, array $alwaysHidden, array $sensitiveHidden): mixed
    {
        $model->makeHidden($alwaysHidden);

        if (request()->attributes->get('can_read_sensitive', false) === false) {
            $model->makeHidden($sensitiveHidden);
        }

        return serializeApiResponse($model);
    }
}
