<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\CloudflareTunnelChanged;
use App\Events\ProxyStatusChanged;
use App\Listeners\CloudflareTunnelChangedNotification;
use App\Listeners\ProxyStatusChangedNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use SocialiteProviders\Authentik\AuthentikExtendSocialite;
use SocialiteProviders\Azure\AzureExtendSocialite;
use SocialiteProviders\Clerk\ClerkExtendSocialite;
use SocialiteProviders\Discord\DiscordExtendSocialite;
use SocialiteProviders\Google\GoogleExtendSocialite;
use SocialiteProviders\Infomaniak\InfomaniakExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Zitadel\ZitadelExtendSocialite;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        CloudflareTunnelChanged::class => [
            CloudflareTunnelChangedNotification::class,
        ],
        ProxyStatusChanged::class => [
            ProxyStatusChangedNotification::class,
        ],
        SocialiteWasCalled::class => [
            AzureExtendSocialite::class.'@handle',
            AuthentikExtendSocialite::class.'@handle',
            ClerkExtendSocialite::class.'@handle',
            DiscordExtendSocialite::class.'@handle',
            GoogleExtendSocialite::class.'@handle',
            InfomaniakExtendSocialite::class.'@handle',
            ZitadelExtendSocialite::class.'@handle',
        ],
    ];

    public function boot(): void
    {
        //
    }

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
