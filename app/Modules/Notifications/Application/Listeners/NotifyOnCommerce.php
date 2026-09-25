<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application\Listeners;

use App\Kernel\Database\AfterCommit;
use App\Modules\Memberships\Domain\Events\MembershipActivated;
use App\Modules\Memberships\Domain\Models\CustomerMembership;
use App\Modules\Notifications\Application\CustomerInboxes;
use App\Modules\Notifications\Application\NotificationCenter;
use App\Modules\Notifications\Domain\Data\NotificationRequest;
use App\Modules\Notifications\Domain\Enums\NotificationType;
use App\Modules\Packages\Domain\Events\PackageActivated;
use App\Modules\Packages\Domain\Models\CustomerPackage;
use App\Modules\Sales\Domain\Events\SaleFinalized;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\Sales\Domain\Models\Sale;

/**
 * The customer's bill, and the benefits they just paid for.
 *
 * ## Money commits first, and this can never get in its way
 *
 * The same rule benefits follow (ADR-061), for the same reason. `SaleFinalized`
 * is dispatched inside the finalization transaction and
 * `MembershipActivated` / `PackageActivated` inside the activation that already
 * runs after a payment settled. Everything here is scheduled for after THAT
 * commits, and a failure is reported and dropped: an invoice that was issued
 * stays issued whether or not the customer's inbox could be written
 * (docs/23-NOTIFICATIONS.md §11).
 *
 * ## No money in the payload
 *
 * `invoice_issued` carries the invoice NUMBER and nothing else — not the total,
 * not the currency, not a line. The customer opens their invoice through their
 * own link; a notification is a pointer, not a second copy of a financial
 * document that could disagree with the first (§7).
 *
 * And nothing here names a plan or a package: those names are translatable, and
 * baking one language into a stored row would show a customer who switched to
 * Kurdish an English plan name forever. The message is general and the row
 * carries the uuid the screen links to.
 */
final class NotifyOnCommerce
{
    public function __construct(
        private readonly NotificationCenter $center,
        private readonly CustomerInboxes $inboxes,
        private readonly AfterCommit $afterCommit,
    ) {}

    public function handleSaleFinalized(SaleFinalized $event): void
    {
        $this->afterCommit->run('notifications.invoice_issued', function () use ($event): void {
            /** @var Sale|null $sale */
            $sale = Sale::query()->whereKey($event->saleId)->first();

            if (! $sale instanceof Sale || $sale->customer_id === null) {
                return;
            }

            /** @var Invoice|null $invoice */
            $invoice = Invoice::query()->where('sale_id', $sale->getKey())->first();

            if (! $invoice instanceof Invoice) {
                return;
            }

            $recipients = $this->inboxes->forCustomer((int) $sale->customer_id);

            if ($recipients === []) {
                return;
            }

            $this->center->deliver(new NotificationRequest(
                type: NotificationType::InvoiceIssued,
                sourceType: 'invoice',
                sourceUuid: $invoice->uuid,
                params: ['number' => $invoice->number],
                recipients: $recipients,
                branchId: (int) $invoice->branch_id,
            ));
        });
    }

    public function handleMembershipActivated(MembershipActivated $event): void
    {
        $this->afterCommit->run('notifications.membership_activated', function () use ($event): void {
            $uuid = CustomerMembership::query()->whereKey($event->customerMembershipId)->value('uuid');

            $this->announceBenefit(NotificationType::MembershipActivated, 'customer_membership', $uuid, $event->customerId);
        });
    }

    public function handlePackageActivated(PackageActivated $event): void
    {
        $this->afterCommit->run('notifications.package_activated', function () use ($event): void {
            $uuid = CustomerPackage::query()->whereKey($event->customerPackageId)->value('uuid');

            $this->announceBenefit(NotificationType::PackageActivated, 'customer_package', $uuid, $event->customerId);
        });
    }

    private function announceBenefit(NotificationType $type, string $sourceType, mixed $sourceUuid, int $customerId): void
    {
        if (! is_string($sourceUuid)) {
            return;
        }

        $recipients = $this->inboxes->forCustomer($customerId);

        if ($recipients === []) {
            return;
        }

        $this->center->deliver(new NotificationRequest(
            type: $type,
            sourceType: $sourceType,
            sourceUuid: $sourceUuid,
            recipients: $recipients,
        ));
    }
}
