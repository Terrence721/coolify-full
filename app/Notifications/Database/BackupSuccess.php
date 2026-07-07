<?php

declare(strict_types=1);

namespace App\Notifications\Database;

use App\Contracts\StandaloneDatabaseInstance;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ServiceDatabase;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

class BackupSuccess extends CustomEmailNotification
{
    public string $name;

    public string $frequency;

    public function __construct(ScheduledDatabaseBackup $backup, public (Model&StandaloneDatabaseInstance)|ServiceDatabase $database, public string $database_name)
    {
        $this->onQueue('high');

        $this->name = $database->name;
        $this->frequency = $backup->frequency;
    }

    /**
     * @return array<int, class-string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('backup_success');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: Backup successfully done for {$this->database->name}");
        $mail->view('emails.backup-success', [
            'name' => $this->name,
            'database_name' => $this->database_name,
            'frequency' => $this->frequency,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':white_check_mark: Database backup successful',
            description: "Database backup for {$this->name} (db:{$this->database_name}) was successful.",
            color: DiscordMessage::successColor(),
        );

        $message->addField('Frequency', $this->frequency, true);

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toTelegram(): array
    {
        $message = "Coolify: Database backup for {$this->name} (db:{$this->database_name}) with frequency of {$this->frequency} was successful.";

        return [
            'message' => $message,
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'Database backup successful',
            level: 'success',
            message: "Database backup for {$this->name} (db:{$this->database_name}) was successful.<br/><br/><b>Frequency:</b> {$this->frequency}.",
        );
    }

    public function toSlack(): SlackMessage
    {
        $title = 'Database backup successful';
        $description = "Database backup for {$this->name} (db:{$this->database_name}) was successful.";

        $description .= "\n\n*Frequency:* {$this->frequency}";

        return new SlackMessage(
            title: $title,
            description: $description,
            color: SlackMessage::successColor()
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toWebhook(): array
    {
        $url = base_url().'/project/'.data_get($this->database, 'environment.project.uuid').'/environment/'.data_get($this->database, 'environment.uuid').'/database/'.$this->database->uuid;

        return [
            'success' => true,
            'message' => 'Database backup successful',
            'event' => 'backup_success',
            'database_name' => $this->name,
            'database_uuid' => $this->database->uuid,
            'database_type' => $this->database_name,
            'frequency' => $this->frequency,
            'url' => $url,
        ];
    }
}
