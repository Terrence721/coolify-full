<?php

declare(strict_types=1);

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\ApplicationDeploymentController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DestinationController;
use App\Http\Controllers\EnvironmentController;
use App\Http\Controllers\ExecuteContainerCommandController;
use App\Http\Controllers\ForcePasswordResetController;
use App\Http\Controllers\NotificationsDiscordController;
use App\Http\Controllers\NotificationsEmailController;
use App\Http\Controllers\NotificationsPushoverController;
use App\Http\Controllers\NotificationsSlackController;
use App\Http\Controllers\NotificationsTelegramController;
use App\Http\Controllers\NotificationsWebhookController;
use App\Http\Controllers\OauthController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectDatabaseBackupController;
use App\Http\Controllers\ProjectDatabaseConfigurationController;
use App\Http\Controllers\ProjectLogsController;
use App\Http\Controllers\ProjectMetricsController;
use App\Http\Controllers\ProjectResourceController;
use App\Http\Controllers\ProjectResourceCreateController;
use App\Http\Controllers\ProjectResourceGitCreateController;
use App\Http\Controllers\ProjectServiceConfigurationController;
use App\Http\Controllers\ProjectServiceDatabaseBackupController;
use App\Http\Controllers\ProjectServiceResourceController;
use App\Http\Controllers\SecurityApiTokensController;
use App\Http\Controllers\SecurityCloudInitScriptsController;
use App\Http\Controllers\SecurityCloudTokensController;
use App\Http\Controllers\SecurityPrivateKeyController;
use App\Http\Controllers\ServerAdvancedController;
use App\Http\Controllers\ServerCaCertificateController;
use App\Http\Controllers\ServerCloudflareTunnelController;
use App\Http\Controllers\ServerCloudProviderTokenController;
use App\Http\Controllers\ServerController;
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
use App\Http\Controllers\SettingsBackupController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SettingsEmailController;
use App\Http\Controllers\SettingsScheduledJobsController;
use App\Http\Controllers\SharedVariablesController;
use App\Http\Controllers\SourceGithubController;
use App\Http\Controllers\StorageController;
use App\Http\Controllers\TagsController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TerminalController;
use App\Http\Controllers\UploadController;
use App\Livewire\Boarding\Index as BoardingIndex;
use App\Livewire\Project\Application\Configuration as ApplicationConfiguration;
use App\Livewire\Project\Database\Configuration as DatabaseConfiguration;
use App\Livewire\Project\Service\Index as ServiceIndex;
use App\Livewire\Server\Show as ServerShow;
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

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/admin', [AdminController::class, 'index'])->name('admin.index');
    Route::post('/admin/back', [AdminController::class, 'back'])->name('admin.back');
    Route::post('/admin/switch-user', [AdminController::class, 'switchUser'])->name('admin.switch-user');
    Route::get('/onboarding', BoardingIndex::class)->name('onboarding');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('/settings/build-helper-image', [SettingsController::class, 'buildHelperImage'])->name('settings.build-helper-image');
    Route::get('/settings/advanced', [SettingsController::class, 'advanced'])->name('settings.advanced');
    Route::put('/settings/advanced', [SettingsController::class, 'advancedUpdate'])->name('settings.advanced.update');
    Route::post('/settings/advanced/enable-registration', [SettingsController::class, 'advancedEnableRegistration'])->name('settings.advanced.enable-registration');
    Route::post('/settings/advanced/disable-two-step-confirmation', [SettingsController::class, 'advancedDisableTwoStepConfirmation'])->name('settings.advanced.disable-two-step-confirmation');
    Route::get('/settings/updates', [SettingsController::class, 'updates'])->name('settings.updates');
    Route::put('/settings/updates', [SettingsController::class, 'updatesUpdate'])->name('settings.updates.update');
    Route::post('/settings/updates/check-manually', [SettingsController::class, 'updatesCheckManually'])->name('settings.updates.check-manually');

    Route::get('/settings/backup', [SettingsBackupController::class, 'index'])->name('settings.backup');
    Route::put('/settings/backup', [SettingsBackupController::class, 'update'])->name('settings.backup.update');
    Route::post('/settings/backup/add-database', [SettingsBackupController::class, 'addDatabase'])->name('settings.backup.add-database');
    Route::put('/settings/backup/schedule', [SettingsBackupController::class, 'updateSchedule'])->name('settings.backup.schedule.update');
    Route::post('/settings/backup/backup-now', [SettingsBackupController::class, 'backupNow'])->name('settings.backup.backup-now');
    Route::post('/settings/backup/cleanup-failed', [SettingsBackupController::class, 'cleanupFailedExecutions'])->name('settings.backup.cleanup-failed');
    Route::post('/settings/backup/cleanup-deleted', [SettingsBackupController::class, 'cleanupDeletedExecutions'])->name('settings.backup.cleanup-deleted');
    Route::delete('/settings/backup/executions/{execution_id}', [SettingsBackupController::class, 'destroyExecution'])->name('settings.backup.execution.destroy');
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
        Route::get('/', [StorageController::class, 'index'])->name('storage.index');
        Route::post('/', [StorageController::class, 'store'])->name('storage.store');
        Route::get('/{storage_uuid}', [StorageController::class, 'show'])->name('storage.show');
        Route::put('/{storage_uuid}', [StorageController::class, 'update'])->name('storage.update');
        Route::delete('/{storage_uuid}', [StorageController::class, 'destroy'])->name('storage.destroy');
        Route::post('/{storage_uuid}/test-connection', [StorageController::class, 'testConnection'])->name('storage.test-connection');
        Route::get('/{storage_uuid}/resources', [StorageController::class, 'resources'])->name('storage.resources');
        Route::post('/{storage_uuid}/resources/{backup_id}/disable-s3', [StorageController::class, 'disableS3'])->name('storage.resources.disable-s3');
        Route::post('/{storage_uuid}/resources/{backup_id}/move-backup', [StorageController::class, 'moveBackup'])->name('storage.resources.move-backup');
    });
    Route::prefix('shared-variables')->group(function () {
        Route::get('/', [SharedVariablesController::class, 'index'])->name('shared-variables.index');

        Route::get('/team', [SharedVariablesController::class, 'teamShow'])->name('shared-variables.team.index');
        Route::post('/team', [SharedVariablesController::class, 'storeVariable'])->name('shared-variables.team.store');
        Route::put('/team/{variable_id}', [SharedVariablesController::class, 'updateVariable'])->name('shared-variables.team.update');
        Route::post('/team/{variable_id}/lock', [SharedVariablesController::class, 'lockVariable'])->name('shared-variables.team.lock');
        Route::delete('/team/{variable_id}', [SharedVariablesController::class, 'destroyVariable'])->name('shared-variables.team.destroy');
        Route::post('/team/bulk-update', [SharedVariablesController::class, 'bulkUpdateVariables'])->name('shared-variables.team.bulk-update');

        Route::get('/projects', [SharedVariablesController::class, 'project'])->name('shared-variables.project.index');
        Route::get('/project/{project_uuid}', [SharedVariablesController::class, 'projectShow'])->name('shared-variables.project.show');
        Route::post('/project/{project_uuid}', [SharedVariablesController::class, 'storeVariable'])->name('shared-variables.project.store');
        Route::put('/project/{project_uuid}/{variable_id}', [SharedVariablesController::class, 'updateVariable'])->name('shared-variables.project.update');
        Route::post('/project/{project_uuid}/{variable_id}/lock', [SharedVariablesController::class, 'lockVariable'])->name('shared-variables.project.lock');
        Route::delete('/project/{project_uuid}/{variable_id}', [SharedVariablesController::class, 'destroyVariable'])->name('shared-variables.project.destroy');
        Route::post('/project/{project_uuid}/bulk-update', [SharedVariablesController::class, 'bulkUpdateVariables'])->name('shared-variables.project.bulk-update');

        Route::get('/environments', [SharedVariablesController::class, 'environment'])->name('shared-variables.environment.index');
        Route::get('/environments/project/{project_uuid}/environment/{environment_uuid}', [SharedVariablesController::class, 'environmentShow'])->name('shared-variables.environment.show');
        Route::post('/environments/project/{project_uuid}/environment/{environment_uuid}', [SharedVariablesController::class, 'storeVariable'])->name('shared-variables.environment.store');
        Route::put('/environments/project/{project_uuid}/environment/{environment_uuid}/{variable_id}', [SharedVariablesController::class, 'updateVariable'])->name('shared-variables.environment.update');
        Route::post('/environments/project/{project_uuid}/environment/{environment_uuid}/{variable_id}/lock', [SharedVariablesController::class, 'lockVariable'])->name('shared-variables.environment.lock');
        Route::delete('/environments/project/{project_uuid}/environment/{environment_uuid}/{variable_id}', [SharedVariablesController::class, 'destroyVariable'])->name('shared-variables.environment.destroy');
        Route::post('/environments/project/{project_uuid}/environment/{environment_uuid}/bulk-update', [SharedVariablesController::class, 'bulkUpdateVariables'])->name('shared-variables.environment.bulk-update');

        Route::get('/servers', [SharedVariablesController::class, 'server'])->name('shared-variables.server.index');
        Route::get('/server/{server_uuid}', [SharedVariablesController::class, 'serverShow'])->name('shared-variables.server.show');
        Route::post('/server/{server_uuid}', [SharedVariablesController::class, 'storeVariable'])->name('shared-variables.server.store');
        Route::put('/server/{server_uuid}/{variable_id}', [SharedVariablesController::class, 'updateVariable'])->name('shared-variables.server.update');
        Route::post('/server/{server_uuid}/{variable_id}/lock', [SharedVariablesController::class, 'lockVariable'])->name('shared-variables.server.lock');
        Route::delete('/server/{server_uuid}/{variable_id}', [SharedVariablesController::class, 'destroyVariable'])->name('shared-variables.server.destroy');
        Route::post('/server/{server_uuid}/bulk-update', [SharedVariablesController::class, 'bulkUpdateVariables'])->name('shared-variables.server.bulk-update');
    });

    Route::prefix('team')->group(function () {
        Route::get('/', [TeamController::class, 'index'])->name('team.index');
        Route::put('/', [TeamController::class, 'update'])->name('team.update');
        Route::delete('/', [TeamController::class, 'destroy'])->name('team.destroy');
        Route::get('/members', [TeamController::class, 'memberIndex'])->name('team.member.index');
        Route::put('/members/{member_id}/role', [TeamController::class, 'updateMemberRole'])->name('team.member.update-role');
        Route::delete('/members/{member_id}', [TeamController::class, 'removeMember'])->name('team.member.remove');
        Route::post('/invitations', [TeamController::class, 'sendInvitation'])->name('team.invitation.send');
        Route::delete('/invitations/{invitation_id}', [TeamController::class, 'deleteInvitation'])->name('team.invitation.destroy');
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

    Route::get('/projects', [ProjectController::class, 'index'])->name('project.index');
    Route::post('/projects', [ProjectController::class, 'store'])->name('project.store');
    Route::prefix('project/{project_uuid}')->group(function () {
        Route::get('/', [ProjectController::class, 'show'])->name('project.show');
        Route::post('/environment', [ProjectController::class, 'createEnvironment'])->name('project.create-environment');
        Route::get('/edit', [ProjectController::class, 'edit'])->name('project.edit')->middleware('can.update.resource');
        Route::put('/edit', [ProjectController::class, 'update'])->name('project.update')->middleware('can.update.resource');
        Route::delete('/', [ProjectController::class, 'destroy'])->name('project.destroy');
    });
    Route::prefix('project/{project_uuid}/environment/{environment_uuid}')->group(function () {
        Route::get('/', [ProjectResourceController::class, 'index'])->name('project.resource.index');
        Route::get('/clone', [EnvironmentController::class, 'cloneMe'])->name('project.clone-me')->middleware('can.create.resources');
        Route::post('/clone', [EnvironmentController::class, 'clone'])->name('project.clone-me.store')->middleware('can.create.resources');
        Route::get('/new', [ProjectResourceCreateController::class, 'index'])->name('project.resource.create')->middleware('can.create.resources');
        Route::post('/new/dockerfile', [ProjectResourceCreateController::class, 'storeDockerfile'])->name('project.resource.create.dockerfile')->middleware('can.create.resources');
        Route::post('/new/docker-image', [ProjectResourceCreateController::class, 'storeDockerImage'])->name('project.resource.create.docker-image')->middleware('can.create.resources');
        Route::post('/new/docker-compose', [ProjectResourceCreateController::class, 'storeDockerCompose'])->name('project.resource.create.docker-compose')->middleware('can.create.resources');
        Route::get('/new/git', [ProjectResourceGitCreateController::class, 'index'])->name('project.resource.create.git')->middleware('can.create.resources');
        Route::post('/new/git/check-repository', [ProjectResourceGitCreateController::class, 'checkPublicRepository'])->name('project.resource.create.git.check')->middleware('can.create.resources');
        Route::get('/new/git/repositories', [ProjectResourceGitCreateController::class, 'loadRepositories'])->name('project.resource.create.git.repositories')->middleware('can.create.resources');
        Route::get('/new/git/branches', [ProjectResourceGitCreateController::class, 'loadBranches'])->name('project.resource.create.git.branches')->middleware('can.create.resources');
        Route::post('/new/git/public', [ProjectResourceGitCreateController::class, 'storePublic'])->name('project.resource.create.git.public')->middleware('can.create.resources');
        Route::post('/new/git/private-gh-app', [ProjectResourceGitCreateController::class, 'storePrivateGithubApp'])->name('project.resource.create.git.private-gh-app')->middleware('can.create.resources');
        Route::post('/new/git/private-deploy-key', [ProjectResourceGitCreateController::class, 'storePrivateDeployKey'])->name('project.resource.create.git.private-deploy-key')->middleware('can.create.resources');
        Route::get('/edit', [EnvironmentController::class, 'edit'])->name('project.environment.edit')->middleware('can.update.resource');
        Route::put('/edit', [EnvironmentController::class, 'update'])->name('project.environment.update')->middleware('can.update.resource');
        Route::delete('/', [EnvironmentController::class, 'destroy'])->name('project.environment.destroy');
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
        Route::get('/metrics', [ProjectMetricsController::class, 'application'])->name('project.application.metrics');
        Route::get('/metrics/data', [ProjectMetricsController::class, 'applicationData'])->name('project.application.metrics.data');
        Route::get('/tags', ApplicationConfiguration::class)->name('project.application.tags');
        Route::get('/danger', ApplicationConfiguration::class)->name('project.application.danger');

        Route::get('/deployment', [ApplicationDeploymentController::class, 'index'])->name('project.application.deployment.index');
        Route::post('/deployment/deploy', [ApplicationDeploymentController::class, 'deploy'])->name('project.application.deployment.deploy');
        Route::post('/deployment/restart', [ApplicationDeploymentController::class, 'restart'])->name('project.application.deployment.restart');
        Route::post('/deployment/stop', [ApplicationDeploymentController::class, 'stop'])->name('project.application.deployment.stop');
        Route::post('/deployment/check-status', [ApplicationDeploymentController::class, 'checkStatus'])->name('project.application.deployment.check-status');
        Route::post('/deployment/toggle-debug', [ApplicationDeploymentController::class, 'toggleDebug'])->name('project.application.deployment.toggle-debug');
        Route::get('/deployment/{deployment_uuid}', [ApplicationDeploymentController::class, 'show'])->name('project.application.deployment.show');
        Route::post('/deployment/{deployment_uuid}/force-start', [ApplicationDeploymentController::class, 'forceStart'])->name('project.application.deployment.force-start');
        Route::post('/deployment/{deployment_uuid}/cancel', [ApplicationDeploymentController::class, 'cancel'])->name('project.application.deployment.cancel');
        Route::get('/deployment/{deployment_uuid}/download-all-logs', [ApplicationDeploymentController::class, 'downloadAllLogs'])->name('project.application.deployment.download-all-logs');
        Route::get('/logs', [ProjectLogsController::class, 'application'])->name('project.application.logs');
        Route::get('/terminal', [ExecuteContainerCommandController::class, 'application'])->name('project.application.command')->middleware('can.access.terminal');
        Route::post('/terminal/connect', [ExecuteContainerCommandController::class, 'connectApplication'])->name('project.application.command.connect')->middleware('can.access.terminal');
        Route::get('/tasks/{task_uuid}', ApplicationConfiguration::class)->name('project.application.scheduled-tasks');
    });
    Route::prefix('project/{project_uuid}/environment/{environment_uuid}/database/{database_uuid}')->group(function () {
        Route::get('/', DatabaseConfiguration::class)->name('project.database.configuration');
        Route::get('/environment-variables', [ProjectDatabaseConfigurationController::class, 'show'])->name('project.database.environment-variables');
        Route::post('/environment-variables', [ProjectDatabaseConfigurationController::class, 'storeEnv'])->name('project.database.envs.store');
        Route::patch('/environment-variables/bulk', [ProjectDatabaseConfigurationController::class, 'bulkUpdateEnvs'])->name('project.database.envs.bulk-update');
        Route::patch('/environment-variables/{env_id}', [ProjectDatabaseConfigurationController::class, 'updateEnv'])->name('project.database.envs.update');
        Route::post('/environment-variables/{env_id}/lock', [ProjectDatabaseConfigurationController::class, 'lockEnv'])->name('project.database.envs.lock');
        Route::delete('/environment-variables/{env_id}', [ProjectDatabaseConfigurationController::class, 'destroyEnv'])->name('project.database.envs.destroy');
        Route::get('/servers', [ProjectDatabaseConfigurationController::class, 'show'])->name('project.database.servers');
        Route::get('/import-backup', DatabaseConfiguration::class)->name('project.database.import-backup')->middleware('can.update.resource');
        Route::get('/persistent-storage', [ProjectDatabaseConfigurationController::class, 'show'])->name('project.database.persistent-storage');
        Route::post('/persistent-storage/volume', [ProjectDatabaseConfigurationController::class, 'storagesVolumeStore'])->name('project.database.storages.volume.store');
        Route::patch('/persistent-storage/volume/{volume_id}', [ProjectDatabaseConfigurationController::class, 'storagesVolumeUpdate'])->name('project.database.storages.volume.update');
        Route::delete('/persistent-storage/volume/{volume_id}', [ProjectDatabaseConfigurationController::class, 'storagesVolumeDestroy'])->name('project.database.storages.volume.destroy');
        Route::post('/persistent-storage/file', [ProjectDatabaseConfigurationController::class, 'storagesFileStore'])->name('project.database.storages.file.store');
        Route::post('/persistent-storage/directory', [ProjectDatabaseConfigurationController::class, 'storagesDirectoryStore'])->name('project.database.storages.directory.store');
        Route::patch('/persistent-storage/file/{file_id}', [ProjectDatabaseConfigurationController::class, 'storagesFileUpdate'])->name('project.database.storages.file.update');
        Route::post('/persistent-storage/file/{file_id}/load', [ProjectDatabaseConfigurationController::class, 'storagesFileLoad'])->name('project.database.storages.file.load');
        Route::post('/persistent-storage/file/{file_id}/convert', [ProjectDatabaseConfigurationController::class, 'storagesFileConvert'])->name('project.database.storages.file.convert');
        Route::delete('/persistent-storage/file/{file_id}', [ProjectDatabaseConfigurationController::class, 'storagesFileDestroy'])->name('project.database.storages.file.destroy');
        Route::get('/healthcheck', DatabaseConfiguration::class)->name('project.database.healthcheck');
        Route::get('/webhooks', [ProjectDatabaseConfigurationController::class, 'show'])->name('project.database.webhooks');
        Route::get('/resource-limits', [ProjectDatabaseConfigurationController::class, 'show'])->name('project.database.resource-limits');
        Route::patch('/resource-limits', [ProjectDatabaseConfigurationController::class, 'updateResourceLimits'])->name('project.database.resource-limits.update');
        Route::get('/resource-operations', [ProjectDatabaseConfigurationController::class, 'show'])->name('project.database.resource-operations');
        Route::post('/resource-operations/clone', [ProjectDatabaseConfigurationController::class, 'clone'])->name('project.database.clone');
        Route::post('/resource-operations/move', [ProjectDatabaseConfigurationController::class, 'move'])->name('project.database.move');
        Route::get('/metrics', [ProjectMetricsController::class, 'database'])->name('project.database.metrics');
        Route::get('/metrics/data', [ProjectMetricsController::class, 'databaseData'])->name('project.database.metrics.data');
        Route::get('/tags', [ProjectDatabaseConfigurationController::class, 'show'])->name('project.database.tags');
        Route::post('/tags', [ProjectDatabaseConfigurationController::class, 'storeTag'])->name('project.database.tags.store');
        Route::delete('/tags/{tag_id}', [ProjectDatabaseConfigurationController::class, 'destroyTag'])->name('project.database.tags.destroy');
        Route::get('/danger', [ProjectDatabaseConfigurationController::class, 'show'])->name('project.database.danger');
        Route::delete('/', [ProjectDatabaseConfigurationController::class, 'destroy'])->name('project.database.destroy');

        Route::get('/logs', [ProjectLogsController::class, 'database'])->name('project.database.logs');
        Route::get('/terminal', [ExecuteContainerCommandController::class, 'database'])->name('project.database.command')->middleware('can.access.terminal');
        Route::post('/terminal/connect', [ExecuteContainerCommandController::class, 'connectDatabase'])->name('project.database.command.connect')->middleware('can.access.terminal');

        Route::post('/start', [ProjectDatabaseBackupController::class, 'start'])->name('project.database.start');
        Route::post('/stop', [ProjectDatabaseBackupController::class, 'stop'])->name('project.database.stop');
        Route::post('/restart', [ProjectDatabaseBackupController::class, 'restart'])->name('project.database.restart');
        Route::post('/check-status', [ProjectDatabaseBackupController::class, 'checkStatus'])->name('project.database.check-status');

        Route::get('/backups', [ProjectDatabaseBackupController::class, 'index'])->name('project.database.backup.index');
        Route::post('/backups', [ProjectDatabaseBackupController::class, 'store'])->name('project.database.backup.store');
        Route::get('/backups/{backup_uuid}', [ProjectDatabaseBackupController::class, 'execution'])->name('project.database.backup.execution');
        Route::put('/backups/{backup_uuid}', [ProjectDatabaseBackupController::class, 'updateBackupSchedule'])->name('project.database.backup.update');
        Route::delete('/backups/{backup_uuid}', [ProjectDatabaseBackupController::class, 'destroyBackupSchedule'])->name('project.database.backup.destroy');
        Route::post('/backups/{backup_uuid}/backup-now', [ProjectDatabaseBackupController::class, 'backupNow'])->name('project.database.backup.backup-now');
        Route::post('/backups/{backup_uuid}/cleanup-failed', [ProjectDatabaseBackupController::class, 'cleanupFailedExecutions'])->name('project.database.backup.cleanup-failed');
        Route::post('/backups/{backup_uuid}/cleanup-deleted', [ProjectDatabaseBackupController::class, 'cleanupDeletedExecutions'])->name('project.database.backup.cleanup-deleted');
        Route::delete('/backups/{backup_uuid}/executions/{execution_id}', [ProjectDatabaseBackupController::class, 'destroyExecution'])->name('project.database.backup.execution.destroy');
    });
    Route::prefix('project/{project_uuid}/environment/{environment_uuid}/service/{service_uuid}')->group(function () {
        Route::get('/', [ProjectServiceConfigurationController::class, 'show'])->name('project.service.configuration');
        Route::patch('/general', [ProjectServiceConfigurationController::class, 'updateGeneral'])->name('project.service.general.update');
        Route::patch('/general/settings', [ProjectServiceConfigurationController::class, 'updateGeneralSettings'])->name('project.service.general.settings');
        Route::post('/general/validate-compose', [ProjectServiceConfigurationController::class, 'validateCompose'])->name('project.service.general.validate-compose');
        Route::patch('/general/domains/{application_id}', [ProjectServiceConfigurationController::class, 'updateChildDomain'])->name('project.service.child.domain');
        Route::post('/general/children/{child_uuid}/restart', [ProjectServiceConfigurationController::class, 'restartChild'])->name('project.service.child.restart');
        Route::get('/logs', [ProjectLogsController::class, 'service'])->name('project.service.logs');
        Route::post('/logs/start', [ProjectLogsController::class, 'serviceStart'])->name('project.logs.service.start');
        Route::post('/logs/force-deploy', [ProjectLogsController::class, 'serviceForceDeploy'])->name('project.logs.service.force-deploy');
        Route::post('/logs/restart', [ProjectLogsController::class, 'serviceRestart'])->name('project.logs.service.restart');
        Route::post('/logs/stop', [ProjectLogsController::class, 'serviceStop'])->name('project.logs.service.stop');
        Route::post('/logs/check-status', [ProjectLogsController::class, 'serviceCheckStatus'])->name('project.logs.service.check-status');
        Route::get('/environment-variables', [ProjectServiceConfigurationController::class, 'show'])->name('project.service.environment-variables');
        Route::post('/environment-variables', [ProjectServiceConfigurationController::class, 'storeEnv'])->name('project.service.envs.store');
        Route::patch('/environment-variables/bulk', [ProjectServiceConfigurationController::class, 'bulkUpdateEnvs'])->name('project.service.envs.bulk-update');
        Route::patch('/environment-variables/{env_id}', [ProjectServiceConfigurationController::class, 'updateEnv'])->name('project.service.envs.update');
        Route::post('/environment-variables/{env_id}/lock', [ProjectServiceConfigurationController::class, 'lockEnv'])->name('project.service.envs.lock');
        Route::delete('/environment-variables/{env_id}', [ProjectServiceConfigurationController::class, 'destroyEnv'])->name('project.service.envs.destroy');
        Route::get('/storages', [ProjectServiceConfigurationController::class, 'show'])->name('project.service.storages');
        Route::patch('/storages/volume/{volume_id}', [ProjectServiceConfigurationController::class, 'storagesVolumeUpdate'])->name('project.service.storages.volume.update');
        Route::delete('/storages/volume/{volume_id}', [ProjectServiceConfigurationController::class, 'storagesVolumeDestroy'])->name('project.service.storages.volume.destroy');
        Route::patch('/storages/file/{file_id}', [ProjectServiceConfigurationController::class, 'storagesFileUpdate'])->name('project.service.storages.file.update');
        Route::post('/storages/file/{file_id}/load', [ProjectServiceConfigurationController::class, 'storagesFileLoad'])->name('project.service.storages.file.load');
        Route::post('/storages/file/{file_id}/convert', [ProjectServiceConfigurationController::class, 'storagesFileConvert'])->name('project.service.storages.file.convert');
        Route::delete('/storages/file/{file_id}', [ProjectServiceConfigurationController::class, 'storagesFileDestroy'])->name('project.service.storages.file.destroy');
        Route::get('/scheduled-tasks', [ProjectServiceConfigurationController::class, 'show'])->name('project.service.scheduled-tasks.show');
        Route::post('/scheduled-tasks', [ProjectServiceConfigurationController::class, 'scheduledTaskStore'])->name('project.service.scheduled-tasks.store');
        Route::patch('/tasks/{task_uuid}', [ProjectServiceConfigurationController::class, 'scheduledTaskUpdate'])->name('project.service.scheduled-tasks.update');
        Route::post('/tasks/{task_uuid}/toggle', [ProjectServiceConfigurationController::class, 'scheduledTaskToggle'])->name('project.service.scheduled-tasks.toggle');
        Route::post('/tasks/{task_uuid}/execute', [ProjectServiceConfigurationController::class, 'scheduledTaskExecute'])->name('project.service.scheduled-tasks.execute');
        Route::delete('/tasks/{task_uuid}', [ProjectServiceConfigurationController::class, 'scheduledTaskDestroy'])->name('project.service.scheduled-tasks.destroy');
        Route::get('/tasks/{task_uuid}/executions/{execution_id}/download', [ProjectServiceConfigurationController::class, 'scheduledTaskDownload'])->name('project.service.scheduled-tasks.download');
        Route::get('/webhooks', [ProjectServiceConfigurationController::class, 'show'])->name('project.service.webhooks');
        Route::get('/resource-operations', [ProjectServiceConfigurationController::class, 'show'])->name('project.service.resource-operations');
        Route::post('/resource-operations/clone', [ProjectServiceConfigurationController::class, 'clone'])->name('project.service.clone');
        Route::post('/resource-operations/move', [ProjectServiceConfigurationController::class, 'move'])->name('project.service.move');
        Route::get('/tags', [ProjectServiceConfigurationController::class, 'show'])->name('project.service.tags');
        Route::post('/tags', [ProjectServiceConfigurationController::class, 'storeTag'])->name('project.service.tags.store');
        Route::delete('/tags/{tag_id}', [ProjectServiceConfigurationController::class, 'destroyTag'])->name('project.service.tags.destroy');
        Route::get('/danger', [ProjectServiceConfigurationController::class, 'show'])->name('project.service.danger');
        Route::delete('/', [ProjectServiceConfigurationController::class, 'destroy'])->name('project.service.destroy');
        Route::get('/terminal', [ExecuteContainerCommandController::class, 'service'])->name('project.service.command')->middleware('can.access.terminal');
        Route::post('/terminal/connect', [ExecuteContainerCommandController::class, 'connectService'])->name('project.service.command.connect')->middleware('can.access.terminal');
        Route::get('/{stack_service_uuid}/backups', [ProjectServiceDatabaseBackupController::class, 'index'])->name('project.service.database.backups');
        Route::post('/{stack_service_uuid}/backups/set-type', [ProjectServiceDatabaseBackupController::class, 'setType'])->name('project.service.database.backups.set-type');
        Route::post('/{stack_service_uuid}/backups/store', [ProjectServiceDatabaseBackupController::class, 'store'])->name('project.service.database.backups.store');
        Route::post('/{stack_service_uuid}/backups/start', [ProjectServiceDatabaseBackupController::class, 'start'])->name('project.service.database.backups.start');
        Route::post('/{stack_service_uuid}/backups/force-deploy', [ProjectServiceDatabaseBackupController::class, 'forceDeploy'])->name('project.service.database.backups.force-deploy');
        Route::post('/{stack_service_uuid}/backups/restart', [ProjectServiceDatabaseBackupController::class, 'restart'])->name('project.service.database.backups.restart');
        Route::post('/{stack_service_uuid}/backups/stop', [ProjectServiceDatabaseBackupController::class, 'stop'])->name('project.service.database.backups.stop');
        Route::post('/{stack_service_uuid}/backups/check-status', [ProjectServiceDatabaseBackupController::class, 'checkStatus'])->name('project.service.database.backups.check-status');
        Route::put('/{stack_service_uuid}/backups/{backup_id}', [ProjectServiceDatabaseBackupController::class, 'update'])->name('project.service.database.backups.update');
        Route::delete('/{stack_service_uuid}/backups/{backup_id}', [ProjectServiceDatabaseBackupController::class, 'destroy'])->name('project.service.database.backups.destroy');
        Route::post('/{stack_service_uuid}/backups/{backup_id}/backup-now', [ProjectServiceDatabaseBackupController::class, 'backupNow'])->name('project.service.database.backups.backup-now');
        Route::post('/{stack_service_uuid}/backups/{backup_id}/cleanup-failed', [ProjectServiceDatabaseBackupController::class, 'cleanupFailedExecutions'])->name('project.service.database.backups.cleanup-failed');
        Route::post('/{stack_service_uuid}/backups/{backup_id}/cleanup-deleted', [ProjectServiceDatabaseBackupController::class, 'cleanupDeletedExecutions'])->name('project.service.database.backups.cleanup-deleted');
        Route::delete('/{stack_service_uuid}/backups/{backup_id}/executions/{execution_id}', [ProjectServiceDatabaseBackupController::class, 'destroyExecution'])->name('project.service.database.backups.execution.destroy');
        Route::get('/{stack_service_uuid}/import', ServiceIndex::class)->name('project.service.database.import')->middleware('can.update.resource');
        Route::post('/{stack_service_uuid}/application', [ProjectServiceResourceController::class, 'updateApplication'])->name('project.service.application.update');
        Route::post('/{stack_service_uuid}/application/advanced', [ProjectServiceResourceController::class, 'updateApplicationAdvanced'])->name('project.service.application.update-advanced');
        Route::post('/{stack_service_uuid}/application/convert', [ProjectServiceResourceController::class, 'convertApplicationToDatabase'])->name('project.service.application.convert');
        Route::delete('/{stack_service_uuid}/application', [ProjectServiceResourceController::class, 'deleteApplication'])->name('project.service.application.delete');
        Route::post('/{stack_service_uuid}/database', [ProjectServiceResourceController::class, 'updateDatabase'])->name('project.service.database.update');
        Route::post('/{stack_service_uuid}/database/advanced', [ProjectServiceResourceController::class, 'updateDatabaseAdvanced'])->name('project.service.database.update-advanced');
        Route::post('/{stack_service_uuid}/database/public', [ProjectServiceResourceController::class, 'updateDatabasePublic'])->name('project.service.database.update-public');
        Route::post('/{stack_service_uuid}/database/convert', [ProjectServiceResourceController::class, 'convertDatabaseToApplication'])->name('project.service.database.convert');
        Route::delete('/{stack_service_uuid}/database', [ProjectServiceResourceController::class, 'deleteDatabase'])->name('project.service.database.delete');
        Route::get('/{stack_service_uuid}/database/proxy-logs', [ProjectServiceResourceController::class, 'proxyLogs'])->name('project.service.database.proxy-logs');
        Route::get('/{stack_service_uuid}/advanced', [ProjectServiceResourceController::class, 'advanced'])->name('project.service.index.advanced');
        Route::get('/{stack_service_uuid}', [ProjectServiceResourceController::class, 'show'])->name('project.service.index');
        Route::get('/tasks/{task_uuid}', [ProjectServiceConfigurationController::class, 'show'])->name('project.service.scheduled-tasks');
    });

    Route::get('/servers', [ServerController::class, 'index'])->name('server.index');
    Route::post('/servers', [ServerController::class, 'store'])->name('server.store');
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
        Route::get('/sentinel/logs', [ServerSentinelController::class, 'logs'])->name('server.sentinel.logs');
        Route::get('/sentinel/logs/download', [ServerSentinelController::class, 'downloadLogs'])->name('server.sentinel.logs.download');
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
        Route::get('/proxy/dynamic', [ServerProxyController::class, 'dynamicConfigurations'])->name('server.proxy.dynamic-confs');
        Route::post('/proxy/dynamic', [ServerProxyController::class, 'storeDynamicConfiguration'])->name('server.proxy.dynamic-confs.store');
        Route::delete('/proxy/dynamic', [ServerProxyController::class, 'destroyDynamicConfiguration'])->name('server.proxy.dynamic-confs.destroy');
        Route::get('/proxy/logs', [ServerProxyController::class, 'logs'])->name('server.proxy.logs');
        Route::get('/proxy/logs/download', [ServerProxyController::class, 'downloadLogs'])->name('server.proxy.logs.download');
        Route::post('/proxy-actions/restart', [ServerProxyActionsController::class, 'restart'])->name('server.proxy-actions.restart');
        Route::post('/proxy-actions/stop', [ServerProxyActionsController::class, 'stop'])->name('server.proxy-actions.stop');
        Route::post('/proxy-actions/start', [ServerProxyActionsController::class, 'start'])->name('server.proxy-actions.start');
        Route::post('/proxy-actions/check-status', [ServerProxyActionsController::class, 'checkStatus'])->name('server.proxy-actions.check-status');
        Route::get('/terminal', [ExecuteContainerCommandController::class, 'server'])->name('server.command')->middleware('can.access.terminal');
        Route::post('/terminal/connect', [ExecuteContainerCommandController::class, 'connectServer'])->name('server.command.connect')->middleware('can.access.terminal');
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
    Route::get('/destinations', [DestinationController::class, 'index'])->name('destination.index');
    Route::post('/destinations', [DestinationController::class, 'store'])->name('destination.store');
    Route::get('/destination/{destination_uuid}', [DestinationController::class, 'show'])->name('destination.show');
    Route::get('/destination/{destination_uuid}/resources', [DestinationController::class, 'resources'])->name('destination.resources');
    Route::put('/destination/{destination_uuid}', [DestinationController::class, 'update'])->name('destination.update');
    Route::delete('/destination/{destination_uuid}', [DestinationController::class, 'destroy'])->name('destination.destroy');

    // Route::get('/security', fn () => view('security.index'))->name('security.index');
    Route::get('/security/private-key', [SecurityPrivateKeyController::class, 'index'])->name('security.private-key.index');
    Route::post('/security/private-key/cleanup', [SecurityPrivateKeyController::class, 'cleanupUnusedKeys'])->name('security.private-key.cleanup');
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
    Route::get('/sources', [SourceGithubController::class, 'index'])->name('source.all');
    Route::post('/source/github', [SourceGithubController::class, 'store'])->name('source.github.store');
    Route::get('/source/github/{github_app_uuid}', [SourceGithubController::class, 'show'])->name('source.github.show');
    Route::get('/source/github/{github_app_uuid}/permissions', [SourceGithubController::class, 'show'])->name('source.github.permissions');
    Route::get('/source/github/{github_app_uuid}/resources', [SourceGithubController::class, 'show'])->name('source.github.resources');
    Route::put('/source/github/{github_app_uuid}', [SourceGithubController::class, 'update'])->name('source.github.update');
    Route::post('/source/github/{github_app_uuid}/update-name', [SourceGithubController::class, 'updateName'])->name('source.github.update-name');
    Route::post('/source/github/{github_app_uuid}/check-permissions', [SourceGithubController::class, 'checkPermissions'])->name('source.github.check-permissions');
    Route::post('/source/github/{github_app_uuid}/instant-save', [SourceGithubController::class, 'instantSaveSystemWide'])->name('source.github.instant-save');
    Route::post('/source/github/{github_app_uuid}/create-manual', [SourceGithubController::class, 'createManual'])->name('source.github.create-manual');
    Route::delete('/source/github/{github_app_uuid}', [SourceGithubController::class, 'destroy'])->name('source.github.destroy');
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

    Route::get('/logs/download/{server_uuid}', [ProjectLogsController::class, 'downloadLogs'])->name('project.logs.download');

});

Route::any('/{any}', function () {
    if (auth()->user()) {
        return redirect(RouteServiceProvider::HOME);
    }

    return redirect()->route('login');
})->where('any', '.*');
