<?php

declare(strict_types=1);

namespace App\Modules\SaasBilling\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Platform\Currencies\PlatformCurrencies;
use App\Kernel\Platform\Notifications\PlatformNotifier;
use App\Kernel\SaaS\Models\Subscription;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Modules\SaasBilling\Application\InvoiceTemplateSettings;
use App\Modules\SaasBilling\Domain\Models\SaasInvoice;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues a SaaS invoice to a center for its subscription.
 *
 * The invoice keeps its own snapshot — currency, plan, billing cycle and the
 * billed period — so a later plan or currency change never rewrites what was
 * billed. Amounts are integer minor units in the subscription's currency.
 */
final class IssueSaasInvoice
{
    public function __construct(
        private readonly Audit $audit,
        private readonly PlatformNotifier $notifier,
        private readonly PlatformCurrencies $currencies,
        private readonly InvoiceTemplateSettings $template,
    ) {}

    public function __invoke(
        Subscription $subscription,
        string $description,
        int $amountMinor,
        Carbon $dueAt,
        Actor $actor,
        ?string $notes = null,
        int $discountMinor = 0,
        ?string $reference = null,
        ?string $cycle = null,
        ?Carbon $periodStart = null,
        ?Carbon $periodEnd = null,
    ): SaasInvoice {
        $description = trim($description);
        $cycle ??= $subscription->billing_period_snapshot ?? $subscription->plan?->billing_period;
        if ($amountMinor <= 0 || $description === '' || $dueAt->isPast()) {
            throw new DomainException(__('sadmin_billing.errors.invoice'));
        }
        if ($discountMinor < 0 || $discountMinor >= $amountMinor) {
            throw new DomainException(__('sadmin_billing.errors.discount'));
        }
        if ($cycle !== null && ! in_array($cycle, ['monthly', 'yearly', 'quarterly'], true)) {
            throw new DomainException(__('sadmin_billing.errors.cycle'));
        }
        $periodStart ??= $subscription->current_period_start;
        $periodEnd ??= $subscription->current_period_end;
        if ($periodStart !== null && $periodEnd !== null && $periodEnd->lessThan($periodStart)) {
            throw new DomainException(__('sadmin_billing.errors.period'));
        }

        $currency = (string) ($subscription->currency_snapshot ?: $subscription->plan?->currency ?: $this->currencies->defaultCode());
        $total = $amountMinor - $discountMinor;

        /** @var SaasInvoice $invoice */
        $invoice = DB::connection('control')->transaction(function () use ($subscription, $description, $amountMinor, $discountMinor, $total, $dueAt, $notes, $reference, $currency, $cycle, $periodStart, $periodEnd): SaasInvoice {
            /** @var SaasInvoice $created */
            $created = SaasInvoice::query()->create([
                'number' => 'MS-'.now()->format('Ym').'-'.mb_strtoupper(Str::random(8)),
                'tenant_id' => $subscription->tenant_id,
                'subscription_id' => $subscription->id,
                'plan_id' => $subscription->plan_id,
                'plan_name_snapshot' => $subscription->plan_name_snapshot ?? $subscription->plan?->name->all(),
                // Who is billing, frozen with the invoice (the template's styling is not).
                'issuer_snapshot' => $this->template->issuer(),
                'billing_period' => $cycle,
                'status' => 'issued',
                'currency' => $currency,
                'subtotal_minor' => $amountMinor,
                'discount_minor' => $discountMinor,
                'total_minor' => $total,
                'paid_minor' => 0,
                'period_start' => $periodStart?->toDateString(),
                'period_end' => $periodEnd?->toDateString(),
                'issued_at' => now(),
                'due_at' => $dueAt,
                'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
                'reference' => $reference !== null && trim($reference) !== '' ? trim($reference) : null,
            ]);
            $created->items()->create(['description' => $description, 'quantity' => 1, 'unit_price_minor' => $amountMinor, 'total_minor' => $amountMinor]);

            return $created;
        });

        $this->audit->recordForTenant($subscription->tenant_id, new AuditEvent(
            action: 'platform.saas_invoice.issued',
            category: AuditCategory::Finance,
            actor: $actor,
            targetType: SaasInvoice::class,
            targetId: $invoice->uuid,
            targetLabel: $invoice->number,
            after: ['subtotal_minor' => $amountMinor, 'discount_minor' => $discountMinor, 'total_minor' => $total, 'currency' => $currency, 'billing_period' => $cycle],
        ));

        $center = (string) TenantModel::query()->whereKey($subscription->tenant_id)->value('name');
        $this->notifier->notify('billing.invoice_issued', 'info', 'invoice_issued', [
            'number' => $invoice->number,
            'center' => $center,
            'amount' => $this->currencies->format($total, $currency, 'en'),
        ], $subscription->tenant_id, '/billing?invoice='.$invoice->uuid);

        return $invoice;
    }
}
