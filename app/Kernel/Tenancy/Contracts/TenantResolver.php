<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy\Contracts;

use App\Kernel\Tenancy\Exceptions\TenantResolutionConflict;
use App\Kernel\Tenancy\Tenant;
use Illuminate\Http\Request;

/**
 * Identifies which tenant a request belongs to.
 *
 * Only trusted sources are consulted — the host, and (from Phase 3) the
 * server-side binding of an access token. A tenant identifier supplied in a
 * query string, body field, or client-settable header is never an input here
 * (docs/02-TENANCY.md §2).
 */
interface TenantResolver
{
    /**
     * @throws TenantResolutionConflict when two trusted sources disagree
     */
    public function resolve(Request $request): ?Tenant;

    /**
     * Resolves the tenant and reports which trusted source identified it.
     *
     * The source is worth surfacing: it goes into request context and audit
     * entries, and it is what makes a conflict diagnosable rather than a
     * mysterious 403.
     *
     * @return array{0: ?Tenant, 1: ?string}
     *
     * @throws TenantResolutionConflict
     */
    public function resolveWithSource(Request $request): array;

    public function findByKey(string $key): ?Tenant;

    /**
     * Resolves a tenant from its opaque public key.
     *
     * The only lookup that accepts a client-supplied identifier, and only
     * because the public key is opaque, revocable and authorises nothing by
     * itself — it names the center to authenticate against
     * (docs/02-TENANCY.md §2.2, source 3).
     */
    public function findByPublicKey(string $publicKey): ?Tenant;
}
