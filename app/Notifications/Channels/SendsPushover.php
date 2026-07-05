<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

interface SendsPushover
{
    /**
     * @return array{user: string|null, token: string|null}
     */
    public function routeNotificationForPushover(): array;
}
