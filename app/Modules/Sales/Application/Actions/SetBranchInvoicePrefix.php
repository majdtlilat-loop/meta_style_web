<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Sales\Application\SalesAccess;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\InvoicePrefix;
use App\Modules\Sales\Domain\Models\Invoice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Sets the code a branch's invoice numbers start with.
 *
 * Branch configuration, so `branch.manage`. It changes FUTURE numbers only:
 * published invoices keep the number they were issued with, and the branch-year
 * sequence continues underneath the new prefix.
 *
 * A prefix already printed on ANOTHER branch's invoices is refused, because
 * reusing it would collide with a number that already exists on paper
 * (docs/18-SALES.md §17, ADR-055).
 */
final class SetBranchInvoicePrefix
{
    public function __construct(
        private readonly SalesAccess $access,
        private readonly Audit $audit,
    ) {}

    /**
     * @throws SaleFailed
     * @throws AuthorizationException
     */
    public function __invoke(string $branchUuid, User $actingUser, ?string $prefix): Branch
    {
        /** @var Branch|null $branch */
        $branch = Branch::query()->where('uuid', $branchUuid)->first();

        if (! $branch instanceof Branch) {
            throw SaleFailed::policy('That branch could not be found.');
        }

        $this->access->ensure($actingUser, Permission::BranchManage, $branch->id, 'You may not configure that branch.');

        $normalised = InvoicePrefix::normalise($prefix, $branch->is_main);

        if ($normalised !== null) {
            $inUse = Branch::query()
                ->where('invoice_prefix', $normalised)
                ->whereKeyNot($branch->getKey())
                ->exists();

            $printed = Invoice::query()
                ->where('prefix', $normalised)
                ->where('branch_id', '!=', $branch->getKey())
                ->exists();

            if ($inUse || $printed) {
                throw SaleFailed::policy('Another branch already uses that invoice prefix.');
            }
        }

        $before = $branch->invoice_prefix;

        try {
            $branch->forceFill(['invoice_prefix' => $normalised])->save();
        } catch (UniqueConstraintViolationException) {
            throw SaleFailed::policy('Another branch already uses that invoice prefix.');
        }

        $this->audit->record(new AuditEvent(
            action: 'sales.invoice_prefix_set',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: Branch::class,
            targetId: $branch->uuid,
            before: ['invoice_prefix' => $before],
            after: ['invoice_prefix' => $normalised],
        ));

        return $branch;
    }
}
