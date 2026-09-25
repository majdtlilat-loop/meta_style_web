<?php

declare(strict_types=1);

namespace App\Modules\SaasBilling\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Modules\SaasBilling\Domain\Models\SaasInvoice;
use App\Modules\SaasBilling\Domain\Models\SaasPayment;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Corrections to SaaS billing, without destroying history.
 *
 *  - a settlement recorded by mistake is REVERSED: the row stays, marked with
 *    who reversed it, when and why, and the invoice balance reopens
 *  - an invoice issued by mistake is VOIDED: only while nothing is paid on it
 *    (reverse the settlements first); the row stays, marked void
 *
 * Nothing here deletes a row.
 */
final class CorrectSaasBilling
{
    public function __construct(private readonly Audit $audit) {}

    public function reversePayment(SaasPayment $payment, Actor $actor, string $reason): SaasPayment
    {
        $reason = $this->reason($reason);

        [$payment, $invoice] = DB::connection('control')->transaction(function () use ($payment, $actor, $reason): array {
            /** @var SaasPayment|null $lockedPayment */
            $lockedPayment = SaasPayment::query()->lockForUpdate()->find($payment->id);
            /** @var SaasInvoice|null $invoice */
            $invoice = SaasInvoice::query()->lockForUpdate()->find($lockedPayment?->invoice_id);
            if (! $lockedPayment instanceof SaasPayment || ! $invoice instanceof SaasInvoice) {
                throw new DomainException(__('sadmin_billing.errors.missing'));
            }
            if ($lockedPayment->reversed_at !== null) {
                throw new DomainException(__('sadmin_billing.errors.already_reversed'));
            }
            if ($invoice->status === 'void') {
                throw new DomainException(__('sadmin_billing.errors.closed'));
            }

            $lockedPayment->forceFill([
                'reversed_at' => now(),
                'reversed_by_id' => $actor->id,
                'reversed_by_label' => $actor->label,
                'reversal_reason' => $reason,
            ])->save();

            $paid = max(0, $invoice->paid_minor - $lockedPayment->amount_minor);
            $invoice->forceFill([
                'paid_minor' => $paid,
                'status' => $paid === 0 ? 'issued' : 'partially_paid',
                'settled_at' => null,
            ])->save();

            return [$lockedPayment, $invoice];
        });

        $this->audit->recordForTenant($invoice->tenant_id, new AuditEvent(
            action: 'platform.saas_payment.reversed',
            category: AuditCategory::Finance,
            actor: $actor,
            severity: AuditSeverity::Warning,
            targetType: SaasPayment::class,
            targetId: $payment->uuid,
            targetLabel: $invoice->number,
            before: ['amount_minor' => $payment->amount_minor, 'reversed' => false],
            after: ['reversed' => true, 'invoice_paid_minor' => $invoice->paid_minor],
            reason: $reason,
        ));

        return $payment;
    }

    public function voidInvoice(SaasInvoice $invoice, Actor $actor, string $reason): SaasInvoice
    {
        $reason = $this->reason($reason);

        $invoice = DB::connection('control')->transaction(function () use ($invoice, $actor, $reason): SaasInvoice {
            /** @var SaasInvoice|null $locked */
            $locked = SaasInvoice::query()->lockForUpdate()->find($invoice->id);
            if (! $locked instanceof SaasInvoice) {
                throw new DomainException(__('sadmin_billing.errors.missing'));
            }
            if ($locked->status === 'void') {
                throw new DomainException(__('sadmin_billing.errors.already_void'));
            }
            if ($locked->paid_minor > 0) {
                throw new DomainException(__('sadmin_billing.errors.void_paid'));
            }

            $locked->forceFill([
                'status' => 'void',
                'voided_at' => now(),
                'void_reason' => $reason,
                'voided_by_label' => $actor->label,
            ])->save();

            return $locked;
        });

        $this->audit->recordForTenant($invoice->tenant_id, new AuditEvent(
            action: 'platform.saas_invoice.voided',
            category: AuditCategory::Finance,
            actor: $actor,
            severity: AuditSeverity::Warning,
            targetType: SaasInvoice::class,
            targetId: $invoice->uuid,
            targetLabel: $invoice->number,
            before: ['status' => 'issued'],
            after: ['status' => 'void'],
            reason: $reason,
        ));

        return $invoice;
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new DomainException(__('sadmin_billing.errors.reason'));
        }

        return $reason;
    }
}
