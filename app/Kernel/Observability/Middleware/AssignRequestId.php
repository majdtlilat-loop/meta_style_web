<?php

declare(strict_types=1);

namespace App\Kernel\Observability\Middleware;

use App\Kernel\Observability\RequestId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the correlation id for the request and puts it on every log line
 * and on the response.
 *
 * See docs/10-API-FOUNDATION.md §3.
 */
final class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function __construct(private readonly RequestId $requestId) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->requestId->set($request->headers->get(self::HEADER));

        $request->headers->set(self::HEADER, $this->requestId->value());

        Log::withContext(['request_id' => $this->requestId->value()]);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set(self::HEADER, $this->requestId->value());

        return $response;
    }
}
