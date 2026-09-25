<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Data;

/**
 * A provider callback as it arrived, handed to the adapter and then discarded.
 *
 * Framework-free so an adapter never needs the HTTP request, and short-lived by
 * design: nothing persists the body (docs/19-PAYMENTS.md §22).
 */
final readonly class InboundWebhook
{
    /**
     * @param  array<string, string>  $headers  lower-cased names
     */
    public function __construct(
        #[\SensitiveParameter]
        public string $body,
        #[\SensitiveParameter]
        public array $headers,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['body' => '[redacted]', 'headers' => '[redacted]'];
    }
}
