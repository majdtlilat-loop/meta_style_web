<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Application;

use App\Modules\Rayan\Contracts\AiProvider;
use App\Modules\Rayan\Domain\Exceptions\RayanFailed;

/**
 * Provider code → adapter. A map, not a plugin framework.
 *
 * The same shape as the payment and messaging registries, including
 * {@see with()} for registering a deterministic fake in tests without reaching
 * into the container (docs/27-RAYAN.md §6).
 */
final class AiProviderRegistry
{
    /** @var array<string, AiProvider> */
    private array $providers = [];

    /**
     * @param  iterable<AiProvider>  $providers
     */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->code()] = $provider;
        }
    }

    /**
     * @throws RayanFailed
     */
    public function get(string $code): AiProvider
    {
        return $this->providers[$code]
            ?? throw RayanFailed::because('That assistant provider is not available.');
    }

    public function has(string $code): bool
    {
        return isset($this->providers[$code]);
    }

    /**
     * @return list<AiProvider>
     */
    public function all(): array
    {
        return array_values($this->providers);
    }

    public function with(AiProvider $provider): self
    {
        $copy = clone $this;
        $copy->providers[$provider->code()] = $provider;

        return $copy;
    }
}
