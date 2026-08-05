<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class NotificationPolicy
{
    /**
     * Determine whether the user can view the notification settings.
     */
    public function view(User $user, Model $notificationSettings): bool
    {
        // Check if the notification settings belong to the user's current team
        if (! data_get($notificationSettings, 'team')) {
            return false;
        }

        return $user->teams()->where('teams.id', data_get($notificationSettings, 'team.id'))->exists();
    }

    /**
     * Determine whether the user can update the notification settings.
     */
    public function update(User $user, Model $notificationSettings): bool
    {
        $teamId = data_get($notificationSettings, 'team.id');

        if (! $teamId) {
            return false;
        }

        // Only owners and admins of the settings' own team can update it
        return $user->isAdminOfTeam($teamId);
    }

    /**
     * Determine whether the user can manage (create, update, delete) notification settings.
     */
    public function manage(User $user, Model $notificationSettings): bool
    {
        return $this->update($user, $notificationSettings);
    }

    /**
     * Determine whether the user can send test notifications.
     */
    public function sendTest(User $user, Model $notificationSettings): bool
    {
        return $this->update($user, $notificationSettings);
    }
}
