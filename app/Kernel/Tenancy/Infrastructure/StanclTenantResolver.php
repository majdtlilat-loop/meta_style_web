<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy\Infrastructure;

use App\Kernel\Identity\TenantApiToken;
use App\Kernel\Tenancy\Contracts\TenantResolver;
use App\Kernel\Tenancy\Exceptions\TenantResolutionConflict;
use App\Kernel\Tenancy\Tenant;
use Illuminate\Http\Request;

/**
 * Identifies the tenant for a request from trusted sources only.
 *
 * Phase 2 supports one source, the request host. Phase 3 adds the second, the
 * server-side tenant binding of an access token. The conflict rule below is
 * implemented now, before there is a second source, because it is the part
 * that is easy to get wrong once there is pressure to "just pick one".
 *
 * There is deliberately no request-data resolver. The package ships
 * `InitializeTenancyByRequestData`, which reads a tenant id from a header or
 * query parameter; Meta Style does not use it and must never use it, because a
 * tenant identifier the caller can choose is not an authorization boundary
 * (docs/02-TENANCY.md §2.1).
 */
final class StanclTenantResolver implements TenantResolver
{
    /**
     * @return array{0: ?Tenant, 1: ?string} the tenant and the source that found it
     */
    public function resolveWithSource(Request $request): array
    {
        $candidates = [];

        $byHost = $this->findByHost($request->getHost());

        if ($byHost !== null) {
            $candidates['host'] = $byHost;
        }

        // Second trusted source: the tenant public key carried by a Meta Style
        // API token. It is resolved against the control plane, never believed
        // on its own — the Sanctum half must still match a row in that
        // tenant's database (docs/DECISIONS.md ADR-027).
        $byToken = $this->findByApiToken($request);

        if ($byToken !== null) {
            $candidates['token'] = $byToken;
        }

        // Third trusted source: the center this SESSION belongs to, recorded
        // server-side at login. Trusted for the same reason the token binding
        // is — the value lives in signed, encrypted server state, not in
        // anything the caller can set.
        $bySession = $this->findBySession($request);

        if ($bySession !== null) {
            $candidates['session'] = $bySession;
        }

        $this->guardAgainstConflict($candidates);

        $source = array_key_first($candidates);

        return [$source === null ? null : $candidates[$source], $source];
    }

    public function resolve(Request $request): ?Tenant
    {
        return $this->resolveWithSource($request)[0];
    }

    public function findByKey(string $key): ?Tenant
    {
        $model = TenantModel::query()->whereKey($key)->first();

        return $this->usable($model);
    }

    /**
     * Resolves the tenant a presented API token belongs to.
     *
     * Returns null when there is no token, when it is not in Meta Style's
     * format, or when the key matches no tenant — all of which simply mean
     * "this source has no opinion", leaving the host to decide.
     */
    public function findByApiToken(Request $request): ?Tenant
    {
        $parsed = TenantApiToken::parse(
            TenantApiToken::fromAuthorizationHeader($request->headers->get('Authorization'))
        );

        return $parsed === null ? null : $this->findByPublicKey($parsed['key']);
    }

    /**
     * The only lookup that accepts an identifier supplied by a client.
     *
     * Safe because the public key is opaque, revocable, and grants nothing on
     * its own — it says WHICH center to authenticate against, not that the
     * caller is authorised. The internal id and sequence are never used this
     * way (docs/02-TENANCY.md §2.1).
     */
    public function findByPublicKey(string $publicKey): ?Tenant
    {
        if (! TenantApiToken::looksLikePublicKey($publicKey)) {
            return null;
        }

        /** @var TenantModel|null $model */
        $model = TenantModel::query()->where('public_key', $publicKey)->first();

        return $this->usable($model);
    }

    public const SESSION_KEY = 'metastyle.center_key';

    /**
     * Resolves the tenant remembered for this web session.
     */
    public function findBySession(Request $request): ?Tenant
    {
        if (! $request->hasSession()) {
            return null;
        }

        $key = $request->session()->get(self::SESSION_KEY);

        return is_string($key) ? $this->findByPublicKey($key) : null;
    }

    public function findByHost(string $host): ?Tenant
    {
        $domain = DomainModel::query()
            ->where('domain', mb_strtolower($host))
            ->first();

        if (! $domain instanceof DomainModel) {
            return null;
        }

        return $this->findByKey($domain->tenant_id);
    }

    /**
     * Two trusted sources naming different tenants is never resolved by
     * preferring one. It means a bug or an attack, and both get the same
     * answer: refuse.
     *
     * Public so the rule can be tested directly. Phase 2 has only one
     * resolution source, so there is no way to provoke a conflict through a
     * real request yet — and this is precisely the rule that must already be
     * correct when the second source arrives in Phase 3.
     *
     * @param  array<string, Tenant>  $candidates
     *
     * @throws TenantResolutionConflict
     */
    public function guardAgainstConflict(array $candidates): void
    {
        if (count($candidates) < 2) {
            return;
        }

        $sources = array_keys($candidates);
        $first = $sources[0];

        foreach (array_slice($sources, 1) as $other) {
            if ($candidates[$first]->id !== $candidates[$other]->id) {
                throw new TenantResolutionConflict(
                    $first,
                    $candidates[$first]->id,
                    $other,
                    $candidates[$other]->id,
                );
            }
        }
    }

    /**
     * A tenant that is still provisioning, failed, or archived has no usable
     * database. Returning it would produce a confusing connection error later
     * instead of a clear "no tenant" now.
     */
    private function usable(?TenantModel $model): ?Tenant
    {
        if (! $model instanceof TenantModel) {
            return null;
        }

        $tenant = $model->toValueObject();

        return $tenant->status->hasDatabase() && $tenant->isProvisioned() ? $tenant : null;
    }
}
