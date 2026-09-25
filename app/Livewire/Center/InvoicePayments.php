<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Livewire\Center\PosFinance\GuardsMoneyActions;
use App\Livewire\Center\PosFinance\MoneyLabels;
use App\Livewire\Center\PosFinance\PaymentRows;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Application\Actions\InitiateGatewayPayment;
use App\Modules\Payments\Application\Actions\RequestRefund;
use App\Modules\Payments\Application\Actions\ResolveGatewayPayment;
use App\Modules\Payments\Application\InvoiceSettlement;
use App\Modules\Payments\Application\PaymentsPresenter;
use App\Modules\Payments\Application\PaymentsQuery;
use App\Modules\Payments\Application\UsableGateways;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Exceptions\PaymentFailed;
use App\Modules\Payments\Domain\Models\Payment;
use App\Modules\Sales\Domain\Models\Invoice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * An invoice's money: total, paid, pending, remaining — and taking it.
 *
 * Embedded in the till and the sales screen. A split bill is just several
 * presses: 50,000 in cash, the rest by transfer or through the branch's online
 * gateway. The desk never does arithmetic: what is left comes from
 * `InvoiceSettlement`, what a payment can still give back from the same class,
 * and whether an amount may be taken is the Action's decision under the
 * invoice lock (docs/19-PAYMENTS.md §59).
 *
 * Amounts typed by a person are parsed with `Money::fromMajorString` in the
 * invoice's currency — never through a float. "Fill in what is left" writes
 * the server's figure back as text; it is a parse in reverse, not a sum.
 */
final class InvoicePayments extends Component
{
    use GuardsMoneyActions;

    #[Locked]
    public string $invoice = '';

    public string $cashAmount = '';

    public string $manualAmount = '';

    public string $manualLabel = '';

    public string $manualReference = '';

    public string $gatewayAccount = '';

    public string $gatewayAmount = '';

    public string $refundPayment = '';

    public string $refundAmount = '';

    public string $refundMethod = 'cash';

    public string $refundReason = '';

    /** One token per form submission; renewed after each success. */
    #[Locked]
    public string $token = '';

    public string $error = '';

    public string $saved = '';

    public function mount(string $invoice): void
    {
        $this->invoice = $invoice;
        $this->token = (string) Str::uuid();
    }

    public function collectCash(CollectDeskPayment $collect, PaymentsQuery $query): void
    {
        $this->collect(function () use ($collect, $query): void {
            $invoice = $query->invoice($this->invoice, $this->user());

            $collect($invoice, $this->user(), PaymentMethod::Cash, $this->amount($this->cashAmount, $invoice), null, null, $this->token);

            $this->reset(['cashAmount']);
            $this->saved = (string) __('Cash payment recorded.');
        });
    }

    public function collectManual(CollectDeskPayment $collect, PaymentsQuery $query): void
    {
        $this->collect(function () use ($collect, $query): void {
            $invoice = $query->invoice($this->invoice, $this->user());

            $collect(
                $invoice,
                $this->user(),
                PaymentMethod::ManualElectronic,
                $this->amount($this->manualAmount, $invoice),
                $this->manualLabel,
                $this->manualReference === '' ? null : $this->manualReference,
                $this->token,
            );

            $this->reset(['manualAmount', 'manualLabel', 'manualReference']);
            $this->saved = (string) __('Transfer recorded as confirmed by the desk.');
        });
    }

    public function startGateway(InitiateGatewayPayment $initiate, PaymentsQuery $query): void
    {
        $this->collect(function () use ($initiate, $query): void {
            $invoice = $query->invoice($this->invoice, $this->user());
            $account = $query->gatewayAccount($this->gatewayAccount, $this->user());

            $payment = $initiate->fromDesk($invoice, $account, $this->user(), $this->amount($this->gatewayAmount, $invoice), $this->token);

            $this->reset(['gatewayAmount']);
            $this->saved = $payment->provider_display_code !== null
                ? (string) __('Online payment started. The customer pays with code :code.', ['code' => $payment->provider_display_code])
                : (string) __('Online payment started.');
        });
    }

