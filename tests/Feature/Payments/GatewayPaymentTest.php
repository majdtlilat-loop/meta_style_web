<?php

declare(strict_types=1);

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Modules\Finance\Domain\Models\FinanceEntry;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Application\Actions\InitiateGatewayPayment;
use App\Modules\Payments\Application\Actions\ManageGatewayAccount;
use App\Modules\Payments\Application\Actions\ResolveGatewayPayment;
use App\Modules\Payments\Application\GatewaySettlement;
use App\Modules\Payments\Application\InvoiceSettlement;
use App\Modules\Payments\Domain\Data\ProviderPaymentStatus;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\Enums\ProviderState;
use App\Modules\Payments\Domain\Enums\SettlementState;
use App\Modules\Payments\Domain\Enums\WebhookResult;
use App\Modules\Payments\Domain\Exceptions\PaymentFailed;
use App\Modules\Payments\Domain\Models\GatewayAccount;
use App\Modules\Payments\Domain\Models\Payment;
use App\Modules\Payments\Domain\Models\WebhookEvent;
use Tests\Support\FakeGatewayProvider;

/*
|--------------------------------------------------------------------------
| Online payments through a branch's gateway
|--------------------------------------------------------------------------
|
| docs/19-PAYMENTS.md §§10, 15, 21–24, 63–65, 82.
|
|   pending   reserves its amount against the invoice
|   succeeded only from a verified provider answer, amount and currency matched
|   failed / cancelled   release the reservation
|
| Callbacks are driven through the real HTTP route, signed by the test-only
| provider, so routing, tenant resolution and signature checks are all real.
|
*/

/**
 * A provider callback. Phase 15: `public.tenant` resolves the center from its
 * own host, whose slug the `{center}` segment repeats — the URL
 * `GatewayConnections::callbackUrl()` publishes when a payment starts there.
 */
function gpUrl(array $center, string $account): string
{
    return 'http://'.$center['registration']->requested_slug.'.localhost:8000/api/v1/payments/'.$center['registration']->requested_slug.'/gateways/'.$account.'/webhook';
}

/**
 * @return array{0: Payment, 1: string}
 */
function gpStart(array $seed, $owner, int $amount = 20000): array
{
    $account = test()->gatewayAccount($seed['branch']);
    $invoice = test()->issuedInvoice($seed, $owner);

    $payment = app(InitiateGatewayPayment::class)->fromDesk($invoice, $account, $owner, $amount);

    return [$payment, $account->uuid];
}

it('opens a pending payment that reserves its amount, so the desk cannot collect it twice', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPayments();
        $fake = $this->fakeGateway();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        [$payment] = gpStart($seed, $owner, 20000);
        $invoice = $payment->invoice()->firstOrFail();
        $summary = app(InvoiceSettlement::class)->forInvoice($invoice);

        expect($payment->status)->toBe(PaymentStatus::Pending)
            ->and($payment->method)->toBe(PaymentMethod::Gateway)
            ->and($payment->provider_payment_reference)->not->toBeNull()
            ->and($payment->provider_display_code)->toStartWith('CODE')
            ->and($fake->calls)->toBe(['create'])
            ->and($summary->pendingMinor)->toBe(20000)
            ->and($summary->availableCollectibleMinor)->toBe(0)
            // Pending money is not collected money.
            ->and($summary->state)->toBe(SettlementState::Unpaid)
            ->and(FinanceEntry::query()->count())->toBe(0);

        // The cashier cannot take cash the gateway may still bring in.
        expect(fn () => app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 20000))
            ->toThrow(PaymentFailed::class, 'more than is left');
    });
});

