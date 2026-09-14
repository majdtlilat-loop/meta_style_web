<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application\Actions;

use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Sales\Application\InvoiceLinks;
use App\Modules\Sales\Application\SalesAccess;
use App\Modules\Sales\Application\SalesAudit;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\Sales\Domain\Models\InvoiceShareLink;
use App\Modules\Sales\Domain\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Replaces an invoice's customer link: the old URL stops working at once, a new
 * one is issued and its secret returned — once.
 *
 * For the invoice link that was forwarded to the wrong person, or photographed
 * on a counter — and for the desk that simply needs to send a customer their
 * invoice again, since a stored secret cannot be read back. Revoke, then create,
 * under a lock on the live link, so an invoice never has two working links and
 * never zero for longer than the transaction (docs/18-SALES.md §20).
 *
 * ## Not gated on `pos`
 *
 * Revoking a credential to a document the center already published is a
 * security control over history, not a new commercial operation. A center that
 * lost POS must still be able to kill a leaked link. It still takes
 * `sale.finalize` and the invoice's branch.
 *
 * Neither secret is audited, nor its digest. An audit row is readable by more
 * people than a customer's invoice link should be.
 */
final class RotateInvoiceLink
{
    public function __construct(
        private readonly SalesAccess $access,
        private readonly InvoiceLinks $links,
        private readonly SalesAudit $audit,
    ) {}

    /**
     * @return string the new plaintext secret; compose the URL with
     *                {@see InvoiceLinks::url()} and never store it
     *
     * @throws SaleFailed
     * @throws AuthorizationException
     */
    public function __invoke(Invoice $invoice, User $actingUser, ?CarbonImmutable $now = null): string
    {
        $this->access->authorize($actingUser, Permission::SaleFinalize, $invoice->branch_id, 'You may not reissue invoice links.');

        $at = ($now ?? CarbonImmutable::now())->utc();

        /** @var string $secret */
        $secret = DB::connection('tenant')->transaction(function () use ($invoice, $actingUser, $at): string {
            /** @var InvoiceShareLink|null $current */
            $current = InvoiceShareLink::query()
                ->where('active_invoice_id', $invoice->getKey())
                ->lockForUpdate()
                ->first();

            if ($current instanceof InvoiceShareLink) {
                $current->forceFill(['active_invoice_id' => null, 'revoked_at' => $at])->save();
            }

            $secret = $this->links->mint($invoice, $actingUser);

            /** @var Sale $sale */
            $sale = Sale::query()->whereKey($invoice->sale_id)->firstOrFail();

            $this->audit->record('invoice.link_rotated', $actingUser, $sale,
                meta: ['invoice' => $invoice->uuid, 'previous_revoked' => $current instanceof InvoiceShareLink],
                severity: AuditSeverity::Notice,
            );

            return $secret;
        });

        return $secret;
    }
}
