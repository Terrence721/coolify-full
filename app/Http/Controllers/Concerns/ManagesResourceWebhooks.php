<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * React port of App\Livewire\Project\Shared\Webhooks — the read-only deploy webhook URL,
 * shared by databases (Phase 54) and services (Phase 55), joined by Application on its third
 * consumer (Phase 66). The manual Git webhook secrets section (GitHub/GitLab/Bitbucket/Gitea)
 * is genuinely Application-only, not just under-generalized: `manual_webhook_secret_*` are
 * `applications`-table-only columns, and generateGitManualWebhook() returns null for any
 * resource whose morph class isn't Application — the original blade's
 * `@if ($resource->type() === 'application')` gate and the helper's own null-return agree.
 */
trait ManagesResourceWebhooks
{
    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function webhooksTabProps(Model $resource, array $parameters, string $routePrefix): array
    {
        $props = [
            'deployWebhook' => generateDeployWebhook($resource),
            'manualWebhooks' => null,
        ];

        if (! $resource instanceof Application) {
            return $props;
        }

        $usesOfficialGitApp = ! is_null($resource->source_id) && $resource->source_id !== 0;

        $props['manualWebhooks'] = [
            'usesOfficialGitApp' => $usesOfficialGitApp,
            'configUrl' => $resource->git_webhook,
            'canUpdate' => auth()->user()->can('update', $resource),
            'updateUrl' => route("{$routePrefix}.webhooks.update", $parameters),
            'providers' => $usesOfficialGitApp ? [] : collect(['github', 'gitlab', 'bitbucket', 'gitea'])
                ->mapWithKeys(fn (string $provider) => [$provider => [
                    'url' => generateGitManualWebhook($resource, $provider),
                    'secret' => data_get($resource, "manual_webhook_secret_{$provider}"),
                ]])
                ->all(),
        ];

        return $props;
    }

    private function updateManualWebhookSecrets(Request $request, Application $application): RedirectResponse
    {
        $this->authorize('update', $application);

        $validated = Validator::make($request->all(), [
            'githubManualWebhookSecret' => 'nullable|string',
            'gitlabManualWebhookSecret' => 'nullable|string',
            'bitbucketManualWebhookSecret' => 'nullable|string',
            'giteaManualWebhookSecret' => 'nullable|string',
        ])->validate();

        $application->update([
            'manual_webhook_secret_github' => $validated['githubManualWebhookSecret'] ?? null,
            'manual_webhook_secret_gitlab' => $validated['gitlabManualWebhookSecret'] ?? null,
            'manual_webhook_secret_bitbucket' => $validated['bitbucketManualWebhookSecret'] ?? null,
            'manual_webhook_secret_gitea' => $validated['giteaManualWebhookSecret'] ?? null,
        ]);

        return back()->with('success', 'Secret Saved.');
    }
}
