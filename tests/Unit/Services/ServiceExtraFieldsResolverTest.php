<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\Team;
use App\Services\ServiceExtraFieldsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function extraFieldsMakeService(): Service
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->destinations()->first();

    return Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
    ]);
}

it('surfaces the Castopod disable-HTTPS field when the sub-application image matches and the env var exists', function () {
    $service = extraFieldsMakeService();
    ServiceApplication::create(['name' => 'castopod', 'service_id' => $service->id, 'image' => 'castopod/castopod:latest']);
    $service->environment_variables()->create([
        'key' => 'CP_DISABLE_HTTPS',
        'value' => '1',
        'resourceable_id' => $service->id,
        'resourceable_type' => $service->getMorphClass(),
        'is_preview' => false,
    ]);

    $fields = (new ServiceExtraFieldsResolver)->resolve($service);

    expect($fields->has('Castopod'))->toBeTrue();
    expect($fields->get('Castopod')['Disable HTTPS']['key'])->toBe('CP_DISABLE_HTTPS');
    expect($fields->get('Castopod')['Disable HTTPS']['value'])->toBe('1');
});

it('omits the Castopod field entirely when the env var does not exist', function () {
    $service = extraFieldsMakeService();
    ServiceApplication::create(['name' => 'castopod', 'service_id' => $service->id, 'image' => 'castopod/castopod:latest']);

    $fields = (new ServiceExtraFieldsResolver)->resolve($service);

    expect($fields->get('Castopod'))->toBeEmpty();
});

it('adds only an empty Admin entry for sub-applications with no recognized image', function () {
    $service = extraFieldsMakeService();
    ServiceApplication::create(['name' => 'plain', 'service_id' => $service->id, 'image' => 'nginx:latest']);

    $fields = (new ServiceExtraFieldsResolver)->resolve($service);

    expect($fields->keys()->all())->toBe(['Admin']);
    expect($fields->get('Admin'))->toBeEmpty();
});

it('saves submitted field values as environment variables, updating an existing one', function () {
    $service = extraFieldsMakeService();
    $existing = $service->environment_variables()->create([
        'key' => 'CP_DISABLE_HTTPS',
        'value' => '1',
        'resourceable_id' => $service->id,
        'resourceable_type' => $service->getMorphClass(),
        'is_preview' => false,
    ]);

    (new ServiceExtraFieldsResolver)->save($service, [
        ['key' => 'CP_DISABLE_HTTPS', 'value' => '0'],
    ]);

    expect($existing->fresh()->value)->toBe('0');
    expect($service->environment_variables()->where('key', 'CP_DISABLE_HTTPS')->count())->toBe(1);
});

it('saves submitted field values as environment variables, creating a new one', function () {
    $service = extraFieldsMakeService();

    (new ServiceExtraFieldsResolver)->save($service, [
        ['key' => 'NEW_FIELD', 'value' => 'hello'],
    ]);

    $created = $service->environment_variables()->where('key', 'NEW_FIELD')->first();
    expect($created)->not->toBeNull();
    expect($created->value)->toBe('hello');
});