it('settles on a verified callback, once, however often it is repeated', function (): void {
    $center = $this->registerCenter();

    [$payment, $account] = $this->asCenter($center['tenant'], function (): array {
        $this->grantPayments();
        $this->fakeGateway();

        return gpStart($this->seedBookableCenter(), $this->ownerWithCatalogAccess());
    });

    [$body, $headers] = FakeGatewayProvider::callback((string) $payment->provider_payment_reference, 'paid', 20000);

    // Providers deliver at least once. Three identical callbacks.
    for ($i = 0; $i < 3; $i++) {
        $this->call('POST', gpUrl($center, $account), [], [], [], $this->transformHeadersToServerVars($headers), $body)->assertStatus(202);
    }

    $this->asCenter($center['tenant'], function () use ($payment): void {
        $fresh = $payment->fresh();

        expect($fresh?->status)->toBe(PaymentStatus::Succeeded)
            ->and(FinanceEntry::query()->where('source_uuid', $payment->uuid)->count())->toBe(1)
            ->and(TenantAuditLog::query()->where('action', 'payment.gateway_succeeded')->count())->toBe(1)
            ->and(WebhookEvent::query()->count())->toBe(1)
            ->and(app(InvoiceSettlement::class)->forInvoice($payment->invoice()->firstOrFail())->state)->toBe(SettlementState::Paid);
    });
});

it('never settles a success whose amount or currency does not match, and records why', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPayments();
        $this->fakeGateway();

        [$payment] = gpStart($this->seedBookableCenter(), $this->ownerWithCatalogAccess());

        $settlement = app(GatewaySettlement::class);
        $actor = Actor::system('test');

        $short = $settlement->apply($payment, new ProviderPaymentStatus((string) $payment->provider_payment_reference, ProviderState::Paid, 19999, 'IQD'), $actor);
        $dollars = $settlement->apply($payment, new ProviderPaymentStatus((string) $payment->provider_payment_reference, ProviderState::Paid, 20000, 'USD'), $actor);
        $unknown = $settlement->apply($payment, new ProviderPaymentStatus((string) $payment->provider_payment_reference, ProviderState::Paid, null, 'IQD'), $actor);
        $otherRef = $settlement->apply($payment, new ProviderPaymentStatus('someone-else', ProviderState::Paid, 20000, 'IQD'), $actor);

        expect([$short, $dollars, $unknown, $otherRef])->each->toBe(WebhookResult::AmountMismatch)
            ->and($payment->fresh()?->status)->toBe(PaymentStatus::Pending)
            ->and(FinanceEntry::query()->count())->toBe(0)
            ->and(TenantAuditLog::query()->where('action', 'payment.gateway_mismatch')->where('severity', 'critical')->count())->toBe(4)
            // Still reserved: the mismatch cannot free money a person must look at.
            ->and(app(InvoiceSettlement::class)->forInvoice($payment->invoice()->firstOrFail())->pendingMinor)->toBe(20000);
    });
});

it('releases the reservation when the provider declines or cancels, and a final payment never moves again', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPayments();
        $this->fakeGateway();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $settlement = app(GatewaySettlement::class);
        $actor = Actor::system('test');

        [$declined] = gpStart($seed, $owner, 20000);
        $invoice = $declined->invoice()->firstOrFail();

        expect($settlement->apply($declined, new ProviderPaymentStatus((string) $declined->provider_payment_reference, ProviderState::Declined, null, null, 'provider_expired'), $actor))
            ->toBe(WebhookResult::Processed);

        expect($declined->fresh()?->status)->toBe(PaymentStatus::Failed)
            ->and($declined->fresh()?->failure_code)->toBe('provider_expired')
            ->and(app(InvoiceSettlement::class)->forInvoice($invoice)->availableCollectibleMinor)->toBe(20000);

        // A late "paid" for a payment already failed changes nothing.
        expect($settlement->apply($declined, new ProviderPaymentStatus((string) $declined->provider_payment_reference, ProviderState::Paid, 20000, 'IQD'), $actor))
            ->toBe(WebhookResult::Ignored);

        $cancelled = app(InitiateGatewayPayment::class)->fromDesk($invoice, GatewayAccount::query()->firstOrFail(), $owner, 20000);
        $settlement->apply($cancelled, new ProviderPaymentStatus((string) $cancelled->provider_payment_reference, ProviderState::Cancelled, null, null), $actor);

        $paid = app(InitiateGatewayPayment::class)->fromDesk($invoice, GatewayAccount::query()->firstOrFail(), $owner, 20000);
        $settlement->apply($paid, new ProviderPaymentStatus((string) $paid->provider_payment_reference, ProviderState::Paid, 20000, 'IQD'), $actor);

        // Succeeded is final: a later decline cannot take it back to pending or failed.
        expect($settlement->apply($paid, new ProviderPaymentStatus((string) $paid->provider_payment_reference, ProviderState::Declined, null, null), $actor))
            ->toBe(WebhookResult::Ignored);

        expect($declined->fresh()?->status)->toBe(PaymentStatus::Failed)
            ->and($cancelled->fresh()?->status)->toBe(PaymentStatus::Cancelled)
            ->and($paid->fresh()?->status)->toBe(PaymentStatus::Succeeded)
            ->and(FinanceEntry::query()->count())->toBe(1)
            ->and(FinanceEntry::query()->value('source_uuid'))->toBe($paid->uuid);
    });
});

