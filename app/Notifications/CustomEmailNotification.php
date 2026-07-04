<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class CustomEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public array $backoff = [10, 20, 30, 40, 50];

    public int $tries = 5;

    public int $maxExceptions = 5;
}
