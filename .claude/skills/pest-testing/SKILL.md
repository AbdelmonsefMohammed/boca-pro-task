---
name: pest-testing
description: "Tests applications using Pest 5. Activates when writing tests, creating unit or feature tests, adding assertions, testing Livewire components, browser testing, mutation testing, debugging test failures, working with datasets or mocking; or when the user mentions test, spec, TDD, expects, assertion, coverage, mutation score, TIA, or needs to verify functionality works."
license: MIT
metadata:
  author: laravel
  version: "2.0.0"
---

# Pest Testing 5

This project runs **Pest v5.1.1** on **PHP 8.4** (Pest 5 requires PHP 8.4+ and is built on PHPUnit 13). Installed plugins: `pest-plugin-arch`, `pest-plugin-laravel`, `pest-plugin-livewire`, `pest-plugin-mutate`, `pest-plugin-phpstan`, `pest-plugin-profanity`, `pest-plugin-rector`.

## When to Apply

- Creating new tests (unit, feature, or browser)
- Modifying existing tests
- Debugging test failures
- Working with browser testing, mutation testing, or architecture tests

## Documentation

Use `search-docs` for detailed Pest 5 patterns and documentation.

## Test Organization

- Unit/Feature tests: `tests/Feature` and `tests/Unit`, plus each module's own `Modules/*/tests/{Feature,Unit}` (this repo uses `nwidart/laravel-modules`; `tests/Pest.php` binds `TestCase` + `DatabaseTransactions` across `Feature`, `Unit`, and `../Modules/*/tests`).
- Do NOT remove tests without approval — these are core application code.

### Basic Test Structure

```php
it('is true', function () {
    expect(true)->toBeTrue();
});
```

## Running Tests

Prefer this repo's composer scripts over ad-hoc flags — they encode the CI contract:

| Command | Purpose |
|---|---|
| `composer test` | Everything CI runs, in CI's order (rector → pint → phpstan → unit) via `test-local`, or the full suite via `test` |
| `composer test:unit` | `vendor/bin/pest --parallel --coverage --min=85` |
| `vendor/bin/pest --filter=testName` | One test/file while iterating |
| `vendor/bin/pest --parallel --tia` | Test Impact Analysis — only re-runs tests whose covered code changed (first run records a coverage graph via pcov/xdebug, then replays unaffected results) |
| `vendor/bin/pest --mutate` | Mutation testing (see below); add `--parallel` |
| `vendor/bin/pest --profanity` | Profanity scan (see below) |

**Always run these inside Docker, never on the host:** `docker exec -w /var/www/html atlas-octane-app <command>`. Host PHP is the wrong version and/or missing extensions (e.g. Redis) that Larastan/Pest need — this produces misleading failures that look like real bugs but aren't. No exceptions, not even "just to check something quickly."

## Assertions

Use specific assertions (`assertSuccessful()`, `assertNotFound()`) instead of `assertStatus()`:

```php
it('returns all', function () {
    $this->postJson('/api/docs', [])->assertSuccessful();
});
```

| Use | Instead of |
|-----|------------|
| `assertSuccessful()` | `assertStatus(200)` |
| `assertNotFound()` | `assertStatus(404)` |
| `assertForbidden()` | `assertStatus(403)` |

New in Pest 5: validation expectations `toBeEmail()`, `toBeUlid()`, `toBeIpAddress()`, `toBeMacAddress()`, `toBeHostname()`, `toBeDomain()`, `toBeBase64()`, `toBeHexadecimal()`.

## Mocking

Import mock function before use: `use function Pest\Laravel\mock;`

## Datasets

Use datasets for repetitive tests (validation rules, etc.):

```php
it('has emails', function (string $email) {
    expect($email)->not->toBeEmpty();
})->with([
    'james' => 'james@laravel.com',
    'taylor' => 'taylor@laravel.com',
]);
```

## Architecture Testing (`pest-plugin-arch`)

```php
arch('controllers')
    ->expect('App\Http\Controllers')
    ->toExtendNothing()
    ->toHaveSuffix('Controller');
```

## Mutation Testing (`pest-plugin-mutate`)

Finds untested code by introducing small mutations and checking your suite catches them:

```bash
vendor/bin/pest --mutate --parallel
vendor/bin/pest --mutate --covered-only   # restrict to lines your tests actually cover
vendor/bin/pest --mutate --bail           # stop at the first untested/uncovered mutation
```

Ignore a specific line with `// @pest-mutate-ignore`. A mutation score of 100% means every mutation was caught.

## PHPStan Plugin (`pest-plugin-phpstan`)

Already wired into `phpstan.neon` (`vendor/pestphp/pest-plugin-phpstan/extension.neon`). It teaches PHPStan Pest's functional API (`it()`, `expect()` chains) so test files don't produce false-positive static analysis errors — no action needed, but if a *test file* reports a PHPStan error that looks like Pest's own API is "undefined", confirm this include is still present before assuming it's a real bug.

## Rector Plugin (`pest-plugin-rector`)

Ships Rector rules that modernize test code (not currently wired into this repo's `rector.php`). Its `PestSetList::CODING_STYLE` set rewrites raw assertions into Pest matchers, e.g. `expect(count($array))->toBe(3)` → `expect($array)->toHaveCount(3)`, and merges/chains consecutive expectations. To use it, add to `rector.php`:

```php
use Pest\Rector\Set\PestSetList;

->withSets([PestSetList::CODING_STYLE])
```

Preview with `vendor/bin/rector process --dry-run` before applying — see the `rector` skill.

## Profanity Plugin (`pest-plugin-profanity`)

Scans comments/constants/properties for inappropriate language:

```bash
vendor/bin/pest --profanity
vendor/bin/pest --profanity --language=en
```

Exclude a legitimate false positive inline: `// @pest-ignore-profanity`.

## Browser Testing

- Browser tests live in `tests/Browser/`.
- Use Laravel features like `Event::fake()`, `assertAuthenticated()`, and model factories.
- Use `RefreshDatabase` for clean state per test.

```php
it('may reset the password', function () {
    Notification::fake();
    $this->actingAs(User::factory()->create());

    $page = visit('/sign-in');
    $page->assertSee('Sign In')
        ->assertNoJavaScriptErrors()
        ->click('Forgot Password?')
        ->fill('email', 'nuno@laravel.com')
        ->click('Send Reset Link')
        ->assertSee('We have emailed your password reset link!');

    Notification::assertSent(ResetPassword::class);
});
```

## Not Installed (Pest 5 features, FYI only)

- `pest-plugin-agent` — lets an AI coding agent drive the real test suite (`--agent=`) to verify a change. Not in this repo's `composer.json`; don't assume it's available.
- `pest-plugin-evals` — scorers for testing LLM-generated output (`--evals`). Also not installed.

Don't add either without the user's explicit go-ahead.

## Common Pitfalls

- Not importing `use function Pest\Laravel\mock;` before using mock
- Using `assertStatus(200)` instead of `assertSuccessful()`
- Forgetting datasets for repetitive validation tests
- Deleting tests without approval
- Forgetting `assertNoJavaScriptErrors()` in browser tests
- Running Pest/PHP commands on the host instead of inside the app container — version/extension mismatches produce misleading failures
