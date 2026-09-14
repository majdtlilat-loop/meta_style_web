<?php

declare(strict_types=1);

namespace App\Kernel\SaaS\Enums;

use App\Kernel\SaaS\Models\Registration;

/**
 * What a self-registration request is doing right now.
 *
 * `Preparing` exists because provisioning creates a database and runs
 * migrations — far too slow for a web request, and far too failure-prone to
 * report as done before it is. The client polls until this leaves `Preparing`
 * (docs/02-TENANCY.md §8.2).
 *
 * The three terminal states are distinguished on purpose. "Failed" is
 * recoverable and keeps its bootstrap credential; "Cancelled" and "Abandoned"
 * are not, and both destroy it. Collapsing them would lose the one thing a
 * support conversation needs to know: whether this can still be rescued
 * (docs/DECISIONS.md ADR-031).
 */
enum RegistrationStatus: string
{
    /** Provisioning is running, or queued to run. */
    case Preparing = 'preparing';

    /** The center exists and the owner can sign in. Credential destroyed. */
    case Ready = 'ready';

    /** Provisioning failed. Retryable while the window is open. */
    case Failed = 'failed';

    /** Explicitly given up on by the registrant or an operator. */
    case Cancelled = 'cancelled';

    /** The retry window elapsed without success. Swept. */
    case Abandoned = 'abandoned';

    /**
     * Could this registration still become a working center?
     *
     * Status only — the retry window and the presence of the credential are
     * checked by {@see Registration::isRetryable()},
     * which is the answer callers should ask for.
     */
    public function isRetryable(): bool
    {
        return $this === self::Failed;
    }

    /**
     * Is this outcome final, with no path back?
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Ready, self::Cancelled, self::Abandoned], true);
    }

    /**
     * Must the bootstrap credential be destroyed on entering this state?
     *
     * Every terminal state, without exception. Success no longer needs it;
     * cancellation and abandonment never will.
     */
    public function requiresCredentialDestruction(): bool
    {
        return $this->isTerminal();
    }
}
