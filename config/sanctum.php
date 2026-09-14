<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Sanctum
|--------------------------------------------------------------------------
|
| Meta Style uses Sanctum for API tokens only. There is no SPA cookie mode:
| the first-party web UI is Blade + Livewire on a normal session, so the
| stateful-domain machinery has nothing to do here (docs/DECISIONS.md ADR-020).
|
| Tokens are stored in the TENANT database via a custom model, which is what
| makes them tenant-bound (ADR-027).
|
*/

return [

    // Empty: no cookie-based SPA authentication.
    'stateful' => [],

    'guard' => ['web'],

    /*
     * Tokens expire. A staff token that lives forever is a credential nobody
     * remembers issuing, on a device nobody remembers owning.
     */
    'expiration' => env('SANCTUM_TOKEN_EXPIRATION', 60 * 24 * 30),

    'token_prefix' => '',

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
