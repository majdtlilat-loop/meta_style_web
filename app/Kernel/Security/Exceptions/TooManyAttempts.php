<?php

declare(strict_types=1);

namespace App\Kernel\Security\Exceptions;

use RuntimeException;

/**
 * An abuse limit was reached.
 *
 * Carries the bucket name and how long until it frees up. The bucket name is
 * for LOGS and for the operator — it is never rendered to whoever tripped it,
 * because "booking_code.reference" tells an attacker which of several limits
 * they are up against and therefore how to spread their attempts
 * (docs/13-ROADMAP.md Phase 13 §39).
 *
 * Public surfaces catch this and answer with the same generic refusal they give
 * a wrong code, so that being rate limited is not itself a signal that the
 * reference exists.
 */
final class TooManyAttempts extends RuntimeException
{
    public function __construct(
        public readonly string $bucket,
        public readonly int $availableInSeconds,
    ) {
        parent::__construct(sprintf('Too many attempts (%s). Try again in %d second(s).', $bucket, $availableInSeconds));
    }
}
