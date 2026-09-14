<?php

use App\Kernel\Identity\Models\User;
use App\Modules\Customers\Domain\Models\CustomerAccount;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
|
| Meta Style has three genuinely different principals, each with its own table,
| password policy and permission model (docs/06-AUTH-ROLES-PERMISSIONS.md §1).
| Phase 3 implements ONE of them:
|
|   staff     tenant-database `users` — owner, manager, host, cashier, employee
|   platform  control-plane super admins and support           — not yet built
|   customer  tenant-database `customers`, guests included     — Phase 5
|
| The other two are absent rather than stubbed. Phase 3 has no SADMIN surface
| and no customers, and standing up authentication systems nothing authenticates
| against is how a codebase acquires guards nobody understands.
|
| Note that the staff provider resolves against the TENANT database. Every
| lookup therefore requires an initialised tenant, and a query with none raises
| TenantConnectionNotInitialized rather than silently reaching the control plane.
|
*/

return [

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'staff'),
    ],

    'guards' => [
        // First-party web (Blade + Livewire), standard Laravel session.
        'web' => [
            'driver' => 'session',
            'provider' => 'staff',
        ],

        // API clients. Tokens live in the tenant database and carry the
        // tenant's public key, so a Tenant A token cannot authenticate against
        // Tenant B (docs/DECISIONS.md ADR-027).
        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => 'staff',
        ],

        /*
         * Customers. Separate guards and a separate provider, so a customer is
         * never authenticated by staff middleware or the reverse.
         *
         * The separation is enforced by the framework rather than by care:
         * Sanctum compares a token's owner against its guard's provider model,
         * so a CustomerAccount token presented to `auth:sanctum` is refused
         * and a User token presented to `auth:customer-api` likewise
         * (docs/13-ROADMAP.md Phase 5 §5).
         */
        'customer' => [
            'driver' => 'session',
            'provider' => 'customers',
        ],

        'customer-api' => [
            'driver' => 'sanctum',
            'provider' => 'customers',
        ],
    ],

    'providers' => [
        'staff' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],

        // Also resolves against the TENANT database, for the same reason: a
        // lookup with no tenant initialised must fail rather than reach the
        // control plane.
        'customers' => [
            'driver' => 'eloquent',
            'model' => CustomerAccount::class,
        ],
    ],

    'passwords' => [
        'staff' => [
            'provider' => 'staff',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
