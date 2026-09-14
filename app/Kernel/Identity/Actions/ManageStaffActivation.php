<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\ActorType;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSource;
use App\Kernel\Identity\Exceptions\InvalidActivationToken;
use App\Kernel\Identity\Models\StaffActivationToken;
use App\Kernel\Identity\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Issues and redeems one-time links for staff to set their first password.
 *
 * The alternative — a manager typing a password on someone else's behalf — is
 * worse than it looks. The manager then holds a credential that authenticates
 * as that person, and every audit entry from that account becomes deniable.
 *
 * Phase 3 hands the link back to the manager to pass on directly. Delivery by
 * SMS or WhatsApp is Phase 13; standing up a notification provider purely to
 * carry these would be building a channel before there is anything to say.
 */
final class ManageStaffActivation
{
    private const LIFETIME_HOURS = 72;

    public function __construct(
        private readonly ChangePassword $changePassword,
        private readonly Audit $audit,
    ) {}

    /**
     * Issues a token, returning the plaintext exactly once.
     *
     * Any outstanding token for the user is revoked first: two live links for
     * one account means one of them is unaccounted for.
     */
    public function issue(User $user, ?User $issuedBy = null): string
    {
        $this->revokeOutstanding($user, 'superseded by a new activation link');

        $plaintext = Str::random(48);

        StaffActivationToken::query()->create([
            'user_id' => $user->getKey(),
            'token_hash' => StaffActivationToken::hash($plaintext),
            'expires_at' => Carbon::now()->addHours(self::LIFETIME_HOURS),
            'created_by_user_id' => $issuedBy?->getKey(),
        ]);

        $this->audit->record(new AuditEvent(
            action: 'identity.activation.issued',
            category: AuditCategory::Security,
            actor: $this->actorFor($issuedBy),
            targetType: User::class,
            targetId: $user->uuid,
            targetLabel: $user->name,
            // Expiry only. The token — and its hash — are never audited.
            meta: ['expires_in_hours' => self::LIFETIME_HOURS],
        ));

        return $plaintext;
    }

    /**
     * Redeems a token and sets the password.
     *
     * @throws InvalidActivationToken
     */
    public function redeem(string $plaintext, string $password): User
    {
        $token = StaffActivationToken::query()
            ->where('token_hash', StaffActivationToken::hash($plaintext))
            ->usable()
            ->first();

        if (! $token instanceof StaffActivationToken) {
            throw InvalidActivationToken::unusable();
        }

        /** @var User $user */
        $user = $token->user()->firstOrFail();

        // Single use, marked before the password is set so a concurrent second
        // redemption cannot slip through.
        $token->forceFill(['used_at' => Carbon::now()])->save();

        ($this->changePassword)($user, $password, $this->actorFor($user));

        $this->audit->record(new AuditEvent(
            action: 'identity.activation.redeemed',
            category: AuditCategory::Security,
            actor: $this->actorFor($user),
            targetType: User::class,
            targetId: $user->uuid,
            targetLabel: $user->name,
        ));

        return $user;
    }

    public function revokeOutstanding(User $user, string $reason, ?User $revokedBy = null): int
    {
        $tokens = StaffActivationToken::query()->where('user_id', $user->getKey())->usable()->get();

        if ($tokens->isEmpty()) {
            return 0;
        }

        StaffActivationToken::query()
            ->whereIn('id', $tokens->modelKeys())
            ->update(['revoked_at' => Carbon::now()]);

        $this->audit->record(new AuditEvent(
            action: 'identity.activation.revoked',
            category: AuditCategory::Security,
            actor: $this->actorFor($revokedBy),
            targetType: User::class,
            targetId: $user->uuid,
            targetLabel: $user->name,
            meta: ['revoked' => $tokens->count()],
            reason: $reason,
        ));

        return $tokens->count();
    }

    private function actorFor(?User $user): Actor
    {
        return $user === null
            ? Actor::system('staff-activation')
            : new Actor(ActorType::Staff, AuditSource::Web, (string) $user->getKey(), $user->name);
    }
}
