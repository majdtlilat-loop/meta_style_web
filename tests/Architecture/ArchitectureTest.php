<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Architecture rules
|--------------------------------------------------------------------------
|
| Executable versions of docs/04-MODULE-BOUNDARIES.md §2 and
| docs/01-ARCHITECTURE.md §4.
|
| Only rules that can fire today are here. Rules that need code which does not
| exist yet (module-to-module imports, tenant model connections, keeping
| Stancl\* inside the tenancy adapter) are added with the code they govern —
| an arch test over an empty namespace passes for the wrong reason.
|
*/

arch('the kernel never depends on business modules')
    ->expect('App\Kernel')
    ->not->toUse('App\Modules');

arch('no debugging leftovers reach the repository')
    ->expect(['dd', 'dump', 'var_dump', 'print_r', 'ray', 'die', 'exit'])
    ->not->toBeUsed();

arch('env() is only read in config, so config caching is safe')
    ->expect('env')
    ->not->toBeUsed();

arch('application code declares strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('enums are backed so their values are stable across releases')
    ->expect('App\Kernel\Http\ApiErrorCode')
    ->toBeStringBackedEnum();

/*
 * Controllers validate, call one Action, and return a Resource. The rule worth
 * enforcing mechanically is the negative one: no raw database access, because
 * that is where authorization and business logic start leaking out of the
 * Actions that are supposed to own them (docs/01-ARCHITECTURE.md §4).
 *
 * "Thin" itself is not expressible as an assertion, and a line-count rule would
 * be a stylistic preference dressed up as architecture.
 */
arch('controllers do not reach for the database directly')
    ->expect('App\Http\Controllers')
    ->not->toUse([
        'Illuminate\Support\Facades\DB',
        'Illuminate\Database\Query\Builder',
    ]);

arch('controllers are final')
    ->expect('App\Http\Controllers')
    ->classes()
    ->toBeFinal()
    ->ignoring('App\Http\Controllers\Controller');

arch('kernel services are final unless designed for extension')
    ->expect('App\Kernel')
    ->classes()
    ->toBeFinal()
    ->ignoring('App\Kernel\Tenancy\Exceptions');

/*
|--------------------------------------------------------------------------
| Tenancy package boundary (ADR-018)
|--------------------------------------------------------------------------
|
| The whole value of wrapping stancl/tenancy is that the package stays
| replaceable. That only holds while `Stancl\*` references are confined to the
| adapter — without this rule the wrapper decays into "unwrapped" within a few
| sprints, and nothing announces it.
|
| The allowed paths are narrow on purpose: the infrastructure adapter, and the
| service provider that wires it up.
|
*/

arch('stancl/tenancy stays behind the Meta Style tenancy adapter')
    ->expect('Stancl')
    ->toOnlyBeUsedIn([
        'App\Kernel\Tenancy\Infrastructure',
        'App\Providers\TenancyServiceProvider',
    ]);

arch('business code never reaches for the tenancy package directly')
    ->expect('App\Modules')
    ->not->toUse('Stancl');

arch('the tenancy contracts do not leak the package into their signatures')
    ->expect('App\Kernel\Tenancy\Contracts')
    ->not->toUse('Stancl');

arch('storage and audit kernels depend on tenancy only through its contracts')
    ->expect(['App\Kernel\Storage', 'App\Kernel\Audit'])
    ->not->toUse('App\Kernel\Tenancy\Infrastructure');

/*
|--------------------------------------------------------------------------
| Layering (docs/04-MODULE-BOUNDARIES.md §2)
|--------------------------------------------------------------------------
*/

arch('identity and authorization do not depend on business modules')
    ->expect(['App\Kernel\Identity', 'App\Kernel\Authorization'])
    ->not->toUse('App\Modules');

arch('the localization kernel stays free of business modules')
    ->expect('App\Kernel\Localization')
    ->not->toUse('App\Modules');
