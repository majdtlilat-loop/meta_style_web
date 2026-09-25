<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Kernel\SaaS\Models\Registration;
use App\Modules\Onboarding\Application\RegistrationService;
use Illuminate\Http\RedirectResponse;

final class VerifyRegistrationEmailController extends Controller
{
    public function __invoke(string $uuid, RegistrationService $registrations): RedirectResponse
    {
        $registration = Registration::query()->where('uuid', $uuid)->firstOrFail();

        abort_unless($registrations->verifyEmail($registration), 410);

        return redirect()->route('registration.status', ['uuid' => $uuid])
            ->with('status', __('Your email is verified. We are creating your center now.'));
    }
}
