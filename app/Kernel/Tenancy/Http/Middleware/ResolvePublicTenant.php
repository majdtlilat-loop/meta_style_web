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
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Exceptions\TenantNotResolved;
use App\Kernel\Tenancy\Exceptions\TenantResolutionConflict;
use App\Kernel\Tenancy\Infrastructure\StanclTenantResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves a center for the GUEST-FACING electronic menu, and nothing else.
 *
 * The platform rule is that a tenant identifier never comes from the client.
 * This middleware is the one deliberate, bounded exception, and it needs
 * justifying (ADR-036).
 *
 * A public menu has to be REACHABLE. A customer opens it from a QR code on a
 * table or a link in a WhatsApp message, with no session, no token and — for
 * the great majority of centers, who will never buy a domain — no distinct
 * host. Something in the URL has to say which center. The center's public key
 * is the right something: it is already the opaque, revocable, rotatable
 * identifier used for exactly this purpose (ADR-027), and it is never the
 * internal id or the sequence.
 *
 * What makes it safe is the LIMIT, not the identifier:
 *
 *  - It is a separate middleware. `ResolveTenant` is unchanged, so nothing
 *    authenticated ever gains a path-segment resolution source.
 *  - It may never share a route with an authentication middleware. That is
 *    enforced by a test, not by care — a public key in a URL must never become
 *    a way to act as a center.
 *  - The routes it guards read explicitly-public data through resources that
 *    allow-list their fields.
 *  - It still refuses a conflict. If a request arrives on a center's own host
 *    with a different center's key in the path, that is a bug or an attack, and
 *    it is answered the same way as everywhere else: reject and audit.
 */
final class ResolvePublicTenant
{
    /** The route parameter carrying the center's public key. */
    public const PARAMETER = 'center';

    public function __construct(
        private readonly StanclTenantResolver $resolver,
        private readonly TenantContext $context,
        private readonly Audit $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = $this->resolver->findByHost($request->getHost());

        $key = $request->route(self::PARAMETER);
        $fromKey = is_string($key) && $key !== '' ? $this->resolver->findByPublicKey($key) : null;

        if ($host !== null && $fromKey !== null && $host->id !== $fromKey->id) {
            $this->auditConflict($request, $host->id, $fromKey->id);

            throw new TenantResolutionConflict('host', $host->id, 'public_key', $fromKey->id);
        }

        $tenant = $fromKey ?? $host;

        if ($tenant === null) {
            // Indistinguishable from any other 404. A different answer for
            // "no such center" would let anyone enumerate which centers exist
            // by trying keys.
            throw new TenantNotResolved('No center is published at this address.');
        }

        return $this->context->run($tenant, function () use ($next, $request): Response {
            $request->attributes->set('tenant_resolution_source', 'public_key');

            return $next($request);
        });
    }

    private function auditConflict(Request $request, string $hostTenant, string $keyTenant): void
    {
        // The route TEMPLATE, never the literal path. A public path can carry a
        // bearer secret — `/i/{center}/{token}` is a customer's invoice link —
        // and a security audit row is read by more people than that link
        // should reach. The template still says which surface was probed.
        $route = $request->route();

        $this->audit->record(new AuditEvent(
            action: 'tenancy.resolution.conflict',
            category: AuditCategory::Security,
            actor: new Actor(ActorType::Guest, AuditSource::Web, null, 'guest'),
            severity: AuditSeverity::Critical,
            meta: [
                'host' => $request->getHost(),
                'route' => $route instanceof Route ? $route->uri() : null,
                'first_source' => 'host',
                'first_tenant' => $hostTenant,
                'second_source' => 'public_key',
                'second_tenant' => $keyTenant,
            ],
        ));
    }
}
