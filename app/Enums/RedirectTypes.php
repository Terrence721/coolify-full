<?php

declare(strict_types=1);

namespace App\Enums;

enum RedirectTypes: string
{
    case BOTH = 'both';
    case WWW = 'www';
    case NON_WWW = 'non-www';
}
