<?php

declare(strict_types=1);

namespace App\Kernel\Entitlements\Http\Middleware;

use App\Kernel\Entitlements\Entitlements;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level entitlement gate.
 *
 * The coarse half of the pair. It protects HTTP and nothing else, which is why
 * Actions call `Entitlements::ensure()` themselves — a WhatsApp webhook, an AI
 * tool call or a queued job never passes through here
 * (docs/05-ENTITLEMENTS.md §6).
 */
final class RequireEntitlement
{
    public function __construct(private readonly Entitlements $entitlements) {}

    public function handle(Request $request, Closure $next, string $entitlement): Response
    {
        $this->entitlements->ensure($entitlement);

        return $next($request);
    }
}
