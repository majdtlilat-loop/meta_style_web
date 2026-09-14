<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Branches\Domain\BranchLock;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Resources\Domain\Models\ResourceType;
use App\Modules\Resources\Domain\Models\ServiceResourceRequirement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Replaces a service's resource requirements wholesale.
 *
 * "A laser session needs one treatment room and one laser machine" — sent as
 * the complete list, not as individual add/remove calls. A partial API here
 * would let a caller leave a service half-configured between two requests, and
 * a service that requires a room but not the machine is bookable and wrong
 * (docs/13-ROADMAP.md Phase 7 §5).
 *
 * ## Locks every branch
 *
 * A requirement change alters what is bookable EVERYWHERE the service is
 * offered, and a service is offered at every branch unless a pivot says
 * otherwise. Working out the exact set costs more than locking the handful of
 * branch rows a center has, so this takes them all — in ascending id order, as
 * `BranchLock` always does (ADR-047, Phase 7 corrections §2).
 *
 * ## Existing bookings are not touched
 *
 * They already hold concrete reservations. Adding a requirement does not
 * retroactively reserve a machine for next Tuesday's appointment, and removing
 * one does not release what was reserved. Only the NEXT booking reads this
 * table (§43).
 */
final class SetServiceResourceRequirements
{
    public function __construct(
        private readonly Audit $audit,
        private readonly BranchLock $lock,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $requirements
     * @return list<ServiceResourceRequirement>
     */
    public function __invoke(Service $service, array $requirements, User $actingUser): array
    {
        if (! $actingUser->hasPermission(Permission::ResourceManage)) {
            throw new AuthorizationException('You may not manage resources.');
        }

        $wanted = $this->resolve($requirements);
        $before = $this->snapshot($service);

        /** @var list<ServiceResourceRequirement> $saved */
        $saved = DB::connection('tenant')->transaction(function () use ($service, $wanted): array {
            $this->lock->acquireAll();

            ServiceResourceRequirement::query()
                ->where('service_id', $service->getKey())
                ->whereNotIn('resource_type_id', array_keys($wanted))
                ->delete();

            $rows = [];

            foreach ($wanted as $typeId => $quantity) {
                /** @var ServiceResourceRequirement $row */
                $row = ServiceResourceRequirement::query()->updateOrCreate(
                    ['service_id' => $service->getKey(), 'resource_type_id' => $typeId],
                    ['quantity' => $quantity],
                );

                $rows[] = $row;
            }

            return $rows;
        });

        $this->audit->record(new AuditEvent(
            action: 'resources.service_requirements.updated',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: Service::class,
            targetId: $service->uuid,
            targetLabel: (string) $service->name,
            before: $before,
            after: $this->snapshot($service->fresh() ?? $service),
        ));

        return $saved;
    }

    /**
     * Resolves the submitted list into type id => quantity.
     *
     * A type sent twice is a caller bug rather than a doubled requirement: the
     * unique key would refuse the second row anyway, so it is refused here with
     * a sentence somebody can act on.
     *
     * @param  array<int, array<string, mixed>>  $requirements
     * @return array<int, int>
     */
    private function resolve(array $requirements): array
    {
        $resolved = [];

        foreach ($requirements as $requirement) {
            $uuid = (string) ($requirement['type'] ?? '');
            $quantity = (int) ($requirement['quantity'] ?? 1);

            if ($quantity < 1 || $quantity > 255) {
                throw ValidationException::withMessages([
                    'requirements' => 'A requirement quantity must be between 1 and 255.',
                ]);
            }

            $type = ResourceType::query()->where('uuid', $uuid)->first();

            if (! $type instanceof ResourceType || ! $type->isBookable()) {
                throw ValidationException::withMessages([
                    'requirements' => 'One of those resource types is not available.',
                ]);
            }

            $id = (int) $type->getKey();

            if (isset($resolved[$id])) {
                throw ValidationException::withMessages([
                    'requirements' => 'That resource type is listed twice. Use one row with a quantity.',
                ]);
            }

            $resolved[$id] = $quantity;
        }

        return $resolved;
    }

    /**
     * @return array<string, int>
     */
    private function snapshot(Service $service): array
    {
        /** @var array<string, int> $rows */
        $rows = ServiceResourceRequirement::query()
            ->where('service_id', $service->getKey())
            ->with('type')
            ->get()
            ->mapWithKeys(static function (ServiceResourceRequirement $r): array {
                $type = $r->type;

                return [
                    ($type instanceof ResourceType ? $type->uuid : (string) $r->resource_type_id) => $r->quantity,
                ];
            })
            ->all();

        return $rows;
    }
}
