<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Domain\Exceptions;

use DomainException;

/**
 * Every center user account has a phone number, the owner's first of all: a
 * center is never registered or created without one. Forms validate it with
 * the phone field; this is the rule itself, for every other way in.
 */
final class OwnerPhoneRequired extends DomainException
{
    public static function make(): self
    {
        return new self(__('phone_field.errors.required'));
    }
}
