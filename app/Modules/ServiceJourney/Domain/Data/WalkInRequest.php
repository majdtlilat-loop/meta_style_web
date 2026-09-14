<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Domain\Data;

use App\Modules\Booking\Domain\Data\BookingLine;

/**
 * "Somebody is at the desk and wants a haircut."
 *
 * The input to a visit that was never reserved. Deliberately small: reception
 * is standing in front of a customer, and a walk-in form that asked for
 * everything a booking form asks for would be slower than writing the name on a
 * pad (docs/17-QUEUE.md §22).
 *
 * ## Uuids, never ids
 *
 * The same rule as {@see BookingLine}: an
 * internal database id in a request body is a value the client chose, and
 * accepting one would let a caller reference a row it was never shown
 * (docs/08-AUDIT-SECURITY.md).
 *
 * ## The customer, in three shapes
 *
 *   `customerUuid`          staff picked somebody they can already see
 *   `phone` (+ `name`)      the existing identity rule: one person, one record
 *   `name` alone            a walk-in who will not give a number
 *
 * The third is why this cannot simply be a `CustomerRef`: the booking forms all
 * have a phone field, and a barbershop's Saturday does not. A name is still
 * required, because the alternative — using the phone as the name — puts a
 * number into the one customer field nothing ever masks (ADR-042).
 *
 * ## `employeeUuid` is a PREFERENCE, not an assignment
 *
 * It seeds every stage's actual employee, and it is validated exactly as a
 * mid-visit reassignment is: active, works at this branch, qualified for the
 * service. Null means "whoever is free", which is the normal case and is
 * decided when the service actually starts.
 */
final readonly class WalkInRequest
{
    /**
     * @param  list<string>  $serviceUuids  in the order they will be performed
     */
    public function __construct(
        public string $branchUuid,
        public array $serviceUuids,
        public ?string $customerUuid = null,
        public ?string $name = null,
        public ?string $phone = null,
        public ?string $employeeUuid = null,
        /*
         * A token the RECEPTION SCREEN generates, one per submission.
         *
         * A double-clicked "create visit" arrives twice with the same token,
         * collides on a unique index, and the Action returns the visit that
         * already exists — the same three-layer pattern check-in uses, and the
         * only one available here because a walk-in has no appointment to
         * collide on (docs/17-QUEUE.md §11).
         */
        public ?string $idempotencyToken = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<string> $services */
        $services = isset($data['services']) && is_array($data['services'])
            ? array_values(array_filter($data['services'], 'is_string'))
            : [];

        return new self(
            branchUuid: (string) ($data['branch'] ?? ''),
            serviceUuids: $services,
            customerUuid: self::nullableString($data['customer'] ?? null),
            name: self::nullableString($data['name'] ?? null),
            phone: self::nullableString($data['phone'] ?? null),
            employeeUuid: self::nullableString($data['employee'] ?? null),
            idempotencyToken: self::nullableString($data['idempotency_token'] ?? null),
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