it('releases the reservation straight away when the provider cannot open the payment', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPayments();
        $fake = $this->fakeGateway();
        $fake->failCreate = true;
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $account = $this->gatewayAccount($seed['branch']);
        $invoice = $this->issuedInvoice($seed, $owner);

        expect(fn () => app(InitiateGatewayPayment::class)->fromDesk($invoice, $account, $owner, 20000))
            ->toThrow(PaymentFailed::class, 'did not respond');

        $attempt = Payment::query()->firstOrFail();

        expect($attempt->status)->toBe(PaymentStatus::Failed)
            ->and($attempt->failure_code)->toBe('provider_create_failed')
            ->and(app(InvoiceSettlement::class)->forInvoice($invoice)->availableCollectibleMinor)->toBe(20000);
    });
});

it('lets staff resolve a stuck payment by asking the provider, or cancelling through it', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPayments();
        $fake = $this->fakeGateway();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        [$payment] = gpStart($seed, $owner, 20000);
        $reference = (string) $payment->provider_payment_reference;

        // Still unpaid at the provider: nothing changes.
        expect(app(ResolveGatewayPayment::class)->refresh($payment, $owner))->toBe(WebhookResult::StillPending);

        // Cancelled through the provider, then confirmed by asking it.
        $fake->statuses[$reference] = new ProviderPaymentStatus($reference, ProviderState::Cancelled, null, null);
        $cancelled = app(ResolveGatewayPayment::class)->cancel($payment, $owner);

        expect($cancelled->status)->toBe(PaymentStatus::Cancelled)
            ->and($fake->calls)->toContain('cancel');

        // A provider that cannot cancel is not cancelled locally behind its back.
        $second = app(InitiateGatewayPayment::class)->fromDesk(
            $payment->invoice()->firstOrFail(),
            GatewayAccount::query()->firstOrFail(),
            $owner,
            20000,
        );
        $fake->cancellation = false;

        expect(fn () => app(ResolveGatewayPayment::class)->cancel($second, $owner))
            ->toThrow(PaymentFailed::class, 'cannot cancel');

        expect($second->fresh()?->status)->toBe(PaymentStatus::Pending);
    });
});

it('refuses a gateway from another branch, a disabled one, and online payments without the entitlement', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->fakeGateway();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $invoice = $this->issuedInvoice($seed, $owner);
        $account = $this->gatewayAccount($seed['branch']);

        // No `payments`: not even a pending row.
        expect(fn () => app(InitiateGatewayPayment::class)->fromDesk($invoice, $account, $owner, 1000))
            ->toThrow(EntitlementRequired::class);

        $this->grantPayments();

        $otherBranch = $this->seedBranch('Mansour');
        $foreign = $this->gatewayAccount($otherBranch);

        expect(fn () => app(InitiateGatewayPayment::class)->fromDesk($invoice, $foreign, $owner, 1000))
            ->toThrow(PaymentFailed::class, 'different branch');

        app(ManageGatewayAccount::class)->setEnabled($account, $owner, false);

        expect(fn () => app(InitiateGatewayPayment::class)->fromDesk($invoice, $account->fresh() ?? $account, $owner, 1000))
            ->toThrow(PaymentFailed::class, 'not available');

        expect(Payment::query()->count())->toBe(0);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
