<?php

namespace App\Notifications\Channels;

use App\Jobs\SendMessageToPushoverJob;
use Illuminate\Notifications\Notification;

class PushoverChannel
{
    public function send(SendsPushover $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toPushover')) {
            return;
        }

        $message = $notification->toPushover();
        $pushoverSettings = data_get($notifiable, 'pushoverNotificationSettings');

        if (! $pushoverSettings || ! $pushoverSettings->isEnabled() || ! $pushoverSettings->pushover_user_key || ! $pushoverSettings->pushover_api_token) {
            return;
        }

        SendMessageToPushoverJob::dispatch($message, $pushoverSettings->pushover_api_token, $pushoverSettings->pushover_user_key);
    }
}
