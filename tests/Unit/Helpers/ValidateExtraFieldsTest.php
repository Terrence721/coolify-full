<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class);

it('returns null when the request is clean', function () {
    $validator = Validator::make(['name' => 'ok'], ['name' => 'required|string']);

    $result = validateExtraFields(['name' => 'ok'], ['name'], $validator);

    expect($result)->toBeNull();
});

it('rejects a field not in the allowed list, even when the validator itself passes', function () {
    $validator = Validator::make(['name' => 'ok'], ['name' => 'required|string']);

    $result = validateExtraFields(['name' => 'ok', 'extra' => 'nope'], ['name'], $validator);

    expect($result)->not->toBeNull();
    $data = json_decode($result->getContent(), true);
    expect($data['message'])->toBe('Validation failed.');
    expect($data['errors']['extra'])->toBe(['This field is not allowed.']);
});

it('still surfaces the validator failure when there are no extra fields', function () {
    $validator = Validator::make(['name' => ''], ['name' => 'required|string']);

    $result = validateExtraFields(['name' => ''], ['name'], $validator);

    expect($result)->not->toBeNull();
    $data = json_decode($result->getContent(), true);
    expect($data['errors'])->toHaveKey('name');
});

it('works in extraFields-only mode with no validator at all', function () {
    $clean = validateExtraFields(['name' => 'ok'], ['name'], null);
    expect($clean)->toBeNull();

    $rejected = validateExtraFields(['name' => 'ok', 'extra' => 'nope'], ['name'], null);
    expect($rejected)->not->toBeNull();
    $data = json_decode($rejected->getContent(), true);
    expect($data['errors']['extra'])->toBe(['This field is not allowed.']);
});

it('merges errors from multiple validators and fails if either fails', function () {
    $validatorA = Validator::make(['name' => ''], ['name' => 'required|string']);
    $validatorB = Validator::make(['email' => 'not-an-email'], ['email' => 'required|email']);

    $result = validateExtraFields(['name' => '', 'email' => 'not-an-email'], ['name', 'email'], [$validatorA, $validatorB]);

    expect($result)->not->toBeNull();
    $data = json_decode($result->getContent(), true);
    expect($data['errors'])->toHaveKeys(['name', 'email']);
});

it('accepts a pre-merged allowed-fields list for a multi-source allowlist', function () {
    $backupConfigFields = ['schedule', 'retention'];
    $extra = ['backup_now'];

    $clean = validateExtraFields(['schedule' => '* * * * *', 'backup_now' => true], array_merge($backupConfigFields, $extra));
    expect($clean)->toBeNull();

    $rejected = validateExtraFields(['schedule' => '* * * * *', 'unexpected' => true], array_merge($backupConfigFields, $extra));
    expect($rejected)->not->toBeNull();
});
