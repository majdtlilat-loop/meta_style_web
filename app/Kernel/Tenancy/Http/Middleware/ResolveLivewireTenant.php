<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy\Http\Middleware;

use App\Kernel\Tenancy\PlatformHosts;
use Closure;
use Illuminate\Http\Request;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps center tenancy bound for the complete Livewire update request.
 *
 * Livewire's persistent-middleware replay is intentionally a short, internal
 * pipeline: it runs before the component action and then returns. That is fine
 * for stateless middleware, but ResolveTenant restores tenant context in a
 * finally block. Replaying it alone therefore resolves the center and tears it
 * down again before a component method can read the tenant database.
 *
 * This middleware is appended to the real `web` group. It is inert for every
 * request except Livewire's update and file endpoints, where it delegates to the normal
 * resolver around the whole controller request. The existing resolver remains
 * the one authority for host/session conflict checks, URL defaults, auditing,
 * fail-closed unknown hosts, and exception-safe cleanup.
 */
final class ResolveLivewireTenant
{
    public function __construct(
        private readonly HandleRequests $livewire,
        private readonly PlatformHosts $hosts,
        private readonly ResolveTenant $tenants,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // The update endpoint, and the two file endpoints: a temporary upload
        // is written by one request and read by the next, so both must see the
        // same center-rooted disk (and a preview must never read another's).
        if (! $this->livewire->isLivewireRoute() && ! $request->routeIs('livewire.upload-file', 'livewire.preview-file')) {
            return $next($request);
        }

        $host = $request->getHost();

        // Corporate and Super Admin Livewire components are platform surfaces
        // and must never initialise tenant context.
        if ($host === $this->hosts->corporateHost() || $host === $this->hosts->superAdminHost()) {
            return $next($request);
        }

        // A center-looking or unknown host goes through the exact same trusted
        // registry resolution as an ordinary center request. Unknown, nested,
        // reserved and conflicting hosts therefore continue to fail closed.
        return $this->tenants->handle($request, $next);
    }
}
