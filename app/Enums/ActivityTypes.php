<?php

declare(strict_types=1);

namespace App\Enums;

enum ActivityTypes: string
{
    case INLINE = 'inline';
    case COMMAND = 'command';
}
