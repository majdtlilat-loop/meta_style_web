<?php

declare(strict_types=1);

namespace App\Kernel\Entitlements;

/**
 * What kind of question an entitlement answers.
 *
 * Phase 3 implements Boolean only — "does this tenant own the capability" is
 * all the engine needs to gate features. Limits (max branches, max staff) and
 * metered quotas (WhatsApp messages, AI requests) are documented in
 * docs/05-ENTITLEMENTS.md §2 and arrive with the features that enforce them:
 * a limit nothing checks is a number in a table.
 */
enum EntitlementType: string
{
    case Boolean = 'boolean';
}
