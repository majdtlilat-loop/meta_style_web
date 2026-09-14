<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Audit\Enums\AuditSource;
use App\Kernel\Http\ApiErrorCode;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Actions\AuthenticateStaff;
use App\Kernel\Identity\Actions\IssueApiToken;
use App\Kernel\Identity\Exceptions\AuthenticationFailed;
use App\Kernel\Identity\LoginThrottle;
use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Contracts\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Issues and revokes tenant-bound API tokens.
 *
 * The login endpoint is the ONLY place a client-supplied center identifier is
 * accepted, and only because there is no session yet: something has to say
 * which center to authenticate against. The `center_key` is opaque, revocable,
 * resolved server-side, and authorises nothing on its own — valid credentials
 * in that center's database are still required. Every subsequent request takes
 * its tenant from the token binding, never from the client
 * (docs/02-TENANCY.md §2.2, source 3).
 *
 * The internal tenant id and sequence are never accepted here. They are not
 * revocable and the sequence leaks how many centers exist.
 */
final class TokenController extends Controller
{
    public function store(
        Request $request,
        TenantResolver $resolver,
        TenantContext $context,
        AuthenticateStaff $authenticate,
        IssueApiToken $issueToken,
        LoginThrottle $throttle,
    ): JsonResponse {
        $validated = $request->validate([
            'center_key' => ['required', 'string', 'max:64'],
            'identifier' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $tenant = $resolver->findByPublicKey((string) $validated['center_key']);

        if ($tenant === null) {
            // Indistinguishable from bad credentials: a different answer here
            // would turn this endpoint into a way to discover which center keys
            // are real.
            //
            // Counted too, and for the same reason. `AuthenticateStaff` now
            // answers a sixth attempt against a REAL center with 429, so an
            // unknown key that never ran out of attempts would announce itself.
            // The tenant-less scope is shared by no center, so this consumes
            // nobody's allowance. A 429 raised here renders itself.
            $identifier = mb_strtolower(trim((string) $validated['identifier']));

            $throttle->assertAllowed($identifier);
            $throttle->recordFailure($identifier);

            return ApiResponse::error(
                ApiErrorCode::InvalidCredentials,
                'Those credentials do not match our records.',
            );
        }

        try {
            /** @var array{token: string, expires_at: string|null} $token */
            $token = $context->run($tenant, function () use ($authenticate, $issueToken, $validated): array {
                $user = $authenticate(
                    (string) $validated['identifier'],
                    (string) $validated['password'],
                    AuditSource::Api,
                );

                return $issueToken($user, (string) ($validated['device_name'] ?? 'api'));
            });
        } catch (AuthenticationFailed $e) {
            return ApiResponse::error(ApiErrorCode::InvalidCredentials, $e->getMessage());
        }

        return ApiResponse::data([
            'token' => $token['token'],
            'expires_at' => $token['expires_at'],
        ], 201);
    }

    /**
     * Revokes the token used to make this request.
     */
    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Reached only through auth:sanctum, so a current token is guaranteed.
        $user->currentAccessToken()->delete();

        return ApiResponse::data(['revoked' => true]);
    }
}
