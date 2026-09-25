<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Kernel\Tenancy\PlatformHosts;
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
            'center_slug' => ['required', 'string', 'min:2', 'max:63', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/'],
            'owner_name' => ['required', 'string', 'min:2', 'max:190'],
            'owner_email' => ['required', 'email:rfc', 'max:190'],
            // Required: every center user account has a phone. E.164, one
            // canonical form so a number is one value however it was typed
            // (docs/07-LOCALIZATION.md §7).
            'owner_phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'password' => ['required', 'string', Password::min(10)->uncompromised()],
            'locale' => ['nullable', 'string', 'in:'.implode(',', array_keys((array) config('localization.languages')))],
            'country' => ['nullable', 'string', 'size:2'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $slug = app(PlatformHosts::class)->normalizeSlug((string) $this->input('center_slug'));

            if (! app(PlatformHosts::class)->isValidCenterSlug($slug)) {
                $validator->errors()->add('center_slug', 'This center address is unavailable.');
            }

            // Pending registrations are deliberately left to RegistrationService:
            // it checks idempotency before slug availability, so an exact retry
            // returns the original registration while a different submission for
            // the same slug is still refused.
            if (TenantModel::query()->where('slug', $slug)->exists()) {
                $validator->errors()->add('center_slug', 'This center address is unavailable.');
            }
        });
    }
}
