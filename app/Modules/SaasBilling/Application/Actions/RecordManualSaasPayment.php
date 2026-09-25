<?php

declare(strict_types=1);

namespace App\Modules\SaasBilling\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Platform\Currencies\PlatformCurrencies;
use App\Kernel\Platform\Notifications\PlatformNotifier;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Modules\SaasBilling\Domain\Models\SaasInvoice;
use App\Modules\SaasBilling\Domain\Models\SaasPayment;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records money a center paid Meta Style outside any gateway.
 *
 * Full or partial; never more than the balance, never on a void or settled
 * invoice. The invoice row is locked while the balance is checked, so two
 * people recording the same transfer cannot both succeed.
 */
final class RecordManualSaasPayment
{
    public const METHODS = ['cash', 'bank_transfer', 'manual_electronic', 'other'];

    public function __construct(
        private readonly Audit $audit,
        private readonly PlatformNotifier $notifier,
        private readonly PlatformCurrencies $currencies,
    ) {}

    public function __invoke(SaasInvoice $invoice, int $amountMinor, string $method, Carbon $receivedAt, Actor $actor, ?string $reference = null, ?string $note = null): SaasPayment
    {
        if ($amountMinor <= 0 || ! in_array($method, self::METHODS, true)) {
            throw new DomainException(__('sadmin_billing.errors.payment'));
        }
        if ($receivedAt->isFuture()) {
            throw new DomainException(__('sadmin_billing.errors.received_future'));
        }

        /** @var SaasPayment $payment */
        $payment = DB::connection('control')->transaction(function () use ($invoice, $amountMinor, $method, $receivedAt, $actor, $reference, $note): SaasPayment {
            /** @var SaasInvoice|null $locked */
            $locked = SaasInvoice::query()->lockForUpdate()->find($invoice->id);
            if (! $locked instanceof SaasInvoice || in_array($locked->status, ['void', 'settled'], true)) {
                throw new DomainException(__('sadmin_billing.errors.closed'));
            }
            if ($amountMinor > $locked->total_minor - $locked->paid_minor) {
                throw new DomainException(__('sadmin_billing.errors.exceeds'));
            }

            /** @var SaasPayment $created */
            $created = SaasPayment::query()->create([
                'invoice_id' => $locked->id, 'tenant_id' => $locked->tenant_id, 'method' => $method,
                'amount_minor' => $amountMinor, 'currency' => $locked->currency,
                'reference' => $reference !== null && trim($reference) !== '' ? trim($reference) : null,
                'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'recorded_by_id' => (string) $actor->id, 'recorded_by_label' => (string) $actor->label, 'received_at' => $receivedAt,
            ]);

            $paid = $locked->paid_minor + $amountMinor;
            $settled = $paid === $locked->total_minor;
            $locked->forceFill(['paid_minor' => $paid, 'status' => $settled ? 'settled' : 'partially_paid', 'settled_at' => $settled ? now() : null])->save();

            return $created;
        });

        $this->audit->recordForTenant($invoice->tenant_id, new AuditEvent(
            action: 'platform.saas_payment.recorded',
            category: AuditCategory::Finance,
            actor: $actor,
            targetType: SaasPayment::class,
            targetId: $payment->uuid,
            targetLabel: $invoice->number,
            after: ['amount_minor' => $amountMinor, 'currency' => $invoice->currency, 'method' => $method],
        ));

        $this->notifier->notify('billing.payment_recorded', 'info', 'payment_recorded', [
            'number' => $invoice->number,
            'center' => (string) TenantModel::query()->whereKey($invoice->tenant_id)->value('name'),
            'amount' => $this->currencies->format($amountMinor, $invoice->currency, 'en'),
        ], $invoice->tenant_id, '/billing?invoice='.$invoice->uuid);

        return $payment;
    }
}
