<?php

declare(strict_types=1);

namespace App\Kernel\Http;

use App\Kernel\Observability\RequestId;
use Illuminate\Http\JsonResponse;

/**
 * The single place API response envelopes are built.
 *
 * Success:  { "data": ..., "meta": { "request_id": "..." } }
 * Error:    { "error": { "code", "message", "details" }, "meta": { ... } }
 *
 * See docs/10-API-FOUNDATION.md §4. Controllers must not hand-assemble these
 * shapes — a second envelope format is a breaking change for every client.
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>|list<mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    public static function data(array $data, int $status = 200, array $meta = []): JsonResponse
    {
        return new JsonResponse([
            'data' => $data,
            'meta' => self::meta($meta),
        ], $status);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $meta
     */
    public static function error(
        ApiErrorCode $code,
        string $message,
        array $details = [],
        ?int $status = null,
        array $meta = [],
    ): JsonResponse {
        $error = [
            'code' => $code->value,
            'message' => $message,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return new JsonResponse([
            'error' => $error,
            'meta' => self::meta($meta),
        ], $status ?? $code->httpStatus());
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function meta(array $extra): array
    {
        return array_merge(['request_id' => app(RequestId::class)->value()], $extra);
    }
}
