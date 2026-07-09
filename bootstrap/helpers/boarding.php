<?php

declare(strict_types=1);

function allowedPathsForBoardingAccounts()
{
    return [
        'login',
        'logout',
        'force-password-reset',
        'two-factor-challenge',
        'livewire/update',
        'onboarding',
    ];
}

function allowedPathsForInvalidAccounts()
{
    return [
        'logout',
        'verify',
        'force-password-reset',
        'two-factor-challenge',
        'livewire/update',
    ];
}
