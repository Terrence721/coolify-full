<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\Service;
use App\Models\User;
use App\Support\DatabaseEngineRegistry;

class ResourceCreatePolicy
{
    /**
     * Non-database resource classes that can be created. Database engines are
     * appended from DatabaseEngineRegistry (see creatableResources()) instead
     * of being listed here, so a 9th engine doesn't require editing this class.
     */
    public const CREATABLE_RESOURCES = [
        Service::class,
        Application::class,
        GithubApp::class,
    ];

    /** @return array<int, string> */
    public static function creatableResources(): array
    {
        return [...self::CREATABLE_RESOURCES, ...DatabaseEngineRegistry::modelClasses()];
    }

    /**
     * Determine whether the user can create any resource.
     */
    public function createAny(User $user): bool
    {
        // return $user->isAdmin();
        return true;
    }

    /**
     * Determine whether the user can create a specific resource type.
     */
    public function create(User $user, string $resourceClass): bool
    {
        if (! in_array($resourceClass, self::creatableResources())) {
            return false;
        }

        //  return $user->isAdmin();
        return true;
    }

    /**
     * Authorize creation of all supported resource types.
     */
    public function authorizeAllResourceCreation(User $user): bool
    {
        return $this->createAny($user);
    }
}
