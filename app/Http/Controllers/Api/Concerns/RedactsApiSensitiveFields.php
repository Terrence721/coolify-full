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
 *
 * hideApiFields() is the makeHidden-only half, split out for callers that
 * need to redact several models across several relations before one final
 * serializeApiResponse() pass over the whole response (ResourcesController's
 * mixed-type /resources endpoint, ProjectController's environment_details())
 * rather than serializing each model individually.
 */
trait RedactsApiSensitiveFields
{
    /**
     * @param  array<int, string>  $alwaysHidden
     * @param  array<int, string>  $sensitiveHidden
     */
    private function hideApiFields(mixed $model, array $alwaysHidden, array $sensitiveHidden): void
    {
        $model->makeHidden($alwaysHidden);

        if (request()->attributes->get('can_read_sensitive', false) === false) {
            $model->makeHidden($sensitiveHidden);
        }
    }

    /**
     * @param  array<int, string>  $alwaysHidden
     * @param  array<int, string>  $sensitiveHidden
     */
    private function redactApiFields(mixed $model, array $alwaysHidden, array $sensitiveHidden): mixed
    {
        $this->hideApiFields($model, $alwaysHidden, $sensitiveHidden);

        return serializeApiResponse($model);
    }
}
