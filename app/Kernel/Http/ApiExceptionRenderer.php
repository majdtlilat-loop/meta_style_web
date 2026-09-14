<?php

declare(strict_types=1);

namespace App\Kernel\Http;

use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Exceptions\AuthenticationFailed;
use App\Kernel\Identity\Exceptions\InvalidActivationToken;
use App\Kernel\Tenancy\Exceptions\TenantConnectionNotInitialized;
use App\Kernel\Tenancy\Exceptions\TenantNotResolved;
use App\Kernel\Tenancy\Exceptions\TenantResolutionConflict;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Maps exceptions to the API error envelope.
 *
 * Returns null for requests that do not expect JSON, so web routes keep
 * Laravel's normal error pages.
 *
 * See docs/10-API-FOUNDATION.md §4.
 */
final class ApiExceptionRenderer
{
    public function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return null;
        }

        return match (true) {
            $e instanceof ValidationException => ApiResponse::error(
                ApiErrorCode::ValidationFailed,
                $e->getMessage(),
                ['fields' => $e->errors()],
            ),

            $e instanceof AuthenticationException => ApiResponse::error(
                ApiErrorCode::Unauthenticated,
                'Authentication is required.',
            ),

            // Laravel converts AuthorizationException into an
            // AccessDeniedHttpException before render callbacks run, so both
            // shapes have to be matched or the status is right and the code is
            // a meaningless SERVER.ERROR.
            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => ApiResponse::error(
                ApiErrorCode::PermissionDenied,
                $this->messageFor($e, 'You do not have permission to perform this action.'),
            ),

            // A record in another tenant must be indistinguishable from one
            // that does not exist (docs/08-AUDIT-SECURITY.md §19).
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => ApiResponse::error(
                ApiErrorCode::NotFound,
                'The requested resource was not found.',
            ),

            $e instanceof MethodNotAllowedHttpException => ApiResponse::error(
                ApiErrorCode::MethodNotAllowed,
                'That HTTP method is not supported for this endpoint.',
            ),

            $e instanceof TooManyRequestsHttpException => $this->withRetryAfter(
                ApiResponse::error(ApiErrorCode::RateLimitExceeded, 'Too many requests.'),
                $e,
            ),

            // Carries the missing key so a client can show a targeted upgrade
            // prompt rather than a generic refusal.
            $e instanceof EntitlementRequired => ApiResponse::error(
                ApiErrorCode::EntitlementNotAvailable,
                $e->getMessage(),
                ['entitlement' => $e->entitlement],
            ),

            $e instanceof AuthenticationFailed,
            $e instanceof InvalidActivationToken => ApiResponse::error(
                ApiErrorCode::InvalidCredentials,
                $e->getMessage(),
            ),

            $e instanceof TenantConnectionNotInitialized => ApiResponse::error(
                ApiErrorCode::TenantNotInitialized,
                'Tenant context is missing for this request.',
            ),

            // The host is not a Meta Style tenant. Answered as a plain 404 so
            // the response cannot be used to enumerate which centers exist.
            $e instanceof TenantNotResolved => ApiResponse::error(
                ApiErrorCode::TenantNotResolved,
                'The requested resource was not found.',
            ),

            $e instanceof TenantResolutionConflict => ApiResponse::error(
                ApiErrorCode::TenantResolutionConflict,
                'Tenant could not be determined.',
            ),

            // A module's own refusal, carrying the code it wants a client to
            // branch on — "try a different time" is a different instruction
            // from "try again". Matched by INTERFACE because the Kernel may not
            // name a module's classes (docs/04-MODULE-BOUNDARIES.md §2).
            $e instanceof ApiProblem => $this->problem($e),

            default => $this->serverError($e),
        };
    }

    /**
     * Uses the exception's own message when it carries one worth showing.
     *
     * Actions raise specific refusals — "You may not add staff to that branch"
     * — and replacing those with a generic sentence makes the API harder to
     * use for no security gain: the caller already knows they were refused.
     */
    private function messageFor(Throwable $e, string $fallback): string
    {
        $message = trim($e->getMessage());

        return $message === '' ? $fallback : $message;
    }

    /**
     * A module's own refusal, rendered in the standard envelope.
     *
     * Carries the exception's HTTP status when it declares one — a throttle
     * refusal has to be a 429 with a `Retry-After`, not the code's default —
     * and its headers with it.
     */
    private function problem(ApiProblem $problem): JsonResponse
    {
        $status = $problem instanceof HttpExceptionInterface ? $problem->getStatusCode() : null;

        $response = ApiResponse::error(
            $problem->errorCode(),
            $problem instanceof Throwable ? $this->messageFor($problem, 'Request refused.') : 'Request refused.',
            $problem->errorDetails(),
            $status,
        );

        return $problem instanceof HttpExceptionInterface
            ? $this->withRetryAfter($response, $problem)
            : $response;
    }

    private function withRetryAfter(JsonResponse $response, HttpExceptionInterface $e): JsonResponse
    {
        $retryAfter = $e->getHeaders()['Retry-After'] ?? null;

        if ($retryAfter !== null) {
            $response->headers->set('Retry-After', (string) $retryAfter);
        }

        return $response;
    }

    /**
     * Internal failure detail is exposed only with debug on. In production the
     * request id is the whole story a client gets — which is why every
     * response carries one.
     */
    private function serverError(Throwable $e): JsonResponse
    {
        $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

        // A framework HTTP exception that reached here still deserves an
        // honest code. Reporting a 401 or a 404 as SERVER.ERROR tells the
        // client nothing and looks like a bug on our side.
        $mapped = match ($status) {
            401 => ApiErrorCode::Unauthenticated,
            403 => ApiErrorCode::PermissionDenied,
            404 => ApiErrorCode::NotFound,
            405 => ApiErrorCode::MethodNotAllowed,
            422 => ApiErrorCode::ValidationFailed,
            429 => ApiErrorCode::RateLimitExceeded,
            default => null,
        };

        if ($mapped !== null) {
            return ApiResponse::error($mapped, $this->messageFor($e, 'Request refused.'), [], $status);
        }

        $details = config('app.debug') === true
            ? ['exception' => $e::class, 'message' => $e->getMessage()]
            : [];

        return ApiResponse::error(
            ApiErrorCode::ServerError,
            'An unexpected error occurred.',
            $details,
            $status,
        );
    }
}
