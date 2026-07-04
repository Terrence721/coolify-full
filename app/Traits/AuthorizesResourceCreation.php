<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

trait AuthorizesResourceCreation
{
    use AuthorizesRequests;

    /**
     * Authorize creation of all supported resources.
     *
     * @throws AuthorizationException
     */
    protected function authorizeResourceCreation(): void
    {
        $this->authorize('createAnyResource');
    }
}
