<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Application;

use App\Modules\Conversations\Contracts\MessagingProvider;
use App\Modules\Conversations\Domain\Exceptions\ConversationFailed;

/**
 * Provider code → adapter. A map, not a plugin framework.
 *
 * The code stored on an account is looked up HERE and nowhere else, so an
 * unknown code — a provider withdrawn from the product, a row edited by hand —
 * is refused rather than resolved to anything (docs/25-WHATSAPP.md §5).
 *
 * The same shape as `Payments\Application\PaymentProviderRegistry`, including
 * {@see with()}: a test registers a deterministic fake by copying the registry
 * rather than by reaching into the container and replacing a singleton other
 * code may already hold.
 */
final class MessagingProviderRegistry
{
    /** @var array<string, MessagingProvider> */
    private array $providers = [];

    /**
     * @param  iterable<MessagingProvider>  $providers
     */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->code()] = $provider;
        }
    }

    /**
     * @throws ConversationFailed
     */
    public function get(string $code): MessagingProvider
    {
        return $this->providers[$code]
            ?? throw ConversationFailed::policy('That messaging provider is not available.');
    }

    public function has(string $code): bool
    {
        return isset($this->providers[$code]);
    }

    /**
     * @return list<MessagingProvider>
     */
    public function all(): array
    {
        return array_values($this->providers);
    }

    /**
     * A copy with one provider added or replaced — how tests register a double.
     */
    public function with(MessagingProvider $provider): self
    {
        $copy = clone $this;
        $copy->providers[$provider->code()] = $provider;

        return $copy;
    }
}
