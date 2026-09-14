<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterCenterRequest;
use App\Kernel\Http\ApiErrorCode;
use App\Kernel\Http\ApiResponse;
use App\Kernel\SaaS\Models\Registration;
use App\Kernel\SaaS\RegistrationAccessToken;
use App\Modules\Onboarding\Application\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public self-registration.
 *
 * Submit, poll, and retry. Provisioning is asynchronous, so the response to a
 * submission is "we have started", never "you have an account" — reporting
 * success before the database exists is the one thing this flow must not do
 * (docs/02-TENANCY.md §8.2).
 *
 * AUTHORIZATION (ADR-035). The uuid in the path is a public LOCATOR and grants
 * nothing. Every read and every retry additionally requires the access token
 * issued once at submission, presented in the `X-Registration-Token` header —
 * a header rather than a query parameter, because a capability in a URL lands
 * in browser history, referrer headers and access logs.
 *
 * An unknown uuid and a wrong token return the SAME 404. Distinguishing them
 * would turn this endpoint into a way to confirm which registrations exist.
 */
final class RegistrationController extends Controller
{
    public function store(RegisterCenterRequest $request, RegistrationService $registrations): JsonResponse
    {
        /** @var array{center_name: string, owner_name: string, owner_email?: string|null, owner_phone?: string|null, password: string, locale?: string|null, country?: string|null} $input */
        $input = $request->validated();

        $result = $registrations->register($input, $this->idempotencyKey($request, $input));

        $payload = $result['registration']->toStatusPayload();

        // The only time this value is ever transmitted. It is not stored in
        // plaintext anywhere and cannot be reissued: a client that loses it
        // has lost access to its own registration.
        if ($result['access_token'] !== null) {
            $payload['access_token'] = $result['access_token'];
        }

        return ApiResponse::data($payload, 202);
    }

    public function show(string $uuid, Request $request): JsonResponse
    {
        $registration = $this->authorizeRegistration($uuid, $request);

        if (! $registration instanceof Registration) {
            return $this->notFound();
        }

        return ApiResponse::data($registration->toStatusPayload());
    }

    /**
     * Re-queues a failed registration.
     *
     * Public but not unauthenticated: the person whose provisioning failed has
     * no account to sign in to — that is precisely what failed — so the access
     * token issued at submission is the credential.
     *
     * Throttled with the submit limiter rather than the poll limiter: a retry
     * enqueues real provisioning work (docs/08-AUDIT-SECURITY.md §13).
     *
     * Idempotent by design. A second call while a retry is already running
     * finds the status is `preparing`, not `failed`, and declines — so an
     * impatient click cannot queue provisioning twice (ADR-031).
     */
    public function retry(string $uuid, Request $request, RegistrationService $registrations): JsonResponse
    {
        $registration = $this->authorizeRegistration($uuid, $request);

        if (! $registration instanceof Registration) {
            return $this->notFound();
        }

        if (! $registrations->retry($registration)) {
            return ApiResponse::error(
                ApiErrorCode::RegistrationNotRetryable,
                $this->whyNotRetryable($registration),
            );
        }

        return ApiResponse::data($registration->fresh()?->toStatusPayload() ?? []);
    }

    /**
     * Locates a registration and verifies the presented capability.
     *
     * Returns null for both "no such registration" and "wrong token", so the
     * caller cannot tell them apart.
     */
    private function authorizeRegistration(string $uuid, Request $request): ?Registration
    {
        $registration = Registration::query()->where('uuid', $uuid)->first();

        if (! $registration instanceof Registration) {
            return null;
        }

        $presented = $request->headers->get(RegistrationAccessToken::HEADER);

        return $registration->accessTokenMatches($presented) ? $registration : null;
    }

    private function notFound(): JsonResponse
    {
        return ApiResponse::error(ApiErrorCode::NotFound, 'The requested resource was not found.');
    }

    /**
     * Says which of the two reasons applies. Safe: the caller has already
     * proven it holds this registration's capability.
     */
    private function whyNotRetryable(Registration $registration): string
    {
        if ($registration->status->isRetryable()) {
            return 'The retry window for this registration has closed. Please register again.';
        }

        return 'This registration is not in a state that can be retried.';
    }

    /**
     * Where the idempotency key comes from.
     *
     * A client-supplied `Idempotency-Key` is honoured when present. When it is
     * absent — a plain form post, a double-clicked button — one is derived from
     * the submitted identity instead, so a duplicate submission still resolves
     * to the same registration rather than creating a second center, a second
     * database and a second subscription (docs/10-API-FOUNDATION.md §6).
     *
     * @param  array<string, mixed>  $input
     */
    private function idempotencyKey(Request $request, array $input): string
    {
        $supplied = $request->headers->get('Idempotency-Key');

        if (is_string($supplied) && trim($supplied) !== '') {
            return 'client:'.substr(hash('sha256', trim($supplied)), 0, 100);
        }

        return 'derived:'.substr(hash('sha256', implode('|', [
            mb_strtolower((string) $input['center_name']),
            mb_strtolower((string) ($input['owner_email'] ?? '')),
            (string) ($input['owner_phone'] ?? ''),
        ])), 0, 100);
    }
}
