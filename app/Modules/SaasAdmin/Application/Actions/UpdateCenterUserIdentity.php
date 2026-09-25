<?php

declare(strict_types=1);

namespace App\Modules\SaasAdmin\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Identity\Models\User;
use App\Kernel\Platform\Directory\CenterUserDirectory;
use App\Kernel\Privacy\Fingerprint;
use App\Kernel\Tenancy\Infrastructure\StanclTenantContext;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use DomainException;

/**
 * Corrects a center user's identity and contact details from the platform:
 * name, email and phone. Nothing else — never a password, a role, a branch or
 * anything inside the center's business.
 *
 * The phone is REQUIRED: every center user account has one, so completing a
 * legacy account's missing phone is exactly how its identity is saved. Email
 * and phone stay unique within the center. The audit records which fields
 * changed and fingerprints of the contact values, never the values.
 */
final class UpdateCenterUserIdentity
{
    public function __construct(
        private readonly StanclTenantContext $context,
        private readonly CenterUserDirectory $directory,
        private readonly Audit $audit,
    ) {}

    public function __invoke(TenantModel $tenant, string $userUuid, string $name, ?string $email, PhoneNumber $phone, Actor $actor): void
    {
        $name = trim($name);
        $email = $email !== null && trim($email) !== '' ? mb_strtolower(trim($email)) : null;
        if ($name === '' || mb_strlen($name) > 190) {
            throw new DomainException(__('sadmin_center_users.errors.name'));
        }
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException(__('sadmin_center_users.errors.email'));
        }

        [$label, $before, $after] = $this->context->runForModel($tenant, function () use ($userUuid, $name, $email, $phone): array {
            /** @var User|null $user */
            $user = User::query()->where('uuid', $userUuid)->first();
            if (! $user instanceof User) {
                throw new DomainException(__('sadmin_centers.errors.person_missing'));
            }
            if ($email !== null && User::query()->where('email', $email)->whereKeyNot($user->getKey())->exists()) {
                throw new DomainException(__('sadmin_center_users.errors.email_taken'));
            }
            if (User::query()->where('phone', $phone->e164)->whereKeyNot($user->getKey())->exists()) {
                throw new DomainException(__('phone_field.errors.taken'));
            }

            $before = ['name' => $user->name, 'email' => Fingerprint::of($user->email), 'phone' => Fingerprint::of($user->phone)];
            $user->forceFill(['name' => $name, 'email' => $email, 'phone' => $phone->e164])->save();
            $after = ['name' => $user->name, 'email' => Fingerprint::of($user->email), 'phone' => Fingerprint::of($user->phone)];

            return [$user->name, $before, $after];
        });

        $changed = array_keys(array_filter($after, static fn ($value, string $key): bool => $before[$key] !== $value, ARRAY_FILTER_USE_BOTH));
        if ($changed !== []) {
            $this->audit->recordForTenant((string) $tenant->getTenantKey(), new AuditEvent(
                action: 'platform.center.user.identity_updated',
                category: AuditCategory::Security,
                actor: $actor,
                severity: AuditSeverity::Notice,
                targetType: User::class,
                targetId: $userUuid,
                targetLabel: $label,
                before: array_intersect_key($before, array_flip($changed)),
                after: array_intersect_key($after, array_flip($changed)) + ['fields' => $changed],
            ));
        }

        $this->directory->refreshTenant($tenant);
    }
}
