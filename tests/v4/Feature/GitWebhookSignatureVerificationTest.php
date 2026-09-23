<?php

declare(strict_types=1);

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

// Regression coverage for issue #294: the 4 Git push-webhook receivers (Github, Gitlab,
// Bitbucket, Gitea manual()) are the entire trust boundary for unauthenticated push-to-deploy -
// anyone who can reach these routes and guess/brute-force past the signature check can trigger a
// real deployment. Before this file, none of the 4 had any test coverage of that boundary.

uses(RefreshDatabase::class);

function makeWebhookTestApplication(array $attrs = []): Application
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destination = $server->standaloneDockers()->first() ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);

    return Application::factory()->create([
        'team_id' => $team->id,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'git_repository' => 'https://github.com/acme/widget',
        'git_branch' => 'main',
        ...$attrs,
    ]);
}

function webhookPushPayloadJson(array $payload): string
{
    return json_encode($payload);
}

// --- GitHub ---

it('queues a deployment when the GitHub push webhook signature is valid', function () {
    Queue::fake();
    $application = makeWebhookTestApplication();
    $payload = ['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'acme/widget'], 'commits' => []];
    $body = webhookPushPayloadJson($payload);
    $signature = 'sha256='.hash_hmac('sha256', $body, $application->manual_webhook_secret_github);

    $response = $this->postJson('/webhooks/source/github/events/manual', $payload, [
        'X-GitHub-Event' => 'push',
        'X-Hub-Signature-256' => $signature,
    ]);

    $response->assertOk();
    Queue::assertPushed(ApplicationDeploymentJob::class);
});

it('rejects the GitHub push webhook when the signature does not match the payload', function () {
    Queue::fake();
    makeWebhookTestApplication();
    $payload = ['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'acme/widget'], 'commits' => []];

    $response = $this->postJson('/webhooks/source/github/events/manual', $payload, [
        'X-GitHub-Event' => 'push',
        'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', webhookPushPayloadJson($payload), 'wrong-secret'),
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['status' => 'failed', 'message' => 'Invalid signature.']);
    Queue::assertNotPushed(ApplicationDeploymentJob::class);
});

it('rejects the GitHub push webhook when no signature header is sent at all', function () {
    Queue::fake();
    makeWebhookTestApplication();
    $payload = ['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'acme/widget'], 'commits' => []];

    $response = $this->postJson('/webhooks/source/github/events/manual', $payload, [
        'X-GitHub-Event' => 'push',
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['status' => 'failed', 'message' => 'Invalid signature.']);
    Queue::assertNotPushed(ApplicationDeploymentJob::class);
});

it('rejects the GitHub push webhook when the application has no webhook secret configured', function () {
    Queue::fake();
    makeWebhookTestApplication(['manual_webhook_secret_github' => null]);
    $payload = ['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'acme/widget'], 'commits' => []];

    $response = $this->postJson('/webhooks/source/github/events/manual', $payload, [
        'X-GitHub-Event' => 'push',
        'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', webhookPushPayloadJson($payload), 'anything'),
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['status' => 'failed', 'message' => 'Invalid signature.']);
    Queue::assertNotPushed(ApplicationDeploymentJob::class);
});

// --- GitLab ---
// GitLab does not use HMAC - the app's manual_webhook_secret_gitlab is sent directly as the
// X-Gitlab-Token header and compared with hash_equals().

it('queues a deployment when the GitLab push webhook token is valid', function () {
    Queue::fake();
    $application = makeWebhookTestApplication();
    $payload = ['object_kind' => 'push', 'ref' => 'refs/heads/main', 'project' => ['path_with_namespace' => 'acme/widget'], 'commits' => []];

    $response = $this->postJson('/webhooks/source/gitlab/events/manual', $payload, [
        'X-Gitlab-Token' => $application->manual_webhook_secret_gitlab,
    ]);

    $response->assertOk();
    Queue::assertPushed(ApplicationDeploymentJob::class);
});

it('rejects the GitLab push webhook when the token is wrong', function () {
    Queue::fake();
    makeWebhookTestApplication();
    $payload = ['object_kind' => 'push', 'ref' => 'refs/heads/main', 'project' => ['path_with_namespace' => 'acme/widget'], 'commits' => []];

    $response = $this->postJson('/webhooks/source/gitlab/events/manual', $payload, [
        'X-Gitlab-Token' => 'wrong-token',
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['status' => 'failed', 'message' => 'Invalid signature.']);
    Queue::assertNotPushed(ApplicationDeploymentJob::class);
});

it('rejects the GitLab push webhook when no token header is sent at all', function () {
    Queue::fake();
    makeWebhookTestApplication();
    $payload = ['object_kind' => 'push', 'ref' => 'refs/heads/main', 'project' => ['path_with_namespace' => 'acme/widget'], 'commits' => []];

    $response = $this->postJson('/webhooks/source/gitlab/events/manual', $payload);

    $response->assertOk();
    $response->assertJsonFragment(['status' => 'failed', 'message' => 'Invalid signature.']);
    Queue::assertNotPushed(ApplicationDeploymentJob::class);
});

it('rejects the GitLab push webhook when the application has no webhook secret configured', function () {
    Queue::fake();
    makeWebhookTestApplication(['manual_webhook_secret_gitlab' => null]);
    $payload = ['object_kind' => 'push', 'ref' => 'refs/heads/main', 'project' => ['path_with_namespace' => 'acme/widget'], 'commits' => []];

    $response = $this->postJson('/webhooks/source/gitlab/events/manual', $payload, [
        'X-Gitlab-Token' => 'anything',
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['status' => 'failed', 'message' => 'Invalid signature.']);
    Queue::assertNotPushed(ApplicationDeploymentJob::class);
});

// --- Bitbucket ---
// Bitbucket signs via X-Hub-Signature: "sha256=<hmac>", parsed by hand in the controller.

it('queues a deployment when the Bitbucket push webhook signature is valid', function () {
    Queue::fake();
    $application = makeWebhookTestApplication();
    $payload = [
        'push' => ['changes' => [['new' => ['name' => 'main', 'target' => ['hash' => 'abc123']]]]],
        'repository' => ['full_name' => 'acme/widget'],
    ];
    $body = webhookPushPayloadJson($payload);
    $signature = 'sha256='.hash_hmac('sha256', $body, $application->manual_webhook_secret_bitbucket);

    $response = $this->postJson('/webhooks/source/bitbucket/events/manual', $payload, [
        'X-Event-Key' => 'repo:push',
        'X-Hub-Signature' => $signature,
    ]);

    $response->assertOk();
    Queue::assertPushed(ApplicationDeploymentJob::class);
});

it('rejects the Bitbucket push webhook when the signature does not match the payload', function () {
    Queue::fake();
    makeWebhookTestApplication();
    $payload = [
        'push' => ['changes' => [['new' => ['name' => 'main', 'target' => ['hash' => 'abc123']]]]],
        'repository' => ['full_name' => 'acme/widget'],
    ];

    $response = $this->postJson('/webhooks/source/bitbucket/events/manual', $payload, [
        'X-Event-Key' => 'repo:push',
        'X-Hub-Signature' => 'sha256='.hash_hmac('sha256', webhookPushPayloadJson($payload), 'wrong-secret'),
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['status' => 'failed', 'message' => 'Invalid signature.']);
    Queue::assertNotPushed(ApplicationDeploymentJob::class);
});

it('rejects the Bitbucket push webhook when the signature header is malformed', function () {
    // Regression guard for the hand-rolled explode('=', $header, 2) parse in
    // Bitbucket::manual() - a header with no "sha256=" prefix (or no "=" at all) must be
    // rejected, not silently misparsed into a spoofable comparison.
    Queue::fake();
    $application = makeWebhookTestApplication();
    $payload = [
        'push' => ['changes' => [['new' => ['name' => 'main', 'target' => ['hash' => 'abc123']]]]],
        'repository' => ['full_name' => 'acme/widget'],
    ];

    $response = $this->postJson('/webhooks/source/bitbucket/events/manual', $payload, [
        'X-Event-Key' => 'repo:push',
        'X-Hub-Signature' => hash_hmac('sha256', webhookPushPayloadJson($payload), $application->manual_webhook_secret_bitbucket),
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['status' => 'failed', 'message' => 'Invalid signature.']);
    Queue::assertNotPushed(ApplicationDeploymentJob::class);
});

it('rejects the Bitbucket push webhook when no signature header is sent at all', function () {
    Queue::fake();
    makeWebhookTestApplication();
    $payload = [
        'push' => ['changes' => [['new' => ['name' => 'main', 'target' => ['hash' => 'abc123']]]]],
        'repository' => ['full_name' => 'acme/widget'],
    ];

    $response = $this->postJson('/webhooks/source/bitbucket/events/manual', $payload, [
        'X-Event-Key' => 'repo:push',
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['status' => 'failed', 'message' => 'Invalid signature.']);
    Queue::assertNotPushed(ApplicationDeploymentJob::class);
});

it('rejects the Bitbucket push webhook when the application has no webhook secret configured', function () {
    Queue::fake();
    makeWebhookTestApplication(['manual_webhook_secret_bitbucket' => null]);
    $payload = [
        'push' => ['changes' => [['new' => ['name' => 'main', 'target' => ['hash' => 'abc123']]]]],
        'repository' => ['full_name' => 'acme/widget'],
    ];

    $response = $this->postJson('/webhooks/source/bitbucket/events/manual', $payload, [
        'X-Event-Key' => 'repo:push',
        'X-Hub-Signature' => 'sha256='.hash_hmac('sha256', webhookPushPayloadJson($payload), 'anything'),
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['status' => 'failed', 'message' => 'Invalid signature.']);
    Queue::assertNotPushed(ApplicationDeploymentJob::class);
});

// --- Gitea ---

it('queues a deployment when the Gitea push webhook signature is valid', function () {
    Queue::fake();
    $application = makeWebhookTestApplication();
    $payload = ['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'acme/widget'], 'commits' => []];
    $body = webhookPushPayloadJson($payload);
    $signature = 'sha256='.hash_hmac('sha256', $body, $application->manual_webhook_secret_gitea);

    $response = $this->postJson('/webhooks/source/gitea/events/manual', $payload, [
        'X-Gitea-Event' => 'push',
        'X-Hub-Signature-256' => $signature,
    ]);

    $response->assertOk();
    Queue::assertPushed(ApplicationDeploymentJob::class);
});

it('rejects the Gitea push webhook when the signature does not match the payload', function () {
    Queue::fake();
    makeWebhookTestApplication();
    $payload = ['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'acme/widget'], 'commits' => []];

    $response = $this->postJson('/webhooks/source/gitea/events/manual', $payload, [
        'X-Gitea-Event' => 'push',
        'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', webhookPushPayloadJson($payload), 'wrong-secret'),
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['status' => 'failed', 'message' => 'Invalid signature.']);
    Queue::assertNotPushed(ApplicationDeploymentJob::class);
});

it('rejects the Gitea push webhook when no signature header is sent at all', function () {
    Queue::fake();
    makeWebhookTestApplication();
    $payload = ['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'acme/widget'], 'commits' => []];

    $response = $this->postJson('/webhooks/source/gitea/events/manual', $payload, [
        'X-Gitea-Event' => 'push',
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['status' => 'failed', 'message' => 'Invalid signature.']);
    Queue::assertNotPushed(ApplicationDeploymentJob::class);
});

it('rejects the Gitea push webhook when the application has no webhook secret configured', function () {
    Queue::fake();
    makeWebhookTestApplication(['manual_webhook_secret_gitea' => null]);
    $payload = ['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'acme/widget'], 'commits' => []];

    $response = $this->postJson('/webhooks/source/gitea/events/manual', $payload, [
        'X-Gitea-Event' => 'push',
        'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', webhookPushPayloadJson($payload), 'anything'),
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['status' => 'failed', 'message' => 'Invalid signature.']);
    Queue::assertNotPushed(ApplicationDeploymentJob::class);
});
