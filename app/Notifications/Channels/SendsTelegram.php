<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

interface SendsTelegram
{
    /**
     * @return array{token: string|null, chat_id: string|null}
     */
    public function routeNotificationForTelegram(): array;
}
