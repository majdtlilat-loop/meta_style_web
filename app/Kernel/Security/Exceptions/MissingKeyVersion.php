<?php

declare(strict_types=1);

namespace App\Kernel\Security\Exceptions;

use RuntimeException;

/**
 * A keyed digest could not be produced or checked, because the key version it
 * needs is not configured.
 *
 * THROWN RATHER THAN ANSWERED FALSE, deliberately. "This code is wrong" and
 * "this deployment cannot tell whether this code is wrong" are different facts,
 * and collapsing them would turn a missing environment variable into a quiet
 * refusal of every booking code in the system — with nothing anywhere saying
 * why (docs/24-BOOKING-VERIFICATION.md §5).
 *
 * Callers on a public path catch it and answer with the same generic refusal
 * they give a wrong code; the difference is that this one is also REPORTED.
 *
 * The message names the key and the version and nothing else. No key material,
 * no list of what is configured: an exception message is the least controlled
 * string in the system — it reaches logs, error trackers and, with the wrong
 * `APP_DEBUG`, a browser.
 */
final class MissingKeyVersion extends RuntimeException
{
    public static function forVersion(string $name, string $version): self
    {
        return new self(sprintf('No key material is configured for "%s" version "%s".', $name, $version));
    }

    public static function noActiveVersion(string $name): self
    {
        return new self(sprintf('No active key version is configured for "%s".', $name));
    }
}
