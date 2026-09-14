<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Audit\Enums\AuditSource;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Http\ApiErrorCode;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Exceptions\AuthenticationFailed;
use App\Kernel\Identity\TenantApiToken;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Contracts\TenantResolver;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Modules\Customers\Application\Actions\AuthenticateCustomer;
use App\Modules\Customers\Application\Actions\RegisterCustomerAccount;
use App\Modules\Customers\Domain\Models\CustomerAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer self-registration and login.
 *
 * COMPLETELY SEPARATE FROM STAFF AUTHENTICATION. Different action, different
 * guard, different token owner, no roles or permissions anywhere. A customer is
 * not a member of staff with fewer boxes ticked
 * (docs/13-ROADMAP.md Phase 5 §§5, 23).
 *
 * Like the staff login, these two endpoints are the only place a client-supplied
 * center identifier is accepted, and for the same reason: there is no session
 * yet, so something has to say which center. The `center_key` is opaque,
 * revocable, resolved server-side, and authorises nothing on its own
 * (docs/02-TENANCY.md §2.2).
 *
 * Both are gated on the `customer_accounts` entitlement — but that gate lives in
 * the Actions, not here, so the WhatsApp bot and RAYAN get it too when they
 * arrive.
 */
final class CustomerAuthController extends Controller
{
    public function register(
        Request $request,
        TenantResolver $resolver,
        TenantContext $context,
        RegisterCustomerAccount $register,
    ): JsonResponse {
        $validated = $request->validate([
            'center_key' => ['required', 'string', 'max:64'],
            'phone' => ['required', 'string', 'max:32'],
            'password' => ['required', 'string', 'min:8', 'max:190'],
            // Required for a NEW customer. The action enforces it too, and
            // explains why: without a name the phone number would end up in a
            // field that is never masked.
            'name' => ['nullable', 'string', 'max:190'],
            'locale' => ['nullable', 'string', 'max:12'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $tenant = $resolver->findByPublicKey((string) $validated['center_key']);

        if ($tenant === null) {
            return $this->unknownCenter();
        }

        return $context->run($tenant, function () use ($validated, $register, $tenant): JsonResponse {
            try {
                $result = $register(
                    (string) $validated['phone'],
                    (string) $validated['password'],
                    $validated['name'] ?? null,
                    $validated['locale'] ?? null,
                );
            } catch (EntitlementRequired $e) {
                return $this->accountsUnavailable($e->entitlement);
            }

            $token = $this->issueToken($result['account'], $tenant->id, $validated['device_name'] ?? 'customer');

            return ApiResponse::data([
                'customer' => ['uuid' => $result['customer']->uuid, 'name' => $result['customer']->name],
                'account' => [
                    'uuid' => $result['account']->uuid,
                    // Always false in Phase 5. Said plainly rather than omitted,
                    // so a client cannot mistake absence for verification.
                    'phone_verified' => false,
                ],
                'token' => $token,
            ], 201);
        });
    }

    public function token(
        Request $request,
        TenantResolver $resolver,
        TenantContext $context,
        AuthenticateCustomer $authenticate,
    ): JsonResponse {
        $validated = $request->validate([
            'center_key' => ['required', 'string', 'max:64'],
            'phone' => ['required', 'string', 'max:32'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $tenant = $resolver->findByPublicKey((string) $validated['center_key']);

        if ($tenant === null) {
            // Indistinguishable from bad credentials: a different answer would
            // turn this into a way to discover which center keys are real.
            return $this->invalidCredentials();
        }

        return $context->run($tenant, function () use ($validated, $authenticate, $tenant): JsonResponse {
            try {
                $account = $authenticate(
                    (string) $validated['phone'],
                    (string) $validated['password'],
                    AuditSource::Api,
                );
            } catch (EntitlementRequired $e) {
                return $this->accountsUnavailable($e->entitlement);
            } catch (AuthenticationFailed) {
                return $this->invalidCredentials();
            }

            return ApiResponse::data([
                'token' => $this->issueToken($account, $tenant->id, $validated['device_name'] ?? 'customer'),
            ]);
        });
    }

    /**
     * The signed-in customer's own record.
     *
     * Runs under `auth:customer-api`, so a staff token cannot reach it.
     */
    public function me(Request $request): JsonResponse
    {
        $account = $request->user('customer-api');

        if (! $account instanceof CustomerAccount) {
            return ApiResponse::error(ApiErrorCode::Unauthenticated, 'Authentication is required.');
        }

        $customer = $account->customer;

        return ApiResponse::data([
            'customer' => [
                'uuid' => $customer->uuid,
                'name' => $customer->name,
                // The customer's OWN contact details, shown in full. Masking
                // protects a person's details from staff who do not need them,
                // not from the person themselves.
                'phone' => $customer->phone,
                'email' => $customer->email,
                'preferred_locale' => $customer->preferred_locale,
            ],
            'account' => [
                'uuid' => $account->uuid,
                'phone_verified' => $account->hasVerifiedPhone(),
                'last_login_at' => $account->last_login_at?->toIso8601String(),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $account = $request->user('customer-api');

        if ($account instanceof CustomerAccount) {
            // Every token, not just this one. A customer asking to sign out
            // after losing a phone means all of them.
            $account->tokens()->delete();
        }

        return ApiResponse::data(['revoked' => true]);
    }

    /**
     * @return array{token: string, expires_at: string|null}
     */
    private function issueToken(CustomerAccount $account, string $tenantId, string $deviceName): array
    {
        $publicKey = TenantModel::query()->whereKey($tenantId)->value('public_key');

        if (! is_string($publicKey) || $publicKey === '') {
            throw new \RuntimeException("Tenant [{$tenantId}] has no public key; a token cannot be issued.");
        }

        $expiresAt = now()->addMinutes((int) config('sanctum.expiration', 43200));

        $newToken = $account->createToken($deviceName, ['*'], $expiresAt);

        // The same wire format staff tokens use, so `ResolveTenant` finds the
        // center from the prefix. The row lives in this center's database,
        // which is the binding that actually matters (ADR-027).
        return [
            'token' => TenantApiToken::format($publicKey, $newToken->plainTextToken),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    private function invalidCredentials(): JsonResponse
    {
        return ApiResponse::error(
            ApiErrorCode::InvalidCredentials,
            'Those details do not match our records.',
        );
    }

    private function unknownCenter(): JsonResponse
    {
        return ApiResponse::error(ApiErrorCode::NotFound, 'The requested resource was not found.');
    }

    private function accountsUnavailable(string $entitlement): JsonResponse
    {
        return ApiResponse::error(
            ApiErrorCode::EntitlementNotAvailable,
            'This center does not offer customer accounts.',
            ['entitlement' => $entitlement],
        );
    }
}
