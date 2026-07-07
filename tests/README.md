# Test Infrastructure

This document explains the three "plumbing" files in this folder: `TestCase.php`,
`CreatesApplication.php`, and `Pest.php`. These are **not test cases** — none of
them contain `it()`/`test()` blocks or assertions. They're the scaffolding that
boots the Laravel application and configures Pest/PHPUnit so that your actual
test files can run at all.

If any of these three files is missing, `php artisan test` will fail before it
even reaches your tests (either with a fatal error about a missing class, or
with "No tests found").

---

## `CreatesApplication.php`

```php
trait CreatesApplication
{
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        return $app;
    }
}
```

**What it does:** boots a full instance of the Laravel application for each
test, the exact same way `public/index.php` boots it for a real HTTP request.
It requires `bootstrap/app.php` (the entry point that builds the service
container, registers providers, etc.) and then runs the console kernel's
`bootstrap()` step, which loads config, environment variables, and service
providers.

**Why we need it:** almost nothing in this app works without the app
container — no `config()`, no `Cache::`, no Eloquent models, no dependency
injection. Any test that touches a model (like `Application::factory()`) or a
facade needs a real, booted app instance behind it. This trait is how that
instance gets created before each test runs.

**Why it's a trait, not just code in `TestCase`:** Laravel's testing base
class expects a `createApplication()` method to exist, and ships this as a
swappable trait so different test setups (e.g. a stripped-down console-only
app) could substitute their own version. In practice, almost every Laravel
project uses this exact trait unmodified.

---

## `TestCase.php`

```php
abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
}
```

**What it does:** this is the base class that all of *this application's*
test classes extend (`class ApplicationConfigurationSnapshotTest extends
TestCase`). It extends Laravel's own
`Illuminate\Foundation\Testing\TestCase`, which itself extends PHPUnit's
`TestCase` — so you inherit three layers of functionality:

1. **PHPUnit's `TestCase`** — the base assertion methods (`assertSame`,
   `assertTrue`, `assertNull`, etc.) and the `#[Test]` attribute discovery
   mechanism.
2. **Laravel's `TestCase`** — Laravel-specific helpers like `$this->get()`,
   `$this->postJson()`, `$this->actingAs()`, `RefreshDatabase`, and automatic
   app booting/teardown between tests.
3. **This app's `TestCase`** — currently just wires in `CreatesApplication`,
   but it's also the place to add project-wide test setup later (e.g. global
   `setUp()` logic, custom assertions, shared traits) without editing every
   test file individually.

**Why we need it:** every class-based test in this codebase (`extends
TestCase`) needs *some* concrete class to extend that already knows how to
boot the app. Without it, `Application::factory()->create()` or anything
touching the database would fail immediately, since there'd be no booted
container to resolve the database connection, config, or models from.

**Note on Pest's functional style:** if you write tests using `it(...)` /
`test(...)` instead of a `class ... extends TestCase`, you don't extend this
class directly in the file — instead, `Pest.php`'s `uses(TestCase::class)`
call binds it behind the scenes (see below). Either way, this class is what
ends up backing the test.

---

## `Pest.php`

This is Pest's own configuration file — it's not a test file, and it isn't
autoloaded like a normal class; Pest specifically looks for a file with this
name in the `tests/` directory and runs it once before the suite starts.

It has four jobs, matching its four commented sections:

### 1. Binding a base `TestCase` to functional tests
```php
uses(TestCase::class)->in('Feature', 'v4/Feature', 'v4/Browser');
```
When you write a test with Pest's functional `it('...', function () {...})`
syntax (no `class` keyword), there's no `extends TestCase` anywhere to give
you `$this->assertDatabaseHas(...)` or similar helpers. This line tells Pest
"bind `Tests\TestCase` behind every test file in these folders," so
`$this` inside those closures behaves as if it were an instance of that class.

Note this only covers `Feature`, `v4/Feature`, and `v4/Browser` — **not**
`Unit`. That's a deliberate convention in this codebase: plain Pest tests
in `tests/Unit` are expected to be fast, dependency-free unit tests that
don't need the full app/database (like our `ConfigurationDiffer`/
`ConfigurationDiff` tests). If a `Unit` test *does* need the database, it's
written as an explicit `class ... extends TestCase` instead (like
`ApplicationConfigurationSnapshotTest`), which is why those files always
declare their own class rather than relying on this `uses()` binding.

### 2. Global test hooks
```php
beforeEach(function () {
    Once::flush();
    Server::flushIdentityMap();
});
```
This runs before **every single test in the whole suite**, clearing two
in-memory caches so that a value memoized in one test can't leak into and
affect the next one. Without this, tests could pass or fail depending on
what ran before them — a classic source of flaky, order-dependent tests.

### 3. Custom expectations
```php
// expect()->extend('toBeOne', function () {
//     return $this->toBe(1);
// });
```
Currently unused (commented out), but this is where you'd define custom
`expect(...)->toYourCustomThing()` assertions if you wanted a reusable,
project-specific expectation.

### 4. Shared helper functions
```php
// function something()
// {
//     // ..
// }
```
Also currently unused, but this is the idiomatic place for helper functions
you want available in *every* test file without an explicit `use` or
`require` — e.g. a `makeTestApplication()` factory helper that builds the
Team → Project → Environment → Application chain needed before creating a
test `Application` record.

---

## Quick reference: what breaks without each file

| Missing file | What happens |
|---|---|
| `CreatesApplication.php` | `TestCase.php` fails to compile (`use CreatesApplication` references a trait that doesn't exist) |
| `TestCase.php` | Every `class ... extends TestCase` test file fails to compile; `Pest.php`'s `uses(TestCase::class)` call fails |
| `Pest.php` | Pest itself won't boot — no global hooks, no `uses()` bindings for functional tests in `Feature`/`v4/*` |
