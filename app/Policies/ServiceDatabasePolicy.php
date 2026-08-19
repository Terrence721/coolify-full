<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ServiceDatabase;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ServiceDatabasePolicy
{
    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ServiceDatabase $serviceDatabase): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ServiceDatabase $serviceDatabase): bool
    {
        return Gate::forUser($user)->allows('update', $serviceDatabase->service);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ServiceDatabase $serviceDatabase): bool
    {
        return Gate::forUser($user)->allows('delete', $serviceDatabase->service);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ServiceDatabase $serviceDatabase): bool
    {
        return Gate::forUser($user)->allows('update', $serviceDatabase->service);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ServiceDatabase $serviceDatabase): bool
    {
        return Gate::forUser($user)->allows('delete', $serviceDatabase->service);
    }

    public function manageBackups(User $user, ServiceDatabase $serviceDatabase): bool
    {
        return Gate::forUser($user)->allows('update', $serviceDatabase->service);
    }

    /**
     * Determine whether the user can manage this resource's environment variables. Currently
     * unreachable - ServiceDatabase has no environment_variables() relation, so no
     * EnvironmentVariable row can have this as its resourceable today - but keeps this policy
     * consistent with its ServiceApplicationPolicy sibling and closes the same silent-Gate-denial
     * trap in advance if that relation is ever added.
     */
    public function manageEnvironment(User $user, ServiceDatabase $serviceDatabase): bool
    {
        return Gate::forUser($user)->allows('manageEnvironment', $serviceDatabase->service);
    }
}
