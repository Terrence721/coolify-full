<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

interface SendsDiscord
{
    public function routeNotificationForDiscord(): ?string;
}
