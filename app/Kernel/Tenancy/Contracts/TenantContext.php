<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy\Contracts;

use App\Kernel\Tenancy\Exceptions\TenantNotResolved;
use App\Kernel\Tenancy\Tenant;

/**
 * The current tenant, for everything outside App\Kernel\Tenancy.
 *
 * This is the whole point of ADR-018: business modules ask Meta Style who the
 * tenant is and never learn that a tenancy package exists. Injected, never a
 * static — there is no mutable global tenant state in Meta Style code.
 */
interface TenantContext
{
    /** The current tenant, or null when running in platform mode. */
    public function tenant(): ?Tenant;

    /**
     * The current tenant, or fail.
     *
     * Use this everywhere tenant data is involved. Never write
     * `?->` fallbacks around tenant(): a missing tenant is a bug, not a
     * condition to work around.
     *
     * @throws TenantNotResolved
     */
    public function require(): Tenant;

    public function id(): ?string;

    public function isBound(): bool;

    /**
     * Run $callback with $tenant initialised, restoring the previous context
     * afterwards even if the callback throws.
     *
     * @template TReturn
     *
     * @param  callable(Tenant): TReturn  $callback
     * @return TReturn
     */
    public function run(Tenant $tenant, callable $callback): mixed;

    /** Leave tenant context and return to the control plane. */
    public function forget(): void;
}
