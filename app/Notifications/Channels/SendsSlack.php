<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

interface SendsSlack
{
    public function routeNotificationForSlack(): ?string;
}
