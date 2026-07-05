<?php

declare(strict_types=1);

namespace App\Notifications\TransactionalEmails;

use App\Notifications\Channels\EmailChannel;
use App\Notifications\CustomEmailNotification;
use Illuminate\Notifications\Messages\MailMessage;

class Test extends CustomEmailNotification
{
    public bool $isTestNotification = true;

    public function __construct(public string $emails, public bool $isTransactionalEmail = true)
    {
        $this->onQueue('high');
    }

    /**
     * @return array<int, class-string>
     */
    public function via(): array
    {
        return [EmailChannel::class];
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject('Coolify: Test Email');
        $mail->view('emails.test');

        return $mail;
    }
}
