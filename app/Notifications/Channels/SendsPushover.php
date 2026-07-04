<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

interface SendsPushover
{
    public function routeNotificationForPushover(): array;
}
