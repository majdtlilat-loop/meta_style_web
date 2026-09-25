<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Exceptions;

use App\Modules\Customers\Domain\Models\Customer;
use Illuminate\Validation\ValidationException;

/**
 * The phone already belongs to another customer of this center.
 *
 * Still a `ValidationException` on `phone` — the API answers 422 exactly as
 * before — but it also names WHICH customer, so a screen can offer to open the
 * existing record instead of creating a second one. Linking, never duplicating,
 * is the rule (ADR-041); the person at the desk decides, with the record in
 * front of them.
 *
 * Only ever thrown to someone who already holds `customer.create` or
 * `customer.update` and typed the full number themselves, so naming the owner
 * reveals nothing they could not already find.
 */
final class DuplicateCustomerPhone extends ValidationException
{
    public string $customerUuid = '';

    public string $customerName = '';

    public static function ownedBy(Customer $owner, string $message): self
    {
        $exception = self::withMessages(['phone' => $message]);
        $exception->customerUuid = $owner->uuid;
        $exception->customerName = $owner->name;

        return $exception;
    }
}
