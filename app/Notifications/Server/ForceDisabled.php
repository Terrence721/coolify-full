<?php

declare(strict_types=1);

namespace App\Notifications\Server;

use App\Models\Server;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class ForceDisabled extends CustomEmailNotification
{
    public function __construct(public Server $server)
    {
        $this->onQueue('high');
    }

    /**
     * @return array<int, class-string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('server_force_disabled');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: Server ({$this->server->name}) disabled - server limit exceeded!");
        $mail->view('emails.server-force-disabled', [
            'name' => $this->server->name,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':cross_mark: Server disabled',
            description: "Server ({$this->server->name}) disabled - server limit exceeded!",
            color: DiscordMessage::errorColor(),
        );

        $message->addField('Action required', "Please contact your instance administrator to increase your team's server limit.");

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toTelegram(): array
    {
        return [
            'message' => "Coolify: Server ({$this->server->name}) disabled - server limit exceeded!\n All automations and integrations are stopped.\nPlease contact your instance administrator to increase your team's server limit.",
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'Server disabled',
            level: 'error',
            message: "Server ({$this->server->name}) disabled - server limit exceeded!\n All automations and integrations are stopped.<br/>Please contact your instance administrator to increase your team's server limit.",
        );
    }

    public function toSlack(): SlackMessage
    {
        $title = 'Server disabled';
        $description = "Server ({$this->server->name}) disabled - server limit exceeded!\n";
        $description .= "All automations and integrations are stopped.\n\n";
        $description .= "Please contact your instance administrator to increase your team's server limit.";

        return new SlackMessage(
            title: $title,
            description: $description,
            color: SlackMessage::errorColor()
        );
    }
}
