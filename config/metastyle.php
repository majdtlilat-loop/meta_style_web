<?php

/*
|--------------------------------------------------------------------------
| Meta Style platform configuration
|--------------------------------------------------------------------------
|
| Meta Style's own knobs, kept separate from framework and package config so
| it is obvious which settings are ours.
|
| Business configuration (trial length, default plan, rate limits) does NOT
| belong here — that is control-plane data editable without a deploy
| (docs/12-DEPLOYMENT-MIGRATION-STRATEGY.md §9).
|
*/

return [

    'tenancy' => [
        /*
         * Prefix for tenant database names, completed by the zero-padded
         * internal tenant sequence: tenant_000001, tenant_000002, ...
         *
         * Configurable for one reason: the test suite creates and drops real
         * databases, and must not be able to touch a developer's actual tenant
         * databases. Tests override this so every database they create carries
         * the test prefix and is safely droppable.
         *
         * The value is validated before it ever reaches SQL — see
         * App\Kernel\Tenancy\TenantDatabaseName.
         */
        'database_prefix' => env('METASTYLE_TENANT_DB_PREFIX', 'tenant_'),
    ],

    'saas' => [
        /*
         * Bootstrap default only. The authoritative value is the
         * `default_trial_days` platform setting, which Super Admin changes
         * without a deploy; this is what seeds it and what applies before it
         * has been set. No business logic contains a literal trial length.
         */
        'default_trial_days' => 14,

        /*
         * Plan code new self-registrations start on, until the
         * `default_plan_code` platform setting says otherwise.
         */
        'default_plan_code' => 'trial',
    ],

    'contact' => [
        /*
         * The country a bare national phone number belongs to.
         *
         * `0750 123 4567` means +964 750 123 4567 in Iraq and something else
         * everywhere. Guessing would file customers under the wrong country
         * code, so the default is explicit (ADR-039).
         */
        'default_country' => env('METASTYLE_DEFAULT_COUNTRY', 'IQ'),

        'countries' => [
            'IQ' => ['dialing_code' => '964', 'trunk_prefix' => '0'],
            'AE' => ['dialing_code' => '971', 'trunk_prefix' => '0'],
            'TR' => ['dialing_code' => '90', 'trunk_prefix' => '0'],
            'JO' => ['dialing_code' => '962', 'trunk_prefix' => '0'],
        ],
    ],

    'money' => [
        /*
         * The currency a newly provisioned center starts on. Centers change it
         * in settings; it is not a per-service column, because one center with
         * two currencies in one price list is a bug, not a feature
         * (docs/10-API-FOUNDATION.md §9).
         */
        'default_currency' => env('METASTYLE_DEFAULT_CURRENCY', 'IQD'),
    ],

    'catalog' => [
        /*
         * Upload limits for catalog imagery. Deliberately modest: these are
         * menu photographs viewed on a phone, and a 20 MB original costs the
         * customer their data allowance.
         */
        'media' => [
            'max_bytes' => 5 * 1024 * 1024,
            'max_images_per_service' => 8,
            'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
            'max_dimension' => 4000,
        ],
    ],

    'registration' => [
        /*
         * How long a registration may sit in `preparing` before it is treated
         * as stuck. Provisioning takes seconds; anything near this is a fault.
         */
        'provisioning_timeout_minutes' => 15,

        /*
         * How long a FAILED registration stays retryable.
         *
         * The bootstrap credential (an encrypted bcrypt hash) survives exactly
         * this long, because a retry needs it to create the owner account.
         * After it, the sweep destroys the credential and marks the
         * registration abandoned.
         *
         * The trade-off is explicit: longer means a better chance the owner
         * comes back and succeeds; shorter means less time holding a
         * credential nobody may ever use (ADR-031).
         */
        'retry_window_hours' => env('METASTYLE_REGISTRATION_RETRY_WINDOW_HOURS', 24),

        /*
         * How long a SUCCESSFUL registration stays readable with its access
         * token (ADR-035).
         *
         * The client is polling when provisioning succeeds. Destroying the
         * capability at that instant would refuse the very next poll — and that
         * poll is how the owner learns their center key. Read-only: retry
         * requires status `failed`, which `ready` cannot return to.
         */
        'status_grace_minutes' => env('METASTYLE_REGISTRATION_STATUS_GRACE_MINUTES', 60),
    ],

    'booking' => [
        /*
         * Defaults for a newly provisioned center. The authoritative values are
         * per-center, in the tenant's own `settings` table — see
         * App\Modules\Booking\Domain\BookingSettings.
         *
         * FOUR SETTINGS, NOT A POLICY MODULE. Buffers between appointments,
         * per-service lead times, cancellation fees, deposit rules, per-branch
         * horizons and no-show penalties are all real requests that will
         * eventually arrive — and every one of them needs a center to have
         * asked, because a policy engine built on guesses is a policy engine
         * nobody can configure (docs/13-ROADMAP.md Phase 6 §28).
         */

        /*
         * The grid public booking offers times on.
         *
         * Not a business rule and not hardcoded in the engine: a laser center
         * running 45-minute treatments wants 15, a barbershop doing 20-minute
         * cuts may want 10. Slot generation reads it; nothing else does.
         */
        'slot_interval_minutes' => 15,

        /*
         * How far ahead anyone may book. Bounds the availability search as much
         * as it bounds the customer — an unbounded date range is an unbounded
         * query.
         */
        'max_advance_days' => 60,

        /*
         * The shortest notice a booking may be made with. Zero means "any time
         * from now", which is correct for a walk-in-heavy market; a center that
         * needs preparation time raises it.
         */
        'min_lead_minutes' => 0,

        /*
         * How long before the appointment a CUSTOMER may still cancel their
         * own booking. Staff are not bound by it — the desk has to be able to
         * cancel anything.
         *
         * Zero means "until it starts". Never means "after it started": a
         * customer cancelling a visit already under way is a no-show, and
         * calling it a cancellation would misreport it.
         */
        'customer_cancel_notice_minutes' => 0,

        /*
         * The widest date range a calendar query may ask for. A guard on the
         * read model, not a product rule: an unbounded range is how one screen
         * loads a year of appointments into a browser (§22).
         */
        'max_calendar_days' => 31,
    ],

];
