<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy\Http\Middleware;

use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Exceptions\TenantNotResolved;
use App\Kernel\Tenancy\Infrastructure\StanclTenantResolver;
use App\Kernel\Tenancy\PlatformHosts;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves a center for guest-facing center routes, and nothing else.
 *
 * Phase 15 makes the registered center subdomain authoritative. The route
 * segment is the same public slug and must agree with the host; it is not a
 * second resolution source. The host still resolves through the control-plane
 * domain registry, so a syntactically valid label grants nothing by itself.
 *
 * What makes it safe is the LIMIT, not the identifier:
 *
 *  - It is a separate middleware. `ResolveTenant` is unchanged, so nothing
 *    authenticated ever gains a path-segment resolution source.
 *  - It may never share a route with an authentication middleware. That is
 *    enforced by a test, not by care — a public slug in a URL must never
 *    become a way to act as a center.
 *  - The routes it guards read explicitly-public data through resources that
 *    allow-list their fields.
 *  - It still refuses a conflict. If a request arrives on a center's own host
 *    with a different center's key in the path, that is a bug or an attack, and
 *    it is answered the same way as everywhere else: reject and audit.
 */
final class ResolvePublicTenant
{
    /** The route parameter carrying the public slug that must match the host. */
    public const PARAMETER = 'center';

    public function __construct(
        private readonly StanclTenantResolver $resolver,
        private readonly TenantContext $context,
        private readonly PlatformHosts $hosts,
        private readonly UrlGenerator $urls,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolver->findByHost($request->getHost());

        if ($tenant === null) {
            // Indistinguishable from any other 404. A different answer for
            // "no such center" would let anyone enumerate which centers exist
            // by trying keys.
            throw new TenantNotResolved('No center is published at this address.');
        }

        $slug = $this->hosts->centerSlugFromHost($request->getHost());
        if ($slug === null || $request->route(self::PARAMETER) !== $slug) {
            throw new TenantNotResolved('No center is published at this address.');
        }
        $this->urls->defaults(['center' => $slug]);

        return $this->context->run($tenant, function () use ($next, $request): Response {
            $request->attributes->set('tenant_resolution_source', 'host');

            return $next($request);
        });
    }
}
