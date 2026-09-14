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
 * Sets a user's password and invalidates everything issued under the old one.
 *
 * Revoking tokens is not optional politeness: changing a password is what
 * someone does when they believe a credential is compromised, and leaving old
 * tokens valid defeats the entire action.
 */
final class ChangePassword
{
    public function __construct(
        private readonly RevokeApiTokens $revokeTokens,
        private readonly Audit $audit,
    ) {}

    public function __invoke(User $user, string $newPassword, ?Actor $actor = null): void
    {
        // The `hashed` cast handles hashing; the plaintext never leaves here.
        $user->forceFill([
            'password' => $newPassword,
            'password_changed_at' => now(),
        ])->save();

        ($this->revokeTokens)($user, 'password changed', $actor);

        $this->audit->record(new AuditEvent(
            action: 'identity.password.changed',
            category: AuditCategory::Security,
            actor: $actor ?? new Actor(ActorType::Staff, AuditSource::Web, (string) $user->getKey(), $user->name),
            targetType: User::class,
            targetId: $user->uuid,
            targetLabel: $user->name,
            // No before/after: the only fields that changed are the credential
            // and its timestamp, and neither belongs in a durable log.
        ));
    }
}
