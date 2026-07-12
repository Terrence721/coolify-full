<?php

declare(strict_types=1);

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/
uses(TestCase::class)->in('Feature', 'v4/Feature', 'v4/Browser');

/*
|--------------------------------------------------------------------------
| Test Hooks
|--------------------------------------------------------------------------
|
| Global hooks that run before/after each test.
|
*/
// NOTE: this used to live here as a plain beforeEach(), but a test file's own local
// beforeEach() silently shadows a global one instead of composing with it in Pest, so
// this was never actually running for the majority of the suite (every file with its
// own `beforeEach(fn () => InstanceSettings::forceCreate(...))`, which is most of
// tests/v4/Feature/). Moved to Tests\TestCase::setUp(), which runs unconditionally for
// every test regardless of what beforeEach() closures a file declares — see its
// docblock for the full story of how this was found.

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

// expect()->extend('toBeOne', function () {
//     return $this->toBe(1);
// });

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

// function something()
// {
//     // ..
// }