    /**
     * Writes what is left to collect into one of the amount fields, as text.
     */
    public function fillRemaining(string $field, PaymentsQuery $query, InvoiceSettlement $settlement): void
    {
        if (! in_array($field, ['cashAmount', 'manualAmount', 'gatewayAmount'], true)) {
            return;
        }

        $this->attempt(function () use ($field, $query, $settlement): void {
            $invoice = $query->invoice($this->invoice, $this->user());
            $left = $settlement->forInvoice($invoice)->availableCollectibleMinor;

            $this->{$field} = Money::fromMinor(max(0, $left), $this->currencyOf($invoice))->toMajorString();
        });
    }

    public function refresh(string $paymentUuid, ResolveGatewayPayment $resolve, PaymentsQuery $query): void
    {
        $this->attempt(function () use ($paymentUuid, $resolve, $query): void {
            $result = $resolve->refresh($this->ownPayment($paymentUuid, $query), $this->user());

            $this->saved = (string) __('manager_pos.payments.checked', ['result' => __('manager_pos.webhook_result.'.$result->value)]);
        });
    }

    public function cancel(string $paymentUuid, ResolveGatewayPayment $resolve, PaymentsQuery $query): void
    {
        $this->attempt(function () use ($paymentUuid, $resolve, $query): void {
            $payment = $resolve->cancel($this->ownPayment($paymentUuid, $query), $this->user());

            $this->saved = (string) __('manager_pos.payments.now', ['status' => MoneyLabels::paymentStatus($payment->status->value)]);
        });
    }

    /**
     * Choosing a payment to refund prefills what it can still give back — the
     * server's figure — and a way back it allows. Both stay editable.
     */
    public function updatedRefundPayment(string $uuid): void
    {
        if ($uuid === '') {
            return;
        }

        try {
            $payment = $this->ownPayment($uuid, app(PaymentsQuery::class));
        } catch (AuthorizationException|NotFoundHttpException) {
            $this->refundPayment = '';

            return;
        }

        $this->refundAmount = Money::fromMinor(InvoiceSettlement::refundableOf($payment), Currency::tryFrom($payment->currency) ?? Currency::default())->toMajorString();
        $this->refundMethod = $payment->method->value;
        $this->refundReason = '';
    }

    public function refund(RequestRefund $refund, PaymentsQuery $query): void
    {
        $this->collect(function () use ($refund, $query): void {
            $payment = $this->ownPayment($this->refundPayment, $query);
            $method = PaymentMethod::tryFrom($this->refundMethod) ?? throw PaymentFailed::policy('Choose how the money goes back.');
            $invoice = $query->invoice($this->invoice, $this->user());

            $refund($payment, $this->user(), $method, $this->amount($this->refundAmount, $invoice), $this->refundReason, $this->token);

            $this->reset(['refundPayment', 'refundAmount', 'refundReason']);
            $this->saved = (string) __('Refund recorded.');
        });
    }

