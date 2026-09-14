<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The gates every sales operation passes, in one place.
 *
 *   1. the CENTER owns `pos`            — commercial, docs/05 — NEW operations only
 *   2. the USER holds the permission    — never a role name, no Owner bypass
 *   3. the user may work at the BRANCH  — a sale always belongs to one
 *
 * ## `pos` gates what happens next, not what already happened
 *
 * The downgrade rule Booking set in Phase 6 (docs/05-ENTITLEMENTS.md §6.2):
 * mutations require the entitlement, READING existing records does not. A sale
 * that was finalized and an invoice that was published are financial history.
 * A center that loses POS can no longer open a cart, check a visit out, change
 * a draft, finalize, void, run a shift or manage products — {@see ensure()} —
 * but its staff still read the sales and invoices they already issued, and the
 * customer's link keeps working — {@see authorize()}.
 *
 * Printing is its own commercial key, `printing`, checked by the print surfaces
 * only: it gates the PAPER, not the history, so it does not also need `pos`
 * (docs/18-SALES.md §§15, 23).
 */
final class SalesAccess
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * A NEW commercial operation: `pos`, the permission, the branch.
     *
     * @throws EntitlementRequired
     * @throws AuthorizationException
     */
    public function ensure(User $user, Permission $permission, int $branchId, string $refusal): void
    {
        $this->entitlements->ensure('pos');

        $this->authorize($user, $permission, $branchId, $refusal);
    }

    /**
     * Reading — or securing — what was already recorded: the permission and the
     * branch, never the entitlement.
     *
     * @throws AuthorizationException
     */
    public function authorize(User $user, Permission $permission, int $branchId, string $refusal): void
    {
        if (! $user->hasPermission($permission)) {
            throw new AuthorizationException($refusal);
        }

        if ($branchId > 0 && ! $user->canAccessBranch($branchId)) {
            throw new AuthorizationException('You may not work in that branch.');
        }
    }

    /**
     * The 80mm / A4 paper surfaces: `printing`, `invoice.print`, the branch.
     *
     * @throws EntitlementRequired
     * @throws AuthorizationException
     */
    public function ensurePrinting(User $user, int $branchId): void
    {
        $this->entitlements->ensure('printing');

        $this->authorize($user, Permission::InvoicePrint, $branchId, 'You may not print invoices.');
    }
}
