<?php

declare(strict_types=1);

namespace App\Modules\Branches\Domain\Data;

use App\Modules\Branches\Application\Actions\SaveBranch;

/**
 * Validated branch details, on their way into {@see SaveBranch}.
 *
 * A data object rather than an array so the Action's signature says what it
 * needs, and so the API controller, the Livewire form and the future setup
 * wizard all construct the same thing instead of three differently-shaped
 * arrays (docs/13-ROADMAP.md Phase 4 §23).
 */
final readonly class BranchInput
{
    /**
     * @param  array<string, string|null>  $name
     * @param  array<string, string|null>  $address
     */
    public function __construct(
        public array $name,
        public string $timezone = 'Asia/Baghdad',
        public array $address = [],
        public ?string $phone = null,
        public ?string $whatsapp = null,
        public ?string $email = null,
        public ?string $mapUrl = null,
        public ?string $latitude = null,
        public ?string $longitude = null,
        public bool $isActive = true,
        public bool $isPublic = true,
        public int $sortOrder = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, string|null> $name */
        $name = is_array($data['name'] ?? null) ? $data['name'] : [];

        /** @var array<string, string|null> $address */
        $address = is_array($data['address'] ?? null) ? $data['address'] : [];

        return new self(
            name: $name,
            timezone: is_string($data['timezone'] ?? null) ? $data['timezone'] : 'Asia/Baghdad',
            address: $address,
            phone: self::nullableString($data['phone'] ?? null),
            whatsapp: self::nullableString($data['whatsapp'] ?? null),
            email: self::nullableString($data['email'] ?? null),
            mapUrl: self::nullableString($data['map_url'] ?? null),
            // Kept as strings all the way to the DECIMAL column. Casting a
            // coordinate to float here would round-trip it through binary
            // floating point for no reason at all.
            latitude: self::nullableString($data['latitude'] ?? null),
            longitude: self::nullableString($data['longitude'] ?? null),
            isActive: (bool) ($data['is_active'] ?? true),
            isPublic: (bool) ($data['is_public'] ?? true),
            sortOrder: (int) ($data['sort_order'] ?? 0),
        );
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
