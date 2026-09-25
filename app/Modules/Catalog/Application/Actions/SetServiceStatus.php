<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Catalog\Domain\Models\Service;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Switches a service on or off — sold at all, shown on the menu, bookable
 * online — without touching anything else about it.
 *
 * A separate Action rather than a full {@see SaveService}: a quick toggle from
 * a list row does not have the whole form, and re-sending a partial form is
 * exactly how a description or a flag used to be reset by accident.
 *
 * `null` means "leave it". An archived service is refused: it comes back
 * through {@see ArchiveService::restore()}, which deliberately returns it
 * inactive so its price is checked first.
 */
final class SetServiceStatus
{
    public function __construct(private readonly Audit $audit) {}

    public function __invoke(
        Service $service,
        User $actingUser,
        ?bool $isActive = null,
        ?bool $isPublic = null,
        ?bool $isOnlineBookable = null,
    ): Service {
        if (! $actingUser->hasPermission(Permission::ServiceUpdate)) {
            throw new AuthorizationException('You may not change services.');
        }

        $wanted = array_filter([
            'is_active' => $isActive,
            'is_public' => $isPublic,
            'is_online_bookable' => $isOnlineBookable,
        ], static fn (?bool $value): bool => $value !== null);

        // Decided on the LOCKED row: a service archived in another tab since
        // this page rendered is refused, not quietly switched back on.
        /** @var array{0: array<string, bool>, 1: array<string, bool>} $change */
        $change = DB::connection('tenant')->transaction(function () use ($service, $wanted): array {
            $locked = Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();

            if ($locked->isArchived()) {
                throw ValidationException::withMessages([
                    'service' => 'Restore an archived service before changing it.',
                ]);
            }

            $before = [];
            $after = [];

            foreach ($wanted as $column => $value) {
                if ((bool) $locked->getAttribute($column) !== $value) {
                    $before[$column] = (bool) $locked->getAttribute($column);
                    $after[$column] = $value;
                }
            }

            if ($after !== []) {
                $locked->forceFill($after)->save();
            }

            return [$before, $after];
        });

        [$before, $after] = $change;

        if ($after === []) {
            return $service;
        }

        $service->forceFill($after)->syncOriginal();

        $this->audit->record(new AuditEvent(
            action: 'catalog.service.status_changed',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: Service::class,
            targetId: $service->uuid,
            targetLabel: (string) $service->name,
            before: $before,
            after: $after,
        ));

        return $service;
    }
}