    public function render(PaymentsQuery $query, InvoiceSettlement $settlement, PaymentsPresenter $presenter, UsableGateways $usable, Entitlements $entitlements): View
    {
        $user = $this->user();

        $summary = null;
        $payments = [];
        $gateways = [];
        $timezone = 'UTC';

        try {
            $invoice = $query->invoice($this->invoice, $user);
            $timezone = $invoice->issued_timezone !== '' ? $invoice->issued_timezone : 'UTC';
            $summary = $presenter->settlement($settlement->forInvoice($invoice));
            $summary['state_label'] = $summary['voided'] ? (string) __('manager_pos.sale_status.voided') : MoneyLabels::settlement($summary['state']);
            $summary['state_tone'] = $summary['voided'] ? 'danger' : MoneyLabels::tone($summary['state']);
            $summary['has_pending'] = $summary['pending']['amount'] > 0;
            $summary['has_refunds'] = $summary['refunded']['amount'] > 0;
            $summary['collectible'] = $summary['available_collectible']['amount'] > 0;
            $payments = array_map(fn (Payment $payment): array => PaymentRows::payment($presenter->payment($payment), $timezone), $query->forInvoice($invoice));

            if ($entitlements->enabled('payments')) {
                foreach ($usable->forInvoice($invoice) as $account) {
                    $gateways[] = ['uuid' => $account->uuid, 'name' => $account->display_name.' · '.$usable->providerName($account)];
                }
            }
        } catch (AuthorizationException|NotFoundHttpException) {
            $this->error = (string) __('You may not view payments for this invoice.');
        }

        $collects = $user->hasPermission(Permission::PaymentCollect);
        $desk = $collects && $entitlements->enabled('pos');
        $online = $collects && $gateways !== [];
        $refunds = $user->hasPermission(Permission::PaymentRefund) && ($entitlements->enabled('pos') || $entitlements->enabled('payments'));
        $refundMethods = $this->refundMethods($payments, $entitlements);
        $allowed = array_column($refundMethods, 'value');

        // Keep the chosen way back one this payment allows.
        if ($allowed !== [] && ! in_array($this->refundMethod, $allowed, true)) {
            $this->refundMethod = $allowed[0];
        }

        return view('livewire.center.invoice-payments', [
            'summary' => $summary,
            'payments' => $payments,
            'gateways' => $gateways,
            'canCollect' => $desk,
            'canGateway' => $online,
            'canTakeMoney' => $desk || $online,
            'canRefund' => $refunds,
            'refundMethods' => $refundMethods,
        ]);
    }

    /**
     * How the selected payment's money may go back: the desk methods with
     * `pos`; through the provider only for an online payment whose provider
     * refunds, with `payments`. The Action checks every one of these again.
     *
     * @param  list<array<string, mixed>>  $payments
     * @return list<array{value: string, label: string}>
     */
    private function refundMethods(array $payments, Entitlements $entitlements): array
    {
        $selected = null;

        foreach ($payments as $payment) {
            if ($payment['uuid'] === $this->refundPayment) {
                $selected = $payment;
            }
        }

        if ($selected === null) {
            return [];
        }

        $methods = [];

        if ($entitlements->enabled('pos')) {
            $methods[] = ['value' => 'cash', 'label' => (string) __('In cash, from my drawer')];
            $methods[] = ['value' => 'manual_electronic', 'label' => (string) __('By transfer, confirmed by the desk')];
        }

        if ($selected['method'] === 'gateway' && $selected['provider_refunds'] && $entitlements->enabled('payments')) {
            $methods[] = ['value' => 'gateway', 'label' => (string) __('Through the online provider')];
        }

        return $methods;
    }

    /**
     * A money-moving press: on success the idempotency token is renewed, so the
     * next press is a new request and a double-click is the same one.
     */
    private function collect(callable $work): void
    {
        if ($this->attempt($work)) {
            $this->token = (string) Str::uuid();
        }
    }

    /**
     * @throws PaymentFailed
     */
    private function amount(string $typed, Invoice $invoice): int
    {
        try {
            return Money::fromMajorString($typed, $this->currencyOf($invoice))->minor;
        } catch (InvalidArgumentException) {
            throw PaymentFailed::policy('Enter an amount like 25000.');
        }
    }

    private function currencyOf(Invoice $invoice): Currency
    {
        return Currency::tryFrom($invoice->currency) ?? Currency::default();
    }

    private function ownPayment(string $uuid, PaymentsQuery $query): Payment
    {
        $payment = $query->payment($uuid, $this->user());

        // A payment of THIS invoice only: the component never acts on another.
        if ($payment->invoice?->uuid !== $this->invoice) {
            throw new NotFoundHttpException;
        }

        return $payment;
    }
}
