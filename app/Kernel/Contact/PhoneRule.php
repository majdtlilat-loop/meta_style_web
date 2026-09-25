<?php

declare(strict_types=1);

namespace App\Kernel\Contact;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the national number typed into the phone field against the
 * country chosen beside it. The value to store is PhoneNumber::fromParts(),
 * never the typed text.
 */
final readonly class PhoneRule implements ValidationRule
{
    public function __construct(private string $country) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! PhoneCountries::exists($this->country)) {
            $fail(__('phone_field.errors.country'));

            return;
        }
        if (! is_string($value) || PhoneNumber::fromParts($this->country, $value) === null) {
            $fail(__('phone_field.errors.invalid'));
        }
    }
}
