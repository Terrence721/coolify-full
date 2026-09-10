<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\PersonalAccessToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;
use Laravel\Telescope\TelescopeServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (App::isLocal()) {
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    public function boot(): void
    {
        $this->configureCommands();
        $this->configureModels();
        $this->configurePasswords();
        $this->configureSanctumModel();
        $this->configureGitHubHttp();
        $this->configureBunnyCdnHttp();
        $this->configureBroadcastingSecrets();
    }

    private function configureCommands(): void
    {
        if (App::isProduction()) {
            DB::prohibitDestructiveCommands();
        }
    }

    /**
     * scripts/install.sh generates real secrets for these on every standard install, so this
     * only fires for a deploy that skipped it (e.g. docker-compose.prod.yml run directly) or hit
     * a partial install failure - refuse to boot rather than silently sign WebSocket auth tokens
     * with a fallback value that's permanently documented in this public repo.
     */
    private function configureBroadcastingSecrets(): void
    {
        if (! App::isProduction()) {
            return;
        }

        $insecureDefault = 'coolify';
        $vars = [
            'PUSHER_APP_KEY' => config('broadcasting.connections.pusher.key'),
            'PUSHER_APP_SECRET' => config('broadcasting.connections.pusher.secret'),
            'PUSHER_APP_ID' => config('broadcasting.connections.pusher.app_id'),
        ];

        $stillDefault = array_keys(array_filter($vars, fn ($value) => $value === $insecureDefault));

        if ($stillDefault !== []) {
            throw new RuntimeException(
                'Refusing to boot in production: '.implode(', ', $stillDefault).
                ' still resolve to the insecure default broadcasting secret ("coolify"). '.
                'Set real values (scripts/install.sh does this automatically on a standard install) before deploying.'
            );
        }
    }

    private function configureModels(): void
    {
        // Disabled because it's causing issues with the application
        // Model::shouldBeStrict();
    }

    private function configurePasswords(): void
    {
        Password::defaults(function () {
            return App::isProduction()
                ? Password::min(8)
                    ->mixedCase()
                    ->letters()
                    ->numbers()
                    ->symbols()
                    ->uncompromised()
                : Password::min(8)->letters();
        });
    }

    private function configureSanctumModel(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }

    private function configureGitHubHttp(): void
    {
        Http::macro('GitHub', function (string $api_url, ?string $github_access_token = null) {
            if ($github_access_token) {
                return Http::withHeaders([
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'Accept' => 'application/vnd.github.v3+json',
                    'Authorization' => "Bearer $github_access_token",
                ])->baseUrl($api_url);
            } else {
                return Http::withHeaders([
                    'Accept' => 'application/vnd.github.v3+json',
                ])->baseUrl($api_url);
            }
        });
    }

    private function configureBunnyCdnHttp(): void
    {
        PendingRequest::macro('storage', function (string $fileName) {
            $headers = [
                'AccessKey' => config('constants.bunny.storage_api_key'),
                'Accept' => 'application/json',
                'Content-Type' => 'application/octet-stream',
            ];
            $fileStream = fopen($fileName, 'r');
            $file = fread($fileStream, filesize($fileName));
            Log::info('Uploading to BunnyCDN: '.$fileName);

            return Http::baseUrl('https://storage.bunnycdn.com')->withHeaders($headers)->withBody($file)->throw();
        });

        PendingRequest::macro('purge', function (string $url) {
            $headers = [
                'AccessKey' => config('constants.bunny.api_key'),
                'Accept' => 'application/json',
            ];
            Log::info('Purging BunnyCDN: '.$url);

            return Http::withHeaders($headers)->get('https://api.bunny.net/purge', [
                'url' => $url,
                'async' => false,
            ]);
        });
    }
}
