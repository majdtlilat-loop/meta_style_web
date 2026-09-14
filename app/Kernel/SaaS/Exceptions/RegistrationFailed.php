<?php

declare(strict_types=1);

namespace App\Kernel\SaaS\Exceptions;

use RuntimeException;

final class RegistrationFailed extends RuntimeException
{
    /**
     * The bootstrap credential is gone and no owner exists to fall back on.
     *
     * Deliberately explicit: a passwordless owner account would look like a
     * successful provisioning and be impossible to sign in as (ADR-031).
     */
    public static function credentialExpired(string $registrationUuid): self
    {
        return new self(
            "Registration [{$registrationUuid}] no longer holds a bootstrap credential, "
            .'so the owner account cannot be created. The retry window has closed; '
            .'the center must be registered again.'
        );
    }

    public static function noDefaultPlan(): self
    {
        return new self(
            'No default plan is configured. Set the platform setting '
            .'[default_plan_code] to the code of an active plan.'
        );
    }
}
