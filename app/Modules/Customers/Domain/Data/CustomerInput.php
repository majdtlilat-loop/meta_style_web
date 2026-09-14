<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Data;

use App\Modules\Customers\Domain\Enums\CustomerSource;

/**
 * Validated customer details on their way into an Action.
 *
 * Relations use `null` to mean "leave alone" and `[]` to mean "clear", the same
 * convention the service catalog uses — collapsing the two would make a partial
 * update from one screen silently wipe tags set on another.
 */
final readonly class CustomerInput
{
    /**
     * @param  list<string>|null  $tagUuids
     */
    public function __construct(
        public string $name,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $preferredLocale = null,
        public ?string $dateOfBirth = null,
        public CustomerSource $source = CustomerSource::Staff,
        public bool $allowOperationalMessages = true,
        public bool $marketingOptIn = false,
        public ?array $tagUuids = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<string>|null $tags */
        $tags = isset($data['tags']) && is_array($data['tags']) ? array_values($data['tags']) : null;

        $source = $data['source'] ?? null;

        return new self(
            name: trim((string) ($data['name'] ?? '')),
            phone: self::nullableString($data['phone'] ?? null),
            email: self::nullableString($data['email'] ?? null),
            preferredLocale: self::nullableString($data['preferred_locale'] ?? null),
            dateOfBirth: self::nullableString($data['date_of_birth'] ?? null),
            source: is_string($source) ? (CustomerSource::tryFrom($source) ?? CustomerSource::Staff) : CustomerSource::Staff,
            allowOperationalMessages: (bool) ($data['allow_operational_messages'] ?? true),
            marketingOptIn: (bool) ($data['marketing_opt_in'] ?? false),
            tagUuids: $tags,
        );
    }

    public function touchesTags(): bool
    {
        return $this->tagUuids !== null;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
