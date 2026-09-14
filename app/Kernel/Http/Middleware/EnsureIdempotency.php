<?php

declare(strict_types=1);

namespace App\Kernel\Http\Middleware;

use App\Kernel\Http\ApiErrorCode;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Http\Idempotency;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires and honours an `Idempotency-Key` on mutating API requests.
 *
 * Applied to the endpoints that create commitments — bookings today, sales and
 * payments later. Not applied globally: an idempotency requirement on a GET is
 * noise, and on a cheap POST it is friction with nothing behind it
 * (docs/10-API-FOUNDATION.md §6).
 *
 * ## The key is REQUIRED, not optional
 *
 * An optional header is one every client forgets, and the clients that forget
 * are the ones on unreliable connections that need it most. Refusing with a
 * clear validation error at integration time is cheaper for everybody than a
 * duplicate booking in production.
 *
 * ## It runs AFTER tenant resolution
 *
 * Keys live in the tenant database, so this middleware must not sit ahead of
 * `ResolveTenant`. It is applied per route inside groups that already resolve a
 * tenant; there is no path by which it runs unbound.
 */
final class EnsureIdempotency
{
    public const HEADER = 'Idempotency-Key';

    public function __construct(private readonly Idempotency $idempotency) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header(self::HEADER, ''));

        if ($key === '') {
            return ApiResponse::error(
                ApiErrorCode::ValidationFailed,
                'This request requires an '.self::HEADER.' header.',
                ['header' => self::HEADER],
            );
        }

        if (mb_strlen($key) > 190) {
            return ApiResponse::error(
                ApiErrorCode::ValidationFailed,
                'That '.self::HEADER.' is too long.',
                ['header' => self::HEADER, 'max' => 190],
            );
        }

        /*
         * The ROUTE NAME, not the path. A path carries uuids, so two retries of
         * the same logical operation against the same route would hash to
         * different endpoints if the uuid moved — and a route name is stable
         * across versioned URLs.
         */
        $endpoint = $request->route()?->getName() ?? $request->path();

        return $this->idempotency->run(
            $key,
            $endpoint,
            $this->payload($request),
            function () use ($request, $next): JsonResponse {
                $response = $next($request);

                if (! $response instanceof JsonResponse) {
                    // Everything this guards is an API endpoint returning the
                    // standard envelope. Anything else cannot be stored and
                    // replayed faithfully, so it is not pretended otherwise.
                    return ApiResponse::error(
                        ApiErrorCode::ServerError,
                        'That operation produced a response that cannot be made idempotent.',
                    );
                }

                return $response;
            },
        );
    }

    /**
     * What gets hashed.
     *
     * The body plus the route's own parameters — so `POST
     * /appointments/{a}/cancel` and `POST /appointments/{b}/cancel` with one
     * reused key are correctly seen as different requests rather than one being
     * replayed as the other.
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        /** @var array<string, mixed> $body */
        $body = $request->all();

        return $body + ['__route' => $request->route()?->parameters() ?? []];
    }
}
