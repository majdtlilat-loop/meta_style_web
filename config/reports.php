<?php

return [
    /*
     * Operational declaration for deploy readiness. Entitlements remain the
     * commercial source of truth per tenant; this flag only tells the doctor
     * whether missing replica credentials are a warning or a release blocker.
     */
    'advanced_enabled' => (bool) env('REPORTS_ADVANCED_ENABLED', false),

    'interactive_max_days' => 366,
    'export_max_rows' => 100000,
];
