<?php

declare(strict_types=1);

test('isAssociativeArray', function () {
    expect(isAssociativeArray([1, 2, 3]))->toBeFalse();
    expect(isAssociativeArray(collect([1, 2, 3])))->toBeFalse();
    expect(isAssociativeArray(collect(['a' => 1, 'b' => 2, 'c' => 3])))->toBeTrue();
});
