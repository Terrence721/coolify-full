<?php

declare(strict_types=1);

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\ApplicationDeploymentController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\DestinationController;
use App\Http\Controllers\ForcePasswordResetController;
use App\Http\Controllers\NotificationsDiscordController;
use App\Http\Controllers\NotificationsEmailController;
use App\Http\Controllers\NotificationsPushoverController;
use App\Http\Controllers\NotificationsSlackController;
use App\Http\Controllers\NotificationsTelegramController;
use App\Http\Controllers\NotificationsWebhookController;
use App\Http\Controllers\OauthController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SecurityApiTokensController;
use App\Http\Controllers\SecurityCloudInitScriptsController;
use App\Http\Controllers\SecurityCloudTokensController;
use App\Http\Controllers\SecurityPrivateKeyController;
use App\Http\Controllers\ServerAdvancedController;
use App\Http\Controllers\ServerCaCertificateController;
use App\Http\Controllers\ServerCloudflareTunnelController;
use App\Http\Controllers\ServerCloudProviderTokenController;
use App\Http\Controllers\ServerDeleteController;
use App\Http\Controllers\ServerDestinationsController;
use App\Http\Controllers\ServerDockerCleanupController;
use App\Http\Controllers\ServerLogDrainsController;
use App\Http\Controllers\ServerMetricsController;
use App\Http\Controllers\ServerPrivateKeyController;
use App\Http\Controllers\ServerProxyActionsController;
use App\Http\Controllers\ServerProxyController;
use App\Http\Controllers\ServerResourcesController;
use App\Http\Controllers\ServerSecurityPatchesController;
use App\Http\Controllers\ServerSecurityTerminalAccessController;
use App\Http\Controllers\ServerSentinelController;
use App\Http\Controllers\ServerSwarmController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SettingsEmailController;
use App\Http\Controllers\SettingsScheduledJobsController;
use App\Http\Controllers\SharedVariablesController;
use App\Http\Controllers\TagsController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TerminalController;
use App\Http\Controllers\UploadController;
use App\Livewire\Boarding\Index as BoardingIndex;
use App\Livewire\Dashboard;
use App\Livewire\Destination\Index as DestinationIndex;
use App\Livewire\Project\Application\Configuration as ApplicationConfiguration;
use App\Livewire\Project\Application\Deployment\Show as DeploymentShow;
use App\Livewire\Project\CloneMe as ProjectCloneMe;
use App\Livewire\Project\Database\Backup\Execution as DatabaseBackupExecution;
use App\Livewire\Project\Database\Backup\Index as DatabaseBackupIndex;
use App\Livewire\Project\Database\Configuration as DatabaseConfiguration;
use App\Livewire\Project\Edit as ProjectEdit;
use App\Livewire\Project\EnvironmentEdit;
use App\Livewire\Project\Index as ProjectIndex;
use App\Livewire\Project\Resource\Create as ResourceCreate;
use App\Livewire\Project\Resource\Index as ResourceIndex;
use App\Livewire\Project\Service\Configuration as ServiceConfiguration;
use App\Livewire\Project\Service\DatabaseBackups as ServiceDatabaseBackups;
use App\Livewire\Project\Service\Index as ServiceIndex;
use App\Livewire\Project\Shared\ExecuteContainerCommand;
use App\Livewire\Project\Shared\Logs;
use App\Livewire\Project\Show as ProjectShow;
use App\Livewire\Security\PrivateKey\Index as SecurityPrivateKeyIndex;
use App\Livewire\Server\Index as ServerIndex;
use App\Livewire\Server\Proxy\DynamicConfigurations as ProxyDynamicConfigurations;
use App\Livewire\Server\Proxy\Logs as ProxyLogs;
use App\Livewire\Server\Sentinel\Logs as SentinelLogs;
use App\Livewire\Server\Show as ServerShow;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Livewire\SettingsBackup;
use App\Livewire\SharedVariables\Environment\Show as EnvironmentSharedVariablesShow;
use App\Livewire\SharedVariables\Project\Show as ProjectSharedVariablesShow;
use App\Livewire\SharedVariables\Server\Show as ServerSharedVariablesShow;
use App\Livewire\SharedVariables\Team\Index as TeamSharedVariablesIndex;
use App\Livewire\Source\Github\Change as GitHubChange;
use App\Livewire\Storage\Index as StorageIndex;
use App\Livewire\Storage\Show as StorageShow;
use App\Livewire\Team\Member\Index as TeamMemberIndex;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ServiceDatabase;
use App\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

