<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\ActorType;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSource;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\TenantApiToken;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Infrastructure\TenantModel;

/**
 * Issues a tenant-bound API token.
 *
 * The returned string is the ONLY time the plaintext exists — Sanctum stores a
 * hash. It is prefixed with the tenant's public key so the token can identify
 * its own center on the way back in (docs/DECISIONS.md ADR-027).
 *
 * The token row itself is written to the tenant's database, which is the
 * binding that actually matters: presenting it under another tenant finds
 * nothing to authenticate against.
 */
final class IssueApiToken
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Audit $audit,
    ) {}

    /**
     * @return array{token: string, expires_at: string|null}
     */
    public function __invoke(User $user, string $name = 'api'): array
    {
        $tenant = $this->tenants->require();

        $publicKey = TenantModel::query()->whereKey($tenant->id)->value('public_key');

        // A tenant with no public key cannot issue a token that can find its
        // way home. Failing loudly beats issuing something unusable.
        if (! is_string($publicKey) || $publicKey === '') {
            throw new \RuntimeException(
                "Tenant [{$tenant->id}] has no public key; a tenant-bound token cannot be issued."
            );
        }

        $expiresAt = now()->addMinutes((int) config('sanctum.expiration', 43200));

        $newToken = $user->createToken($name, ['*'], $expiresAt);

        $this->audit->record(new AuditEvent(
            action: 'identity.token.issued',
            category: AuditCategory::Security,
            actor: new Actor(ActorType::Staff, AuditSource::Api, (string) $user->getKey(), $user->name),
            targetType: User::class,
            targetId: $user->uuid,
            targetLabel: $user->name,
            // The token id is recorded so a specific credential can be traced
            // and revoked. The plaintext never is.
            meta: ['token_id' => $newToken->accessToken->getKey(), 'name' => $name],
        ));

        return [
            'token' => TenantApiToken::format($publicKey, $newToken->plainTextToken),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }
}
