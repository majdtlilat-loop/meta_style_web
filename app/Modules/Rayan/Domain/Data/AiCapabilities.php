<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Domain\Data;

/**
 * What one AI adapter can actually do.
 *
 * Same contract as the payment and messaging provider capabilities, for the
 * same reason: generic code above the adapter must not assume a feature that
 * a given provider lacks, and a silent default of "yes" means the first
 * adapter that cannot do something breaks quietly (docs/27-RAYAN.md §6).
 */
final readonly class AiCapabilities
{
    /**
     * @param  list<string>  $models  the model ids this installation may use
     */
    public function __construct(
        /** A verified adapter exists AND this installation is configured for it. */
        public bool $available,
        /** Structured function calling — without it there is no RAYAN. */
        public bool $tools,
        public array $models = [],
        public ?string $defaultModel = null,
        /** The platform's model for report analysis; null = same as the assistant. */
        public ?string $reportModel = null,
    ) {}

    public static function unavailable(): self
    {
        return new self(false, false);
    }

    /**
     * Is this model id one the PLATFORM approved?
     *
     * The check that keeps a tenant setting from becoming a way to spend a
     * center's allowance on an arbitrarily expensive model, or to name one that
     * does not exist and break every conversation (§5).
     */
    public function allowsModel(string $model): bool
    {
        return in_array($model, $this->models, true);
    }
}
