<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

// handleError() and verifyPasswordConfirmation() previously accepted an extra
// Livewire\Component parameter used only for dead code: handleError()'s
// TooManyRequestsException branch could never match (DanHarrin\LivewireRateLimiting was
// fully uninstalled - `instanceof` against an undefined class name is always false, not a
// crash), and verifyPasswordConfirmation()'s `if ($component)` branch had no real caller
// passing one (all 16 call sites pass exactly one argument). Both signatures were
// simplified to drop the dead parameter; these tests cover the remaining real behavior.
beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('returns a friendly message for a unique constraint violation', function () {
    $exception = new UniqueConstraintViolationException(
        'sqlite', 'insert into x', [], new PDOException('duplicate')
    );

    expect(handleError($exception))->toBe('Duplicate entry found. Please use a different name.');
});

it('aborts with a 404 for a model not found exception', function () {
    expect(fn () => handleError(new ModelNotFoundException))->toThrow(NotFoundHttpException::class);
});

it('throws with the original message for a generic throwable', function () {
    expect(fn () => handleError(new RuntimeException('something went wrong')))
        ->toThrow(Exception::class, 'something went wrong');
});

it('prefixes a custom message when given', function () {
    expect(fn () => handleError(new RuntimeException('something went wrong'), 'Custom prefix:'))
        ->toThrow(Exception::class, 'Custom prefix: something went wrong');
});

it('checks the authenticated users password', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-password')]);
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'member']);

    $this->actingAs($user)->withSession(['currentTeam' => $team]);

    expect(verifyPasswordConfirmation('correct-password'))->toBeTrue();
    expect(verifyPasswordConfirmation('wrong-password'))->toBeFalse();
});
