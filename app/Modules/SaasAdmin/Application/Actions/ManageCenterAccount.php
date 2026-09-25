<?php

declare(strict_types=1);

namespace App\Modules\SaasAdmin\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Identity\Actions\IssueCenterPasswordLink;
use App\Kernel\Identity\Actions\ManageStaffActivation;
use App\Kernel\Identity\Actions\RevokeApiTokens;
use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Infrastructure\StanclTenantContext;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Modules\SaasAdmin\Mail\CenterAccessLinkEmail;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Account lifecycle help a Super Admin can give a center's user.
 *
 * Deliberately narrow: block or reactivate a sign-in, and email a one-time
 * link to set a new password. The platform never sees or sets a center
 * user's password, never signs in as them, and never touches anything else in
 * the center. The owner cannot be blocked from here — the center would lose
 * its only administrator; suspend the center instead.
 *
 * Blocking works like a manager deactivating staff: the login is disabled,
 * API tokens are revoked and any outstanding activation link is withdrawn.
 * Every step is audited against the center.
 */
final class ManageCenterAccount
{
    public function __construct(
        private readonly StanclTenantContext $context,
        private readonly RevokeApiTokens $revokeTokens,
        private readonly ManageStaffActivation $activation,
        private readonly IssueCenterPasswordLink $links,
        private readonly Audit $audit,
    ) {}

    public function setActive(TenantModel $tenant, string $userUuid, bool $active, Actor $actor, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new DomainException(__('sadmin_centers.errors.reason'));
        }

        $name = $this->context->runForModel($tenant, function () use ($userUuid, $active, $actor, $reason): string {
            /** @var User|null $user */
            $user = User::query()->where('uuid', $userUuid)->first();
            if (! $user instanceof User) {
                throw new DomainException(__('sadmin_centers.errors.person_missing'));
            }
            if (! $active && $user->is_owner) {
                throw new DomainException(__('sadmin_centers.errors.owner_block'));
            }

            DB::connection('tenant')->transaction(function () use ($user, $active, $actor, $reason): void {
                $user->forceFill(['is_active' => $active])->save();
                if (! $active) {
                    ($this->revokeTokens)($user, 'blocked by platform: '.$reason, $actor);
                    $this->activation->revokeOutstanding($user, 'blocked by platform');
                }
            });

            return $user->name;
        });

        $this->audit->recordForTenant($tenant->getTenantKey(), new AuditEvent(
            action: $active ? 'platform.center.user.reactivated' : 'platform.center.user.blocked',
            category: AuditCategory::Security,
            actor: $actor,
            severity: $active ? AuditSeverity::Notice : AuditSeverity::Warning,
            targetType: User::class,
            targetId: $userUuid,
            targetLabel: $name,
            before: ['active' => ! $active],
            after: ['active' => $active],
            reason: $reason,
        ));
    }

    public function sendAccessLink(TenantModel $tenant, string $userUuid, Actor $actor): void
    {
        $slug = $tenant->slug;
        if ($slug === null) {
            throw new DomainException(__('sadmin_centers.errors.no_address'));
        }

        [$email, $name, $url] = $this->context->runForModel($tenant, function () use ($userUuid, $slug): array {
            /** @var User|null $user */
            $user = User::query()->where('uuid', $userUuid)->where('is_active', true)->first();
            if (! $user instanceof User || ! is_string($user->email) || $user->email === '') {
                throw new DomainException(__('sadmin_centers.errors.no_email'));
            }

            return [$user->email, $user->name, ($this->links)($user, $slug, 24 * 60)];
        });

        Mail::to($email)->queue(new CenterAccessLinkEmail($name, $tenant->name, $url));

        $this->audit->recordForTenant($tenant->getTenantKey(), new AuditEvent(
            action: 'platform.center.user.access_link_sent',
            category: AuditCategory::Security,
            actor: $actor,
            targetType: User::class,
            targetId: $userUuid,
            targetLabel: $name,
        ));
    }
}
