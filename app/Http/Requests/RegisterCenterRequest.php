<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * What a center must supply to sign up.
 *
 * Deliberately small. Everything a center needs to actually operate — branches,
 * hours, services, staff — belongs to the setup wizard AFTER they have an
 * account. Asking for it here turns a sign-up into a data-entry session and
 * loses the customer (docs/13-ROADMAP.md Phase 3).
 */
final class RegisterCenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'center_name' => ['required', 'string', 'min:2', 'max:190'],
            'owner_name' => ['required', 'string', 'min:2', 'max:190'],
            'owner_email' => ['nullable', 'email:rfc', 'max:190'],
            // E.164. Stored in one canonical form so a number is one value
            // regardless of how it was typed (docs/07-LOCALIZATION.md §7).
            'owner_phone' => ['nullable', 'string', 'regex:/^\+[1-9][0-9]{7,17}$/'],
            'password' => ['required', 'string', Password::min(10)->uncompromised()],
            'locale' => ['nullable', 'string', 'in:'.implode(',', array_keys((array) config('localization.languages')))],
            'country' => ['nullable', 'string', 'size:2'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // One contact method is required, either one will do. Phone is
            // primary in the target market; email is common for owners.
            if ($this->input('owner_email') === null && $this->input('owner_phone') === null) {
                $validator->errors()->add('owner_email', 'An email address or a phone number is required.');
            }
        });
    }
}
