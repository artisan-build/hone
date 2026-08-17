<?php

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Headless auth contract
|--------------------------------------------------------------------------
|
| Hone authenticates source apps with bearer tokens only. It has no session
| users, no users table, and no user model — commit 1a4cd48 stripped the
| users/passkeys/2FA migrations on purpose.
|
| Two traps live here, and both are easy to reintroduce:
|
| 1. `auth.defaults.guard` must still name a resolvable guard. Laravel's
|    ThrottleRequests::resolveRequestSignature() calls $request->user(), so
|    every `throttle:`-protected Built for Cloud route (/bfc/meta, ownership
|    claim, onboarding exchange/verify) 500s with "Auth guard [] is not
|    defined" the moment the default is set to null. Headless does NOT mean
|    "no default guard".
|
| 2. `auth.guards` / `auth.providers` cannot be emptied from this config file.
|    LoadConfiguration::mergeableOptions() deep-merges the framework defaults
|    back in, so `'providers' => []` still yields the eloquent/App\Models\User
|    provider. Declaring a `database` provider here (as the ^0.3 convergence
|    did) overwrites that default with one that returns GenericUser, which can
|    never satisfy Built for Cloud's `bfc.admin` middleware and leaves
|    `create-admin` with no model to resolve.
|
*/

it('keeps a resolvable default guard so throttled routes can sign requests', function (): void {
    expect(config('auth.defaults.guard'))->toBe('web')
        ->and(config('auth.defaults.passwords'))->toBeNull();

    /** Must not throw — this is what ThrottleRequests depends on. */
    expect(Auth::check())->toBeFalse();
    expect(Auth::user())->toBeNull();
});

it('serves throttled Built for Cloud routes without resolving a user', function (): void {
    $this->getJson('/bfc/meta')->assertOk();

    /** Unauthorized is fine; a 500 means the default guard stopped resolving. */
    $this->postJson('/bfc/onboarding/verify')->assertStatus(401);
});

it('never wires the users provider to the database driver', function (): void {
    /** GenericUser is not an Eloquent model, so `bfc.admin` would reject every caller. */
    expect(config('auth.providers.users.driver'))->toBe('eloquent');
});

it('declares no session guard or provider of its own in config/auth.php', function (): void {
    $config = require config_path('auth.php');

    expect($config['guards'])->toBe([])
        ->and($config['providers'])->toBe([]);
});

it('exposes no route behind session authentication middleware', function (): void {
    $offenders = collect(Route::getRoutes())
        ->filter(fn (RoutingRoute $route): bool => collect($route->gatherMiddleware())
            ->contains(fn (mixed $middleware): bool => is_string($middleware) && in_array(
                $middleware,
                ['auth', 'bfc.auth', 'bfc.admin', 'auth.session', 'auth.basic'],
                true,
            )))
        ->map(fn (RoutingRoute $route): string => $route->uri())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

it('has no user model, so nothing may depend on resolving one', function (): void {
    expect(class_exists('App\Models\User'))->toBeFalse();
});
