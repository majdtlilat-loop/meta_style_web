<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Catalog\Domain\Data\ServiceInput;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceVariation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a service, with its variations, add-ons, branches and
 * eligible employees, in one transaction.
 *
 * All of it together because that is how the form works: an owner edits a
 * service and saves once. Splitting it into five actions would create a window
 * where a service exists with a price but no branches, and the menu would show
 * it as available nowhere.
 *
 * PRICE AND DURATION CHANGES ARE AUDITED WITH BEFORE AND AFTER. They are the
 * money-shaped fields in this module: "the price was wrong on the invoice" is
 * only answerable if the trail says what it was and when it changed
 * (docs/08-AUDIT-SECURITY.md §3).
 */
final class SaveService
{
    public function __construct(private readonly Audit $audit) {}

    public function __invoke(ServiceInput $input, User $actingUser, ?Service $service = null): Service
    {
        $this->authorize($actingUser, $service);
        $this->validate($input);

        $existing = $service;

        $before = $existing === null ? null : [
            'price_minor' => $existing->price_minor,
            'duration_minutes' => $existing->duration_minutes,
            'is_active' => $existing->is_active,
            'is_public' => $existing->is_public,
        ];

        /** @var Service $saved */
        $saved = DB::connection('tenant')->transaction(function () use ($input, $existing): Service {
            $service = $existing ?? new Service;

            $service->forceFill([
                'department_id' => $input->departmentId,
                'service_category_id' => $input->serviceCategoryId,
                'name' => TranslatedText::fromArray($input->name),
                'short_description' => $input->shortDescription === []
                    ? null
                    : TranslatedText::fromArray($input->shortDescription),
                'description' => $input->description === []
                    ? null
                    : TranslatedText::fromArray($input->description),
                'duration_minutes' => $input->durationMinutes,
                'price_minor' => $input->priceMinor,
                'is_active' => $input->isActive,
                'is_public' => $input->isPublic,
                'is_online_bookable' => $input->isOnlineBookable,
                'available_at_all_branches' => $input->availableAtAllBranches,
                'sort_order' => $input->sortOrder,
            ]);

            $service->save();

            if ($input->touchesVariations()) {
                $this->syncVariations($service, $input->variations ?? []);
            }

            if ($input->touchesAddons()) {
                $service->addons()->sync($input->addonIds ?? []);
            }

            if ($input->touchesBranches()) {
                // "Everywhere" is the absence of rows, so restricting to all
                // branches would be a contradiction: clear the pivot instead.
                $service->branches()->sync($input->availableAtAllBranches ? [] : ($input->branchIds ?? []));
            }

            if ($input->touchesEmployees()) {
                $service->eligibleEmployees()->sync($input->employeeIds ?? []);
            }

            return $service;
        });

        $this->audit->record(new AuditEvent(
            action: $existing === null ? 'catalog.service.created' : 'catalog.service.updated',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: Service::class,
            targetId: $saved->uuid,
            targetLabel: (string) $saved->name,
            before: $before,
            after: [
                'price_minor' => $saved->price_minor,
                'duration_minutes' => $saved->duration_minutes,
                'is_active' => $saved->is_active,
                'is_public' => $saved->is_public,
            ],
        ));

        return $saved;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $actingUser, ?Service $service): void
    {
        $needed = $service === null ? Permission::ServiceCreate : Permission::ServiceUpdate;

        if (! $actingUser->hasPermission($needed)) {
            throw new AuthorizationException(
                $service === null ? 'You may not add services.' : 'You may not change services.'
            );
        }
    }

    /**
     * @throws ValidationException
     */
    private function validate(ServiceInput $input): void
    {
        if ($input->durationMinutes < 1) {
            throw ValidationException::withMessages([
                'duration_minutes' => 'A service must take at least one minute.',
            ]);
        }

        if ($input->priceMinor < 0) {
            throw ValidationException::withMessages([
                'price_minor' => 'A price cannot be negative.',
            ]);
        }

        // A service restricted to no branches is available nowhere, which is
        // never what anyone meant — they meant "all branches", or they forgot
        // to pick one.
        if (! $input->availableAtAllBranches && $input->touchesBranches() && ($input->branchIds ?? []) === []) {
            throw ValidationException::withMessages([
                'branch_ids' => 'Choose at least one branch, or make the service available at all branches.',
            ]);
        }
    }

    /**
     * Replaces the variation set, preserving rows the caller sent back by uuid.
     *
     * Preserving matters: a variation's id will be referenced by bookings and
     * invoice lines from Phase 6, so delete-and-recreate on every save would
     * detach history from the thing it was for.
     *
     * @param  list<array{uuid?: string|null, name: array<string, string|null>, price_minor?: int|null, duration_minutes?: int|null, is_active?: bool}>  $variations
     */
    private function syncVariations(Service $service, array $variations): void
    {
        $keptIds = [];

        foreach ($variations as $index => $variation) {
            $uuid = $variation['uuid'] ?? null;

            $model = is_string($uuid) && $uuid !== ''
                ? $service->variations()->where('uuid', $uuid)->first()
                : null;

            $model ??= new ServiceVariation(['service_id' => $service->id]);

            $model->forceFill([
                'service_id' => $service->id,
                'name' => TranslatedText::fromArray($variation['name']),
                // Null is meaningful here and must survive: it is what makes
                // the variation follow the service's price rather than freeze
                // a copy of it (ADR-037).
                'price_minor' => $variation['price_minor'] ?? null,
                'duration_minutes' => $variation['duration_minutes'] ?? null,
                'is_active' => $variation['is_active'] ?? true,
                'sort_order' => $index,
            ]);

            $model->save();

            $keptIds[] = $model->id;
        }

        $service->variations()->whereNotIn('id', $keptIds === [] ? [0] : $keptIds)->delete();
    }
}
