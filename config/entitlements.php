<?php

use App\Kernel\Entitlements\EntitlementType;

/*
|--------------------------------------------------------------------------
| Entitlement catalog
|--------------------------------------------------------------------------
|
| The capabilities Meta Style sells, owned by application CODE rather than by
| database rows (docs/DECISIONS.md ADR-006).
|
| Why code: a key referenced by `Entitlements::ensure('pos')` must exist. If the
| catalog lived only in the database, a deploy could ship code referencing a key
| before the row existed, and every environment would drift. Here the catalog is
| identical everywhere by construction; only the GRANTS — plans, add-ons,
| per-tenant overrides — are data.
|
| Keys are permanent. Renaming one silently changes what existing subscriptions
| own; deprecate instead.
|
| An entitlement is NOT a permission. This answers "does this tenant own the
| feature". Whether a given user may use it is a separate gate
| (docs/06-AUTH-ROLES-PERMISSIONS.md).
|
| Phase 3 implements the resolution engine, not the modules. Keys below are
| declared so plans can grant them and the dependency graph is real; the
| features themselves arrive in their own phases.
|
*/

return [

    'entitlements' => [

        // ---- Core operations -------------------------------------------
        'booking' => ['type' => EntitlementType::Boolean, 'category' => 'core'],
        'customer_accounts' => ['type' => EntitlementType::Boolean, 'category' => 'core'],
        'crm' => ['type' => EntitlementType::Boolean, 'category' => 'engagement', 'requires' => ['customer_accounts']],

        // ---- Queue -----------------------------------------------------
        'queue_management' => ['type' => EntitlementType::Boolean, 'category' => 'queue'],
        'queue_display' => ['type' => EntitlementType::Boolean, 'category' => 'queue', 'requires' => ['queue_management']],
        'queue_voice' => ['type' => EntitlementType::Boolean, 'category' => 'queue', 'requires' => ['queue_management']],

        // ---- Commerce --------------------------------------------------
        'pos' => ['type' => EntitlementType::Boolean, 'category' => 'commerce'],
        'printing' => ['type' => EntitlementType::Boolean, 'category' => 'commerce'],
        'payments' => ['type' => EntitlementType::Boolean, 'category' => 'commerce', 'requires' => ['pos']],
        'finance' => ['type' => EntitlementType::Boolean, 'category' => 'commerce', 'requires' => ['pos']],
        'inventory' => ['type' => EntitlementType::Boolean, 'category' => 'commerce', 'requires' => ['pos']],

        // ---- Engagement ------------------------------------------------
        'loyalty' => ['type' => EntitlementType::Boolean, 'category' => 'engagement', 'requires' => ['customer_accounts']],
        'memberships' => ['type' => EntitlementType::Boolean, 'category' => 'engagement', 'requires' => ['pos']],
        'packages' => ['type' => EntitlementType::Boolean, 'category' => 'engagement', 'requires' => ['pos']],

        // ---- Insight ---------------------------------------------------
        'reports_advanced' => ['type' => EntitlementType::Boolean, 'category' => 'insight'],

        // ---- Channels --------------------------------------------------
        'whatsapp_booking' => ['type' => EntitlementType::Boolean, 'category' => 'channels', 'requires' => ['booking']],
        'rayan_ai' => ['type' => EntitlementType::Boolean, 'category' => 'channels'],
        'white_label_app' => ['type' => EntitlementType::Boolean, 'category' => 'channels'],
    ],

];
