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

/**
 * Drops every API token a user holds.
 *
 * Called on sign-out, on password change and on deactivation. A credential
 * that outlives the reason it was issued is the quiet half of most account
 * compromises (docs/06-AUTH-ROLES-PERMISSIONS.md §7).
 */
final class RevokeApiTokens
{
    public function __construct(private readonly Audit $audit) {}

    public function __invoke(User $user, string $reason, ?Actor $actor = null): int
    {
        $count = $user->tokens()->count();

        if ($count === 0) {
            return 0;
        }

        $user->tokens()->delete();

        $this->audit->record(new AuditEvent(
            action: 'identity.token.revoked',
            category: AuditCategory::Security,
            actor: $actor ?? new Actor(ActorType::Staff, AuditSource::Web, (string) $user->getKey(), $user->name),
            targetType: User::class,
            targetId: $user->uuid,
            targetLabel: $user->name,
            meta: ['revoked' => $count],
            reason: $reason,
        ));

        return $count;
    }
}
