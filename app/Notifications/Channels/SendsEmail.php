<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

interface SendsEmail
{
    public function getRecipients(): array;
}
