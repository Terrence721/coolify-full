<?php

declare(strict_types=1);

namespace App\Notifications\TransactionalEmails;

use App\Models\InstanceSettings;
use Exception;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPassword extends Notification
{
    public static ?\Closure $createUrlCallback = null;

    public static ?\Closure $toMailCallback = null;

    public string $token;

    public InstanceSettings $settings;

    public function __construct(string $token, public bool $isTransactionalEmail = true)
    {
        $this->settings = instanceSettings();
        $this->token = $token;
    }

    public static function createUrlUsing(?\Closure $callback): void
    {
        static::$createUrlCallback = $callback;
    }

    public static function toMailUsing(?\Closure $callback): void
    {
        static::$toMailCallback = $callback;
    }

    public function via($notifiable): array
    {
        $type = set_transanctional_email_settings();
        if (blank($type)) {
            throw new Exception('No email settings found.');
        }

        return ['mail'];
    }

    public function toMail($notifiable): mixed
    {
        if (static::$toMailCallback) {
            return call_user_func(static::$toMailCallback, $notifiable, $this->token);
        }

        return $this->buildMailMessage($this->resetUrl($notifiable));
    }

    protected function buildMailMessage($url): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject('Coolify: Reset Password');
        $mail->view('emails.reset-password', ['url' => $url, 'count' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire')]);

        return $mail;
    }

    protected function resetUrl($notifiable): mixed
    {
        if (static::$createUrlCallback) {
            return call_user_func(static::$createUrlCallback, $notifiable, $this->token);
        }

        $path = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false);

        // Use server-side config (FQDN / public IP) instead of request host
        return rtrim(base_url(), '/').$path;
    }
}
