<?php

declare(strict_types=1);

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

// Refactored ✅
class Webhooks extends Component
{
    use AuthorizesRequests;

    public Application|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $resource;

    public ?string $deploywebhook;

    public ?string $githubManualWebhook;

    public ?string $gitlabManualWebhook;

    public ?string $bitbucketManualWebhook;

    public ?string $giteaManualWebhook;

    public ?string $githubManualWebhookSecret = null;

    public ?string $gitlabManualWebhookSecret = null;

    public ?string $bitbucketManualWebhookSecret = null;

    public ?string $giteaManualWebhookSecret = null;

    public function mount()
    {
        $this->deploywebhook = generateDeployWebhook($this->resource);

        $this->githubManualWebhookSecret = data_get($this->resource, 'manual_webhook_secret_github');
        $this->githubManualWebhook = generateGitManualWebhook($this->resource, 'github');

        $this->gitlabManualWebhookSecret = data_get($this->resource, 'manual_webhook_secret_gitlab');
        $this->gitlabManualWebhook = generateGitManualWebhook($this->resource, 'gitlab');

        $this->bitbucketManualWebhookSecret = data_get($this->resource, 'manual_webhook_secret_bitbucket');
        $this->bitbucketManualWebhook = generateGitManualWebhook($this->resource, 'bitbucket');

        $this->giteaManualWebhookSecret = data_get($this->resource, 'manual_webhook_secret_gitea');
        $this->giteaManualWebhook = generateGitManualWebhook($this->resource, 'gitea');
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->resource);
            $this->resource->update([
                'manual_webhook_secret_github' => $this->githubManualWebhookSecret,
                'manual_webhook_secret_gitlab' => $this->gitlabManualWebhookSecret,
                'manual_webhook_secret_bitbucket' => $this->bitbucketManualWebhookSecret,
                'manual_webhook_secret_gitea' => $this->giteaManualWebhookSecret,
            ]);
            $this->dispatch('success', 'Secret Saved.');
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }
}
