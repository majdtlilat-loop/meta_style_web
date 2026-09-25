<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Data;

use App\Modules\Payments\Domain\Enums\GatewayEnvironment;

/**
 * Decrypted credentials, for the length of one provider call.
 *
 * Built from the account immediately before the adapter is called and never
 * stored, logged or presented. `__debugInfo` keeps a stray `dump()` from
 * printing them; the values are marked sensitive so a stack trace does not
 * carry them either.
 */
final readonly class GatewayCredentials
{
    /**
     * @param  array<string, string>  $values
     */
    public function __construct(
        public string $accountUuid,
        public GatewayEnvironment $environment,
        #[\SensitiveParameter]
        private array $values,
    ) {}

    public function get(string $key): string
    {
        return $this->values[$key] ?? '';
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['accountUuid' => $this->accountUuid, 'environment' => $this->environment->value, 'values' => '[redacted]'];
    }
}
