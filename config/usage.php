<?php

/*
|--------------------------------------------------------------------------
| Metered resources and their commercial allowances
|--------------------------------------------------------------------------
|
| docs/26-USAGE-QUOTAS.md §§1–5.
|
| The catalog of things Meta Style COUNTS, owned by configuration for exactly
| the reason the entitlement catalog is owned by code (ADR-006): a resource code
| written into a `usage_events` row must be a code that exists, and a catalog in
| the database would let a deploy write rows nothing can interpret.
|
| It lives in config rather than in a Kernel enum for a different reason. The
| codes below describe RAYAN and WhatsApp — business concepts the Kernel must
| never learn. `Kernel\Usage` validates against this list and can therefore stay
| entirely generic: it knows there is a resource called `ai_runs`, and nothing
| whatsoever about when RAYAN should run (Phase 13 correction 3).
|
| ## Hard versus metered
|
| `enforced => true` means a request is REFUSED when the allowance is spent.
| `ai_runs` and `advanced_report_ai_runs` are separate enforced product run
| allowances, and that is a deliberate, narrow claim:
|
|   - a RUN is a discrete thing that can be refused before it starts, so
|     "50,000 runs a month" is a promise the code can actually keep;
|   - TOKENS are not. Their count is only known AFTER the provider answers, so a
|     hard monthly token cap would need reserve-then-settle semantics — an
|     estimate held before the call and trued up after it — and guessing at that
|     would produce a limit that both overcounts and leaks (§42).
|
| So tokens are METERED: recorded exactly, shown to the manager, billable, and
| never used to refuse anything. Nothing anywhere claims a hard token cap.
|
*/

use App\Kernel\Usage\UsageStatus;

return [

    /*
     * The billing period.
     *
     * `subscription` uses the tenant's own `current_period_start` /
     * `current_period_end` when the control plane has them, so usage resets
     * when the center is billed rather than on a day that means nothing to
     * them. When there is no authoritative period — a trial, a plan with no
     * billing dates yet — it falls back to a deterministic UTC calendar month.
     *
     * NEVER the server's local timezone. A period boundary computed in
     * `Asia/Baghdad` on one host and `UTC` on another silently gives a center
     * two different months (§50).
     */
    'period' => [
        'strategy' => env('METASTYLE_USAGE_PERIOD', 'subscription'),
    ],

    /*
     * Where the alert thresholds sit, and what state each one puts a resource
     * in.
     *
     * One alert per tenant + resource + period + threshold, enforced by a
     * unique index rather than by remembering — so an hourly sweep, a retry and
     * a burst of fifty messages all produce exactly one 85% warning (§52).
     */
    'thresholds' => [
        ['percent' => 70, 'status' => UsageStatus::Warning],
        ['percent' => 85, 'status' => UsageStatus::High],
        ['percent' => 100, 'status' => UsageStatus::Exhausted],
    ],

    /*
     * The resources themselves.
     *
     * `default` is the system fallback allowance, used when neither a tenant
     * override nor the plan says anything. NULL MEANS UNLIMITED — and it means
     * that everywhere, consistently. There is no sentinel: a plan that grants
     * "unlimited" stores NULL, never 999999999, because a fake number is a
     * number somebody eventually hits (§40).
     */
    'resources' => [

        // ---- RAYAN ---------------------------------------------------
        'ai_runs' => ['group' => 'ai', 'enforced' => true, 'default' => 500],
        'advanced_report_ai_runs' => ['group' => 'advanced_reports', 'enforced' => true, 'default' => 100],
        'ai_input_tokens' => ['group' => 'ai', 'enforced' => false, 'default' => null],
        'ai_output_tokens' => ['group' => 'ai', 'enforced' => false, 'default' => null],
        'ai_tool_calls' => ['group' => 'ai', 'enforced' => false, 'default' => null],
        'ai_failed_runs' => ['group' => 'ai', 'enforced' => false, 'default' => null],

        // ---- WhatsApp ------------------------------------------------
        'wa_inbound' => ['group' => 'whatsapp', 'enforced' => false, 'default' => null],
        'wa_outbound' => ['group' => 'whatsapp', 'enforced' => false, 'default' => null],
        'wa_template' => ['group' => 'whatsapp', 'enforced' => false, 'default' => null],
        'wa_failed' => ['group' => 'whatsapp', 'enforced' => false, 'default' => null],
    ],

];
