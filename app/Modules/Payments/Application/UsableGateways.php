<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Kernel\Money\Currency;
use App\Modules\Payments\Domain\Models\GatewayAccount;
use App\Modules\Sales\Domain\Models\Invoice;

/**
 * The gateway accounts an invoice can actually be paid through.
 *
 * ONE filter for every surface that offers online payment — the customer's
 * invoice page and the desk. An account qualifies when it belongs to the
 * invoice's own branch, is enabled and configured, and its provider has a
 * verified adapter that is available, runs in the account's environment and
 * accepts the invoice's currency. The desk once skipped the last two checks and
 * could offer a gateway the Action then refused (docs/19-PAYMENTS.md §60).
 *
 * Offering is not deciding: `InitiateGatewayPayment` checks all of it again.
 */
final class UsableGateways
{
    public function __construct(private readonly PaymentProviderRegistry $providers) {}

    /**
     * @return list<GatewayAccount>
     */
    public function forInvoice(Invoice $invoice): array
    {
        $currency = Currency::tryFrom($invoice->currency);

        /** @var list<GatewayAccount> $accounts */
        $accounts = GatewayAccount::query()
            ->where('branch_id', $invoice->branch_id)
            ->where('enabled', true)
            ->orderBy('provider')
            ->get()
            ->all();

        return array_values(array_filter($accounts, function (GatewayAccount $account) use ($currency): bool {
            if (! $account->isConfigured() || ! $this->providers->has($account->provider)) {
                return false;
            }

            $capabilities = $this->providers->get($account->provider)->capabilities();

            return $capabilities->available
                && $capabilities->supportsEnvironment($account->environment)
                && $currency !== null
                && $capabilities->supportsCurrency($currency);
        }));
    }

    /** The provider's own name for an account, for a picker. */
    public function providerName(GatewayAccount $account): string
    {
        return $this->providers->has($account->provider) ? $this->providers->get($account->provider)->displayName() : $account->provider;
    }
}
