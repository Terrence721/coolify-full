<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

interface SendsTelegram
{
    public function routeNotificationForTelegram(): array;
}
