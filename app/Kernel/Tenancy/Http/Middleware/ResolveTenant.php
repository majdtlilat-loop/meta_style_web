<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy\Http\Middleware;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\ActorType;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Audit\Enums\AuditSource;
use App\Kernel\Identity\TenantApiToken;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Contracts\TenantResolver;
use App\Kernel\Tenancy\Exceptions\TenantNotResolved;
use App\Kernel\Tenancy\Exceptions\TenantResolutionConflict;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes tenant context for a request.
 *
 * Applied to tenant-scoped route groups only. Platform routes never run this
 * and never have a tenant bound.
 *
 * Two failure modes, deliberately answered differently:
 *
 *   unresolvable → 404. The host is not a Meta Style tenant. Saying "no tenant
 *                  here" any more precisely would confirm which hosts exist.
 *   conflict     → 403 + a security audit entry. Two trusted sources named
 *                  different tenants, which means a bug or an attack. Never
 *                  resolved by preferring one source (docs/02-TENANCY.md §2.2).
 */
final class ResolveTenant
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantContext $context,
        private readonly Audit $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            [$tenant, $source] = $this->resolver->resolveWithSource($request);
        } catch (TenantResolutionConflict $e) {
            $this->auditConflict($request, $e);

            // Re-thrown rather than abort()ed so the exception renderer maps it
            // to TENANT.RESOLUTION_CONFLICT. A bare abort(403) would surface as
            // a generic server error and tell the client nothing.
            throw $e;
        }

        if ($tenant === null) {
            throw new TenantNotResolved(
                "No tenant is registered for host [{$request->getHost()}]."
            );
        }

        $this->normaliseApiToken($request);

        return $this->context->run($tenant, function () use ($next, $request, $source): Response {
            $request->attributes->set('tenant_resolution_source', $source);

            return $next($request);
        });
    }

    /**
     * Removes the tenant prefix from the bearer token.
     *
     * Resolution has already used it; Sanctum needs the bare `id|plaintext`
     * form it issued. Rewriting here rather than teaching Sanctum about our
     * format keeps the token concern in one place and leaves Sanctum stock.
     */
    private function normaliseApiToken(Request $request): void
    {
        $bearer = TenantApiToken::fromAuthorizationHeader($request->headers->get('Authorization'));
        $parsed = TenantApiToken::parse($bearer);

        if ($parsed !== null) {
            $request->headers->set('Authorization', 'Bearer '.$parsed['token']);
        }
    }

    private function auditConflict(Request $request, TenantResolutionConflict $e): void
    {
        $this->audit->record(new AuditEvent(
            action: 'tenancy.resolution.conflict',
            category: AuditCategory::Security,
            actor: new Actor(ActorType::Guest, AuditSource::Web, null, 'unauthenticated'),
            severity: AuditSeverity::Critical,
            meta: [
                'host' => $request->getHost(),
                'path' => $request->path(),
                'first_source' => $e->firstSource,
                'first_tenant' => $e->firstTenantId,
                'second_source' => $e->secondSource,
                'second_tenant' => $e->secondTenantId,
            ],
        ));
    }
}