Route::post('/forgot-password', [Controller::class, 'forgot_password'])->name('password.forgot')->middleware('throttle:forgot-password');
Route::get('/realtime', [Controller::class, 'realtime_test'])->middleware('auth');
Route::get('/verify', [Controller::class, 'verify'])->middleware('auth')->name('verify.email');
Route::get('/email/verify/{id}/{hash}', [Controller::class, 'email_verify'])->middleware(['auth'])->name('verify.verify');
Route::middleware(['throttle:login'])->group(function () {
    Route::get('/auth/link', [Controller::class, 'link'])->name('auth.link');
});

Route::get('/auth/{provider}/redirect', [OauthController::class, 'redirect'])->name('auth.redirect');
Route::get('/auth/{provider}/callback', [OauthController::class, 'callback'])->name('auth.callback');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::middleware(['throttle:force-password-reset'])->group(function () {
        Route::get('/force-password-reset', [ForcePasswordResetController::class, 'edit'])->name('auth.force-password-reset');
        Route::put('/force-password-reset', [ForcePasswordResetController::class, 'update'])->name('auth.force-password-reset.update');
    });

    Route::get('/', Dashboard::class)->name('dashboard');
    Route::get('/admin', [AdminController::class, 'index'])->name('admin.index');
    Route::post('/admin/back', [AdminController::class, 'back'])->name('admin.back');
    Route::post('/admin/switch-user', [AdminController::class, 'switchUser'])->name('admin.switch-user');
    Route::get('/onboarding', BoardingIndex::class)->name('onboarding');

    Route::get('/settings', SettingsIndex::class)->name('settings.index');
    Route::get('/settings/advanced', [SettingsController::class, 'advanced'])->name('settings.advanced');
    Route::put('/settings/advanced', [SettingsController::class, 'advancedUpdate'])->name('settings.advanced.update');
    Route::post('/settings/advanced/enable-registration', [SettingsController::class, 'advancedEnableRegistration'])->name('settings.advanced.enable-registration');
    Route::post('/settings/advanced/disable-two-step-confirmation', [SettingsController::class, 'advancedDisableTwoStepConfirmation'])->name('settings.advanced.disable-two-step-confirmation');
    Route::get('/settings/updates', [SettingsController::class, 'updates'])->name('settings.updates');
    Route::put('/settings/updates', [SettingsController::class, 'updatesUpdate'])->name('settings.updates.update');
    Route::post('/settings/updates/check-manually', [SettingsController::class, 'updatesCheckManually'])->name('settings.updates.check-manually');

    Route::get('/settings/backup', SettingsBackup::class)->name('settings.backup');
    Route::get('/settings/email', [SettingsEmailController::class, 'edit'])->name('settings.email');
    Route::put('/settings/email/smtp', [SettingsEmailController::class, 'updateSmtp'])->name('settings.email.update-smtp');
    Route::put('/settings/email/resend', [SettingsEmailController::class, 'updateResend'])->name('settings.email.update-resend');
    Route::post('/settings/email/send-test', [SettingsEmailController::class, 'sendTest'])->name('settings.email.send-test');
    Route::get('/settings/oauth', [SettingsController::class, 'oauth'])->name('settings.oauth');
    Route::put('/settings/oauth', [SettingsController::class, 'oauthUpdate'])->name('settings.oauth.update');
    Route::get('/settings/scheduled-jobs', [SettingsScheduledJobsController::class, 'index'])->name('settings.scheduled-jobs');

    Route::get('/profile', [ProfileController::class, 'index'])->name('profile');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/email/request', [ProfileController::class, 'requestEmailChange'])->name('profile.email.request');
    Route::post('/profile/email/verify', [ProfileController::class, 'verifyEmailChange'])->name('profile.email.verify');
    Route::post('/profile/email/resend', [ProfileController::class, 'resendVerificationCode'])->name('profile.email.resend');
    Route::post('/profile/email/cancel', [ProfileController::class, 'cancelEmailChange'])->name('profile.email.cancel');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');
    Route::get('/profile/appearance', [ProfileController::class, 'appearance'])->name('profile.appearance');

    Route::prefix('tags')->group(function () {
        Route::get('/{tagName?}', [TagsController::class, 'show'])->name('tags.show');
        Route::post('/{tagName}/redeploy', [TagsController::class, 'redeploy'])->name('tags.redeploy');
    });

    Route::prefix('notifications')->group(function () {
        Route::get('/email', [NotificationsEmailController::class, 'edit'])->name('notifications.email');
        Route::put('/email', [NotificationsEmailController::class, 'update'])->name('notifications.email.update');
        Route::put('/email/smtp', [NotificationsEmailController::class, 'updateSmtp'])->name('notifications.email.update-smtp');
        Route::put('/email/resend', [NotificationsEmailController::class, 'updateResend'])->name('notifications.email.update-resend');
        Route::post('/email/send-test', [NotificationsEmailController::class, 'sendTest'])->name('notifications.email.send-test');
        Route::post('/email/copy-from-instance', [NotificationsEmailController::class, 'copyFromInstance'])->name('notifications.email.copy-from-instance');
        Route::get('/telegram', [NotificationsTelegramController::class, 'edit'])->name('notifications.telegram');
        Route::put('/telegram', [NotificationsTelegramController::class, 'update'])->name('notifications.telegram.update');
        Route::post('/telegram/send-test', [NotificationsTelegramController::class, 'sendTest'])->name('notifications.telegram.send-test');
        Route::get('/discord', [NotificationsDiscordController::class, 'edit'])->name('notifications.discord');
        Route::put('/discord', [NotificationsDiscordController::class, 'update'])->name('notifications.discord.update');
        Route::post('/discord/send-test', [NotificationsDiscordController::class, 'sendTest'])->name('notifications.discord.send-test');
        Route::get('/slack', [NotificationsSlackController::class, 'edit'])->name('notifications.slack');
        Route::put('/slack', [NotificationsSlackController::class, 'update'])->name('notifications.slack.update');
        Route::post('/slack/send-test', [NotificationsSlackController::class, 'sendTest'])->name('notifications.slack.send-test');
        Route::get('/pushover', [NotificationsPushoverController::class, 'edit'])->name('notifications.pushover');
        Route::put('/pushover', [NotificationsPushoverController::class, 'update'])->name('notifications.pushover.update');
        Route::post('/pushover/send-test', [NotificationsPushoverController::class, 'sendTest'])->name('notifications.pushover.send-test');
        Route::get('/webhook', [NotificationsWebhookController::class, 'edit'])->name('notifications.webhook');
        Route::put('/webhook', [NotificationsWebhookController::class, 'update'])->name('notifications.webhook.update');
        Route::post('/webhook/send-test', [NotificationsWebhookController::class, 'sendTest'])->name('notifications.webhook.send-test');
    });

    Route::prefix('storages')->group(function () {
        Route::get('/', StorageIndex::class)->name('storage.index');
        Route::get('/{storage_uuid}', StorageShow::class)->name('storage.show');
        Route::get('/{storage_uuid}/resources', StorageShow::class)->name('storage.resources');
    });
    Route::prefix('shared-variables')->group(function () {
        Route::get('/', [SharedVariablesController::class, 'index'])->name('shared-variables.index');
        Route::get('/team', TeamSharedVariablesIndex::class)->name('shared-variables.team.index');
        Route::get('/projects', [SharedVariablesController::class, 'project'])->name('shared-variables.project.index');
        Route::get('/project/{project_uuid}', ProjectSharedVariablesShow::class)->name('shared-variables.project.show');
        Route::get('/environments', [SharedVariablesController::class, 'environment'])->name('shared-variables.environment.index');
        Route::get('/environments/project/{project_uuid}/environment/{environment_uuid}', EnvironmentSharedVariablesShow::class)->name('shared-variables.environment.show');
        Route::get('/servers', [SharedVariablesController::class, 'server'])->name('shared-variables.server.index');
        Route::get('/server/{server_uuid}', ServerSharedVariablesShow::class)->name('shared-variables.server.show');
    });

    Route::prefix('team')->group(function () {
        Route::get('/', [TeamController::class, 'index'])->name('team.index');
        Route::put('/', [TeamController::class, 'update'])->name('team.update');
        Route::delete('/', [TeamController::class, 'destroy'])->name('team.destroy');
        Route::get('/members', TeamMemberIndex::class)->name('team.member.index');
        Route::get('/admin', [TeamController::class, 'adminView'])->name('team.admin-view');
        Route::delete('/admin/user', [TeamController::class, 'adminDeleteUser'])->name('team.admin-view.delete-user');
    });

    Route::get('/terminal', [TerminalController::class, 'index'])->name('terminal')->middleware('can.access.terminal');
    Route::post('/terminal/connect', [TerminalController::class, 'connect'])->name('terminal.connect')->middleware('can.access.terminal');
    Route::get('/activity/{id}', [ActivityController::class, 'show'])->name('activity.show');
    Route::post('/terminal/auth', function () {
        if (auth()->check()) {
            return response()->json(['authenticated' => true], 200);
        }

        return response()->json(['authenticated' => false], 401);
    })->name('terminal.auth')->middleware('can.access.terminal');

    Route::post('/terminal/auth/ips', function () {
        if (auth()->check()) {
            $team = auth()->user()->currentTeam();
            $ipAddresses = $team->servers
                ->where('settings.is_terminal_enabled', true)
                ->pluck('ip')
                ->filter()
                ->values();

            if (isDev()) {
                $ipAddresses = $ipAddresses->merge([
                    'coolify-testing-host',
                    'host.docker.internal',
                    'localhost',
                    '127.0.0.1',
                    base_ip(),
                ])->filter()->unique()->values();
            }

            return response()->json(['ipAddresses' => $ipAddresses->all()], 200);
        }

        return response()->json(['ipAddresses' => []], 401);
    })->name('terminal.auth.ips')->middleware('can.access.terminal');

    Route::prefix('invitations')->group(function () {
        Route::get('/{uuid}', [Controller::class, 'showInvitation'])->name('team.invitation.show');
        Route::post('/{uuid}', [Controller::class, 'acceptInvitation'])->name('team.invitation.accept');
    });

    Route::get('/projects', ProjectIndex::class)->name('project.index');
    Route::prefix('project/{project_uuid}')->group(function () {
        Route::get('/', ProjectShow::class)->name('project.show');
        Route::get('/edit', ProjectEdit::class)->name('project.edit')->middleware('can.update.resource');
    });
    Route::prefix('project/{project_uuid}/environment/{environment_uuid}')->group(function () {
        Route::get('/', ResourceIndex::class)->name('project.resource.index');
        Route::get('/clone', ProjectCloneMe::class)->name('project.clone-me')->middleware('can.create.resources');
        Route::get('/new', ResourceCreate::class)->name('project.resource.create')->middleware('can.create.resources');
        Route::get('/edit', EnvironmentEdit::class)->name('project.environment.edit')->middleware('can.update.resource');
    });
    Route::prefix('project/{project_uuid}/environment/{environment_uuid}/application/{application_uuid}')->group(function () {
        Route::get('/', ApplicationConfiguration::class)->name('project.application.configuration');
        Route::get('/swarm', ApplicationConfiguration::class)->name('project.application.swarm');
        Route::get('/advanced', ApplicationConfiguration::class)->name('project.application.advanced');
        Route::get('/environment-variables', ApplicationConfiguration::class)->name('project.application.environment-variables');
        Route::get('/persistent-storage', ApplicationConfiguration::class)->name('project.application.persistent-storage');
        Route::get('/source', ApplicationConfiguration::class)->name('project.application.source');
        Route::get('/servers', ApplicationConfiguration::class)->name('project.application.servers');
        Route::get('/scheduled-tasks', ApplicationConfiguration::class)->name('project.application.scheduled-tasks.show');
        Route::get('/webhooks', ApplicationConfiguration::class)->name('project.application.webhooks');
        Route::get('/preview-deployments', ApplicationConfiguration::class)->name('project.application.preview-deployments');
        Route::get('/healthcheck', ApplicationConfiguration::class)->name('project.application.healthcheck');
        Route::get('/rollback', ApplicationConfiguration::class)->name('project.application.rollback');
        Route::get('/resource-limits', ApplicationConfiguration::class)->name('project.application.resource-limits');
        Route::get('/resource-operations', ApplicationConfiguration::class)->name('project.application.resource-operations');
        Route::get('/metrics', ApplicationConfiguration::class)->name('project.application.metrics');
        Route::get('/tags', ApplicationConfiguration::class)->name('project.application.tags');
        Route::get('/danger', ApplicationConfiguration::class)->name('project.application.danger');

        Route::get('/deployment', [ApplicationDeploymentController::class, 'index'])->name('project.application.deployment.index');
        Route::post('/deployment/deploy', [ApplicationDeploymentController::class, 'deploy'])->name('project.application.deployment.deploy');
        Route::post('/deployment/restart', [ApplicationDeploymentController::class, 'restart'])->name('project.application.deployment.restart');
        Route::post('/deployment/stop', [ApplicationDeploymentController::class, 'stop'])->name('project.application.deployment.stop');
        Route::post('/deployment/check-status', [ApplicationDeploymentController::class, 'checkStatus'])->name('project.application.deployment.check-status');
        Route::get('/deployment/{deployment_uuid}', DeploymentShow::class)->name('project.application.deployment.show');
        Route::get('/logs', Logs::class)->name('project.application.logs');
        Route::get('/terminal', ExecuteContainerCommand::class)->name('project.application.command')->middleware('can.access.terminal');
        Route::get('/tasks/{task_uuid}', ApplicationConfiguration::class)->name('project.application.scheduled-tasks');
    });
    Route::prefix('project/{project_uuid}/environment/{environment_uuid}/database/{database_uuid}')->group(function () {
        Route::get('/', DatabaseConfiguration::class)->name('project.database.configuration');
        Route::get('/environment-variables', DatabaseConfiguration::class)->name('project.database.environment-variables');
        Route::get('/servers', DatabaseConfiguration::class)->name('project.database.servers');
        Route::get('/import-backup', DatabaseConfiguration::class)->name('project.database.import-backup')->middleware('can.update.resource');
        Route::get('/persistent-storage', DatabaseConfiguration::class)->name('project.database.persistent-storage');
        Route::get('/healthcheck', DatabaseConfiguration::class)->name('project.database.healthcheck');
        Route::get('/webhooks', DatabaseConfiguration::class)->name('project.database.webhooks');
        Route::get('/resource-limits', DatabaseConfiguration::class)->name('project.database.resource-limits');
        Route::get('/resource-operations', DatabaseConfiguration::class)->name('project.database.resource-operations');
        Route::get('/metrics', DatabaseConfiguration::class)->name('project.database.metrics');
        Route::get('/tags', DatabaseConfiguration::class)->name('project.database.tags');
        Route::get('/danger', DatabaseConfiguration::class)->name('project.database.danger');

        Route::get('/logs', Logs::class)->name('project.database.logs');
        Route::get('/terminal', ExecuteContainerCommand::class)->name('project.database.command')->middleware('can.access.terminal');
        Route::get('/backups', DatabaseBackupIndex::class)->name('project.database.backup.index');
        Route::get('/backups/{backup_uuid}', DatabaseBackupExecution::class)->name('project.database.backup.execution');
    });
    Route::prefix('project/{project_uuid}/environment/{environment_uuid}/service/{service_uuid}')->group(function () {
        Route::get('/', ServiceConfiguration::class)->name('project.service.configuration');
        Route::get('/logs', Logs::class)->name('project.service.logs');
        Route::get('/environment-variables', ServiceConfiguration::class)->name('project.service.environment-variables');
        Route::get('/storages', ServiceConfiguration::class)->name('project.service.storages');
        Route::get('/scheduled-tasks', ServiceConfiguration::class)->name('project.service.scheduled-tasks.show');
        Route::get('/webhooks', ServiceConfiguration::class)->name('project.service.webhooks');
        Route::get('/resource-operations', ServiceConfiguration::class)->name('project.service.resource-operations');
        Route::get('/tags', ServiceConfiguration::class)->name('project.service.tags');
        Route::get('/danger', ServiceConfiguration::class)->name('project.service.danger');
        Route::get('/terminal', ExecuteContainerCommand::class)->name('project.service.command')->middleware('can.access.terminal');
        Route::get('/{stack_service_uuid}/backups', ServiceDatabaseBackups::class)->name('project.service.database.backups');
        Route::get('/{stack_service_uuid}/import', ServiceIndex::class)->name('project.service.database.import')->middleware('can.update.resource');
        Route::get('/{stack_service_uuid}/advanced', ServiceIndex::class)->name('project.service.index.advanced');
        Route::get('/{stack_service_uuid}', ServiceIndex::class)->name('project.service.index');
        Route::get('/tasks/{task_uuid}', ServiceConfiguration::class)->name('project.service.scheduled-tasks');
    });

    Route::get('/servers', ServerIndex::class)->name('server.index');
    // Route::get('/server/new', ServerCreate::class)->name('server.create');

    Route::prefix('server/{server_uuid}')->group(function () {
        Route::get('/', ServerShow::class)->name('server.show');
        Route::get('/advanced', [ServerAdvancedController::class, 'index'])->name('server.advanced');
        Route::put('/advanced', [ServerAdvancedController::class, 'update'])->name('server.advanced.update');
        Route::get('/swarm', [ServerSwarmController::class, 'index'])->name('server.swarm');
        Route::put('/swarm', [ServerSwarmController::class, 'update'])->name('server.swarm.update');
        Route::get('/sentinel', [ServerSentinelController::class, 'index'])->name('server.sentinel');
        Route::post('/sentinel/submit', [ServerSentinelController::class, 'submit'])->name('server.sentinel.submit');
        Route::post('/sentinel/toggle', [ServerSentinelController::class, 'toggle'])->name('server.sentinel.toggle');
        Route::post('/sentinel/restart', [ServerSentinelController::class, 'restart'])->name('server.sentinel.restart');
        Route::post('/sentinel/regenerate-token', [ServerSentinelController::class, 'regenerateToken'])->name('server.sentinel.regenerate-token');
        Route::get('/sentinel/logs', SentinelLogs::class)->name('server.sentinel.logs');
        Route::get('/private-key', [ServerPrivateKeyController::class, 'index'])->name('server.private-key');
        Route::post('/private-key/set', [ServerPrivateKeyController::class, 'setKey'])->name('server.private-key.set');
        Route::post('/private-key/check-connection', [ServerPrivateKeyController::class, 'checkConnection'])->name('server.private-key.check-connection');
        Route::get('/cloud-provider-token', [ServerCloudProviderTokenController::class, 'index'])->name('server.cloud-provider-token');
        Route::post('/cloud-provider-token/set', [ServerCloudProviderTokenController::class, 'setToken'])->name('server.cloud-provider-token.set');
        Route::post('/cloud-provider-token/validate', [ServerCloudProviderTokenController::class, 'validateToken'])->name('server.cloud-provider-token.validate');
        Route::post('/cloud-provider-token/store', [ServerCloudProviderTokenController::class, 'store'])->name('server.cloud-provider-token.store');
        Route::get('/ca-certificate', [ServerCaCertificateController::class, 'index'])->name('server.ca-certificate');
        Route::post('/ca-certificate/save', [ServerCaCertificateController::class, 'save'])->name('server.ca-certificate.save');
        Route::post('/ca-certificate/regenerate', [ServerCaCertificateController::class, 'regenerate'])->name('server.ca-certificate.regenerate');
        Route::get('/resources', [ServerResourcesController::class, 'index'])->name('server.resources');
        Route::post('/resources/container-action', [ServerResourcesController::class, 'containerAction'])->name('server.resources.container-action');
        Route::get('/cloudflare-tunnel', [ServerCloudflareTunnelController::class, 'index'])->name('server.cloudflare-tunnel');
        Route::post('/cloudflare-tunnel/toggle', [ServerCloudflareTunnelController::class, 'toggle'])->name('server.cloudflare-tunnel.toggle');
        Route::post('/cloudflare-tunnel/manual-config', [ServerCloudflareTunnelController::class, 'manualConfig'])->name('server.cloudflare-tunnel.manual-config');
        Route::post('/cloudflare-tunnel/automated-config', [ServerCloudflareTunnelController::class, 'automatedConfig'])->name('server.cloudflare-tunnel.automated-config');
        Route::get('/destinations', [ServerDestinationsController::class, 'index'])->name('server.destinations');
        Route::post('/destinations/scan', [ServerDestinationsController::class, 'scan'])->name('server.destinations.scan');
        Route::post('/destinations/add', [ServerDestinationsController::class, 'add'])->name('server.destinations.add');
        Route::post('/destinations/create', [ServerDestinationsController::class, 'create'])->name('server.destinations.create');
        Route::get('/log-drains', [ServerLogDrainsController::class, 'index'])->name('server.log-drains');
        Route::post('/log-drains/toggle', [ServerLogDrainsController::class, 'toggle'])->name('server.log-drains.toggle');
        Route::post('/log-drains/submit', [ServerLogDrainsController::class, 'submit'])->name('server.log-drains.submit');
        Route::get('/metrics', [ServerMetricsController::class, 'index'])->name('server.metrics');
        Route::post('/metrics/toggle', [ServerMetricsController::class, 'toggleMetrics'])->name('server.metrics.toggle');
        Route::get('/metrics/data', [ServerMetricsController::class, 'data'])->name('server.metrics.data');
        Route::get('/danger', [ServerDeleteController::class, 'index'])->name('server.delete');
        Route::delete('/danger', [ServerDeleteController::class, 'destroy'])->name('server.delete.destroy');
        Route::get('/proxy', [ServerProxyController::class, 'index'])->name('server.proxy');
        Route::post('/proxy/select', [ServerProxyController::class, 'selectProxy'])->name('server.proxy.select');
        Route::post('/proxy/reset-selection', [ServerProxyController::class, 'resetProxySelection'])->name('server.proxy.reset-selection');
        Route::post('/proxy/instant-save', [ServerProxyController::class, 'instantSave'])->name('server.proxy.instant-save');
        Route::post('/proxy/instant-save-redirect', [ServerProxyController::class, 'instantSaveRedirect'])->name('server.proxy.instant-save-redirect');
        Route::post('/proxy/submit', [ServerProxyController::class, 'submit'])->name('server.proxy.submit');
        Route::post('/proxy/reset-configuration', [ServerProxyController::class, 'resetConfiguration'])->name('server.proxy.reset-configuration');
        Route::get('/proxy/dynamic', ProxyDynamicConfigurations::class)->name('server.proxy.dynamic-confs');
        Route::get('/proxy/logs', ProxyLogs::class)->name('server.proxy.logs');
        Route::post('/proxy-actions/restart', [ServerProxyActionsController::class, 'restart'])->name('server.proxy-actions.restart');
        Route::post('/proxy-actions/stop', [ServerProxyActionsController::class, 'stop'])->name('server.proxy-actions.stop');
        Route::post('/proxy-actions/start', [ServerProxyActionsController::class, 'start'])->name('server.proxy-actions.start');
        Route::post('/proxy-actions/check-status', [ServerProxyActionsController::class, 'checkStatus'])->name('server.proxy-actions.check-status');
        Route::get('/terminal', ExecuteContainerCommand::class)->name('server.command')->middleware('can.access.terminal');
        Route::get('/docker-cleanup', [ServerDockerCleanupController::class, 'index'])->name('server.docker-cleanup');
        Route::put('/docker-cleanup', [ServerDockerCleanupController::class, 'update'])->name('server.docker-cleanup.update');
        Route::post('/docker-cleanup/manual-cleanup', [ServerDockerCleanupController::class, 'manualCleanup'])->name('server.docker-cleanup.manual-cleanup');
        Route::get('/docker-cleanup/executions', [ServerDockerCleanupController::class, 'executions'])->name('server.docker-cleanup.executions');
        Route::get('/docker-cleanup/executions/{execution}/download', [ServerDockerCleanupController::class, 'downloadLog'])->name('server.docker-cleanup.download-log');
        Route::get('/security', fn () => redirect(route('dashboard')))->name('server.security')->middleware('can.update.resource');
        Route::get('/security/patches', [ServerSecurityPatchesController::class, 'index'])->name('server.security.patches')->middleware('can.update.resource');
        Route::post('/security/patches/check-updates', [ServerSecurityPatchesController::class, 'checkUpdates'])->name('server.security.patches.check-updates')->middleware('can.update.resource');
        Route::post('/security/patches/update-all', [ServerSecurityPatchesController::class, 'updateAll'])->name('server.security.patches.update-all')->middleware('can.update.resource');
        Route::post('/security/patches/update-package', [ServerSecurityPatchesController::class, 'updatePackage'])->name('server.security.patches.update-package')->middleware('can.update.resource');
        Route::post('/security/patches/notify-updated', [ServerSecurityPatchesController::class, 'notifyUpdated'])->name('server.security.patches.notify-updated')->middleware('can.update.resource');
        Route::post('/security/patches/send-test-email', [ServerSecurityPatchesController::class, 'sendTestEmail'])->name('server.security.patches.send-test-email')->middleware('can.update.resource');
        Route::get('/security/terminal-access', [ServerSecurityTerminalAccessController::class, 'index'])->name('server.security.terminal-access')->middleware('can.update.resource');
        Route::put('/security/terminal-access', [ServerSecurityTerminalAccessController::class, 'toggle'])->name('server.security.terminal-access.toggle')->middleware('can.update.resource');
    });
    Route::get('/destinations', DestinationIndex::class)->name('destination.index');
    Route::get('/destination/{destination_uuid}', [DestinationController::class, 'show'])->name('destination.show');
    Route::get('/destination/{destination_uuid}/resources', [DestinationController::class, 'resources'])->name('destination.resources');
    Route::put('/destination/{destination_uuid}', [DestinationController::class, 'update'])->name('destination.update');
    Route::delete('/destination/{destination_uuid}', [DestinationController::class, 'destroy'])->name('destination.destroy');

    // Route::get('/security', fn () => view('security.index'))->name('security.index');
    Route::get('/security/private-key', SecurityPrivateKeyIndex::class)->name('security.private-key.index');
    // Route::get('/security/private-key/new', SecurityPrivateKeyCreate::class)->name('security.private-key.create');
    Route::post('/security/private-key', [SecurityPrivateKeyController::class, 'store'])->name('security.private-key.store');
    Route::post('/security/private-key/generate', [SecurityPrivateKeyController::class, 'generateKey'])->name('security.private-key.generate');
    Route::get('/security/private-key/{private_key_uuid}', [SecurityPrivateKeyController::class, 'show'])->name('security.private-key.show');
    Route::put('/security/private-key/{private_key_uuid}', [SecurityPrivateKeyController::class, 'update'])->name('security.private-key.update');
    Route::delete('/security/private-key/{private_key_uuid}', [SecurityPrivateKeyController::class, 'destroy'])->name('security.private-key.destroy');

    Route::get('/security/cloud-tokens', [SecurityCloudTokensController::class, 'index'])->name('security.cloud-tokens');
    Route::post('/security/cloud-tokens', [SecurityCloudTokensController::class, 'store'])->name('security.cloud-tokens.store');
    Route::post('/security/cloud-tokens/{id}/validate', [SecurityCloudTokensController::class, 'validateToken'])->name('security.cloud-tokens.validate');
    Route::delete('/security/cloud-tokens/{id}', [SecurityCloudTokensController::class, 'destroy'])->name('security.cloud-tokens.destroy');
    Route::get('/security/cloud-init-scripts', [SecurityCloudInitScriptsController::class, 'index'])->name('security.cloud-init-scripts');
    Route::post('/security/cloud-init-scripts', [SecurityCloudInitScriptsController::class, 'store'])->name('security.cloud-init-scripts.store');
    Route::put('/security/cloud-init-scripts/{id}', [SecurityCloudInitScriptsController::class, 'update'])->name('security.cloud-init-scripts.update');
    Route::delete('/security/cloud-init-scripts/{id}', [SecurityCloudInitScriptsController::class, 'destroy'])->name('security.cloud-init-scripts.destroy');
    Route::get('/security/api-tokens', [SecurityApiTokensController::class, 'index'])->name('security.api-tokens');
    Route::post('/security/api-tokens', [SecurityApiTokensController::class, 'store'])->name('security.api-tokens.store');
    Route::delete('/security/api-tokens/{id}', [SecurityApiTokensController::class, 'destroy'])->name('security.api-tokens.destroy');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/sources', function () {
        $sources = currentTeam()->sources();

        return view('source.all', [
            'sources' => $sources,
        ]);
    })->name('source.all');
    Route::get('/source/github/{github_app_uuid}', GitHubChange::class)->name('source.github.show');
    Route::get('/source/github/{github_app_uuid}/permissions', GitHubChange::class)->name('source.github.permissions');
    Route::get('/source/github/{github_app_uuid}/resources', GitHubChange::class)->name('source.github.resources');
});

