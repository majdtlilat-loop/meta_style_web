<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy\Infrastructure;

use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Exceptions\TenantNotResolved;
use App\Kernel\Tenancy\Tenant;
use Stancl\Tenancy\Tenancy;

/**
 * Adapts stancl/tenancy's Tenancy manager to the Meta Style TenantContext
 * contract.
 *
 * This is the seam described in ADR-018: the package does the switching, and
 * this class is the only thing the rest of the application talks to. It is not
 * a wrapper for the sake of wrapping — it earns its place three ways:
 *
 *  1. It returns the immutable {@see Tenant} value object rather than an
 *     Eloquent model, so business code cannot mutate tenant state in passing.
 *  2. It makes `require()` a hard failure, so no caller can quietly proceed
 *     without a tenant.
 *  3. Its `run()` is exception-safe. The package's own `Tenant::run()` has no
 *     try/finally: if the callback throws, tenancy stays initialised and the
 *     next operation silently inherits the wrong tenant. In a queue worker or
 *     a migration loop that is a cross-tenant write.
 */
final class StanclTenantContext implements TenantContext
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function tenant(): ?Tenant
    {
        $model = $this->tenancy->tenant;

        return $model instanceof TenantModel ? $model->toValueObject() : null;
    }

    public function require(): Tenant
    {
        return $this->tenant() ?? throw new TenantNotResolved(
            'No tenant is initialised. Tenant data must never be accessed without an '
            .'explicit tenant context (docs/02-TENANCY.md §4).'
        );
    }

    public function id(): ?string
    {
        $model = $this->tenancy->tenant;

        return $model instanceof TenantModel ? $model->getTenantKey() : null;
    }

    public function isBound(): bool
    {
        return $this->tenancy->initialized && $this->tenancy->tenant instanceof TenantModel;
    }

    public function run(Tenant $tenant, callable $callback): mixed
    {
        $model = TenantModel::query()->findOrFail($tenant->id);

        return $this->runForModel($model, $callback);
    }

    /**
     * Internal entry point for code that already holds the model (the
     * provisioner and the migrator), avoiding a redundant lookup.
     *
     * @template TReturn
     *
     * @param  callable(Tenant): TReturn  $callback
     * @return TReturn
     */
    public function runForModel(TenantModel $model, callable $callback): mixed
    {
        $previous = $this->tenancy->tenant;

        $this->tenancy->initialize($model);

        try {
            return $callback($model->toValueObject());
        } finally {
            // The `finally` is the point. Without it a thrown exception leaves
            // the previous tenant's connection, cache tag and storage prefix
            // bound to whatever runs next.
            if ($previous !== null) {
                $this->tenancy->initialize($previous);
            } else {
                $this->tenancy->end();
            }
        }
    }

    public function forget(): void
    {
        $this->tenancy->end();
    }
}
