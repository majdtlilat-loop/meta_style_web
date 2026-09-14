<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain\Data;

use Illuminate\Validation\ValidationException;

/**
 * What a caller asked for when saving a resource, validated into a shape the
 * Action can trust.
 *
 * UUIDs, never internal ids: an id in a request body is a value the client
 * chose, and accepting one would let a caller reference a row it was never
 * shown (docs/08-AUDIT-SECURITY.md).
 *
 * The capacity floor lives here rather than in a database CHECK constraint —
 * Laravel has no portable check builder and the schema must build identically
 * on MariaDB 10.4 and MySQL 8 (ADR-033). A capacity of zero is not "unbookable"
 * with a clear meaning; it is a resource that silently refuses every booking,
 * which is what the `is_active` flag says properly.
 */
final readonly class ResourceInput
{
    /** The largest number of simultaneous uses worth modelling. */
    private const MAX_CAPACITY = 500;

    /**
     * @param  array<string, string|null>  $name
     */
    public function __construct(
        public string $typeUuid,
        public string $branchUuid,
        public ?string $departmentUuid,
        public array $name,
        public int $capacity,
        public bool $isActive,
        public int $sortOrder,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public static function fromArray(array $data): self
    {
        $capacity = (int) ($data['capacity'] ?? 1);

        if ($capacity < 1 || $capacity > self::MAX_CAPACITY) {
            throw ValidationException::withMessages([
                'capacity' => 'Capacity must be between 1 and '.self::MAX_CAPACITY.'.',
            ]);
        }

        /** @var array<string, string|null> $name */
        $name = is_array($data['name'] ?? null) ? $data['name'] : [];

        if ($name === []) {
            throw ValidationException::withMessages(['name' => 'A resource needs a name.']);
        }

        return new self(
            typeUuid: (string) ($data['resource_type'] ?? ''),
            branchUuid: (string) ($data['branch'] ?? ''),
            departmentUuid: self::nullableString($data['department'] ?? null),
            name: $name,
            capacity: $capacity,
            isActive: (bool) ($data['is_active'] ?? true),
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