Route::middleware(['auth'])->group(function () {
    Route::post('/upload/backup/{databaseUuid}', [UploadController::class, 'upload'])->name('upload.backup');
    Route::get('/download/backup/{executionId}', function () {
        try {
            $user = auth()->user();
            $team = $user->currentTeam();
            if (is_null($team)) {
                return response()->json(['message' => 'Team not found.'], 404);
            }
            if ($user->isAdminFromSession() === false) {
                return response()->json(['message' => 'Only team admins/owners can download backups.'], 403);
            }
            $exeuctionId = request()->route('executionId');
            $execution = ScheduledDatabaseBackupExecution::where('id', $exeuctionId)->firstOrFail();
            $execution_team_id = $execution->scheduledDatabaseBackup->database->team()?->id;
            if ($team->id !== 0) {
                if (is_null($execution_team_id)) {
                    return response()->json(['message' => 'Team not found.'], 404);
                }
                if ($team->id !== $execution_team_id) {
                    return response()->json(['message' => 'Permission denied.'], 403);
                }
                if (is_null($execution)) {
                    return response()->json(['message' => 'Backup not found.'], 404);
                }
            }
            $filename = data_get($execution, 'filename');
            if ($execution->scheduledDatabaseBackup->database->getMorphClass() === ServiceDatabase::class) {
                $server = $execution->scheduledDatabaseBackup->database->service->destination->server;
            } else {
                $server = $execution->scheduledDatabaseBackup->database->destination->server;
            }

            $privateKeyLocation = $server->privateKey->getKeyLocation();
            $disk = Storage::build([
                'driver' => 'sftp',
                'host' => $server->ip,
                'port' => (int) $server->port,
                'username' => $server->user,
                'privateKey' => $privateKeyLocation,
                'root' => '/',
            ]);
            if (! $disk->exists($filename)) {
                if ($execution->scheduledDatabaseBackup->disable_local_backup === true && $execution->scheduledDatabaseBackup->save_s3 === true) {
                    return response()->json(['message' => 'Backup not available locally, but available on S3.'], 404);
                }

                return response()->json(['message' => 'Backup not found locally on the server.'], 404);
            }

            return new StreamedResponse(function () use ($disk, $filename) {
                if (ob_get_level()) {
                    ob_end_clean();
                }
                $stream = $disk->readStream($filename);
                if ($stream === false || is_null($stream)) {
                    abort(500, 'Failed to open stream for the requested file.');
                }
                while (! feof($stream)) {
                    echo fread($stream, 2048);
                    flush();
                }

                fclose($stream);
            }, 200, [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="'.basename($filename).'"',
            ]);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Failed to download backup.'], 500);
        }
    })->name('download.backup');

});

Route::any('/{any}', function () {
    if (auth()->user()) {
        return redirect(RouteServiceProvider::HOME);
    }

    return redirect()->route('login');
})->where('any', '.*');
