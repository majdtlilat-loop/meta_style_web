<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Data;

use App\Kernel\Money\Currency;
use App\Modules\Payments\Domain\Enums\GatewayEnvironment;

/**
 * What one provider adapter can actually do — stated, so nothing has to pretend.
 *
 * The desk asks before offering a refund or a cancel button; an Action asks
 * before calling. A provider that cannot refund through its API says so here,
 * and a refund against it is refused as unsupported rather than faked
 * (docs/19-PAYMENTS.md §17).
 */
final readonly class ProviderCapabilities
{
    /**
     * @param  list<GatewayEnvironment>  $environments  environments this installation has a base URL for
     * @param  list<Currency>  $currencies
     */
    public function __construct(
        /** A verified adapter exists and may be configured at all. */
        public bool $available,
        public bool $cancellation,
        public bool $refunds,
        /**
         * TRUE when a callback may be believed only after asking the provider,
         * with the center's credentials, what the payment's status is — the
         * callback is unsigned, so it is a trigger, never evidence.
         */
        public bool $callbackRequiresStatusQuery,
        public array $environments,
        public array $currencies,
    ) {}

    public static function unavailable(): self
    {
        return new self(false, false, false, true, [], []);
    }

    public function supportsEnvironment(GatewayEnvironment $environment): bool
    {
        return in_array($environment, $this->environments, true);
    }

    public function supportsCurrency(Currency $currency): bool
    {
        return in_array($currency, $this->currencies, true);
    }
}
