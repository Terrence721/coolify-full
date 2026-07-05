<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

interface SendsEmail
{
    /**
     * @return array<int, string>
     */
    public function getRecipients(): array;
}
