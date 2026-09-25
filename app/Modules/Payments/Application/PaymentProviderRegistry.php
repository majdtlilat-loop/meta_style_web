<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Domain\Exceptions\PaymentFailed;

/**
 * Provider code → adapter. A map, not a plugin framework (docs/19-PAYMENTS.md §17).
 *
 * The code stored on an account or a payment is looked up here and nowhere
 * else, so an unknown code — a provider removed from the product, a row edited
 * by hand — is refused instead of resolved to anything.
 */
final class PaymentProviderRegistry
{
    /** @var array<string, PaymentProvider> */
    private array $providers = [];

    /**
     * @param  iterable<PaymentProvider>  $providers
     */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->code()] = $provider;
        }
    }

    /**
     * @throws PaymentFailed
     */
    public function get(string $code): PaymentProvider
    {
        return $this->providers[$code] ?? throw PaymentFailed::unsupported('That payment provider is not available.');
    }

    public function has(string $code): bool
    {
        return isset($this->providers[$code]);
    }

    /**
     * @return list<PaymentProvider>
     */
    public function all(): array
    {
        return array_values($this->providers);
    }

    /**
     * A copy with one provider added or replaced — how tests register a double.
     */
    public function with(PaymentProvider $provider): self
    {
        $copy = clone $this;
        $copy->providers[$provider->code()] = $provider;

        return $copy;
    }
}
