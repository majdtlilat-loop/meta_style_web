<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Data;

/**
 * One service a caller wants to book, as uuids.
 *
 * The input side of the engine's contract. Every channel — the host screen, the
 * public form, the customer API, and later WhatsApp and RAYAN — builds these
 * and hands them over; none of them touches a catalog model or decides what
 * anything costs (docs/13-ROADMAP.md Phase 6 §40).
 *
 * UUIDS, NOT IDS. An internal database id from a request body is a value the
 * client chose, and accepting one would let a caller reference a row it was
 * never shown (docs/08-AUDIT-SECURITY.md).
 *
 * `employeeUuid` null means "any available", which is a real choice a customer
 * makes and not a missing field. `resourceUuids` empty means the same thing
 * about rooms and devices, and it is the normal case: a customer booking a
 * laser session has no idea which machine that is and should not be asked
 * (docs/13-ROADMAP.md Phase 7 §6).
 *
 * `offsetMinutes` is the Phase 7 layout escape hatch. Null keeps Phase 6's
 * behaviour — this line starts where the previous one ended — and an integer
 * places it that many minutes after the visit's start, which is how a colour
 * with a development gap or two genuinely parallel services are expressed. Only
 * staff adapters ever set it; public booking stays sequential (§34).
 */
final readonly class BookingLine
{
    /**
     * @param  list<string>  $addonUuids
     * @param  list<string>  $resourceUuids
     */
    public function __construct(
        public string $serviceUuid,
        public ?string $variationUuid = null,
        public array $addonUuids = [],
        public ?string $employeeUuid = null,
        public ?string $note = null,
        public array $resourceUuids = [],
        public ?int $offsetMinutes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<string> $addons */
        $addons = isset($data['addons']) && is_array($data['addons'])
            ? array_values(array_filter($data['addons'], 'is_string'))
            : [];

        /** @var list<string> $resources */
        $resources = isset($data['resources']) && is_array($data['resources'])
            ? array_values(array_filter($data['resources'], 'is_string'))
            : [];

        $offset = $data['offset_minutes'] ?? null;

        return new self(
            serviceUuid: (string) ($data['service'] ?? ''),
            variationUuid: self::nullableString($data['variation'] ?? null),
            addonUuids: $addons,
            employeeUuid: self::nullableString($data['employee'] ?? null),
            note: self::nullableString($data['note'] ?? null),
            resourceUuids: $resources,
            offsetMinutes: is_numeric($offset) ? (int) $offset : null,
        );
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<self>
     */
    public static function listFromArray(array $rows): array
    {
        $lines = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $lines[] = self::fromArray($row);
            }
        }

        return $lines;
    }

    public function wantsSpecificEmployee(): bool
    {
        return $this->employeeUuid !== null;
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
