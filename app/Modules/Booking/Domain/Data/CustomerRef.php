<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Data;

/**
 * Who the booking is for.
 *
 * Three shapes, and the engine picks by the ACTOR rather than by what the
 * caller sent:
 *
 *   `existing()`  staff selecting a customer they can already see
 *   `details()`   a guest, or staff booking for somebody new
 *   `self()`      a signed-in customer, resolved from their own account
 *
 * A SIGNED-IN CUSTOMER'S REQUEST NEVER CARRIES A UUID. If it did, the engine
 * would have to decide whether to trust it, and the answer is always no —
 * "book this appointment for customer X" from a customer session is a request
 * to act as somebody else (docs/13-ROADMAP.md Phase 6 §§16, 27). The customer
 * endpoints therefore construct `self()` unconditionally and ignore anything in
 * the body that looks like an identity.
 *
 * A GUEST'S DETAILS RESOLVE, THEY DO NOT CREATE BLINDLY. If the normalised
 * phone already belongs to a customer, that customer is used — one person stays
 * one record. The engine returns nothing about that existing record, so a guest
 * cannot learn anything by typing somebody else's number.
 */
final readonly class CustomerRef
{
    private function __construct(
        public ?string $uuid,
        public ?string $name,
        public ?string $phone,
        public ?string $email,
        public ?string $locale,
        public bool $isSelf,
    ) {}

    public static function existing(string $uuid): self
    {
        return new self($uuid, null, null, null, null, false);
    }

    public static function details(
        string $name,
        string $phone,
        ?string $email = null,
        ?string $locale = null,
    ): self {
        return new self(null, trim($name), trim($phone), $email, $locale, false);
    }

    /** The actor's own linked customer. */
    public static function self(): self
    {
        return new self(null, null, null, null, null, true);
    }

    public function hasDetails(): bool
    {
        return $this->phone !== null && $this->name !== null;
    }
}
