<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function verifyEmailActingAs(): User
{
    $user = User::factory()->unverified()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    test()->actingAs($user)->withSession(['currentTeam' => $team]);

    return $user;
}

it('renders the verify email Inertia page for an unverified user', function () {
    verifyEmailActingAs();

    $response = test()->get(route('verify.email'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('VerifyEmail')
        ->has('resendUrl')
    );
});

it('flashes a graceful error instead of crashing when no transactional email is configured', function () {
    verifyEmailActingAs();

    $response = test()->post(route('verify.resend'));

    $response->assertRedirect();
    $response->assertSessionHas('error');
});

it('rate limits repeated resend attempts within the same window', function () {
    verifyEmailActingAs();

    test()->post(route('verify.resend'));
    $response = test()->post(route('verify.resend'));

    $response->assertRedirect();
    $response->assertSessionHas('error', function ($message) {
        return str_contains((string) $message, 'Too many requests');
    });
});
