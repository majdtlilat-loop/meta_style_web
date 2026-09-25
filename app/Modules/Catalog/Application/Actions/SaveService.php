<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Catalog\Application\Ordering\CatalogLayout;
use App\Modules\Catalog\Application\Ordering\CatalogOrdering;
use App\Modules\Catalog\Domain\Data\ServiceInput;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use App\Modules\Catalog\Domain\Models\ServiceVariation;
use App\Modules\Departments\Domain\Models\Department;
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
 *
 * A VARIATION IS NEVER DELETED. One the caller leaves out is deactivated:
 * package definitions and customer packages reference variations with a
 * restricting foreign key, and bookings and invoice lines point at them for
 * history. Deactivated, it stops being offered and history keeps its link.
 */
final class SaveService
{
    private const MAX_PRICE_MINOR = 1_000_000_000_000;

    private const MAX_DURATION_MINUTES = 1440;

    public function __construct(
        private readonly Audit $audit,
        private readonly CatalogOrdering $ordering,
        private readonly LanguageRegistry $languages,
    ) {}

    public function __invoke(ServiceInput $input, User $actingUser, ?Service $service = null): Service
    {
        $this->authorize($actingUser, $service);

        $name = $this->text($input->name);
        $this->validate($input, $name, $service);

        $existing = $service;

        $before = $existing === null ? null : $this->snapshot($existing);

        /** @var Service $saved */
        $saved = DB::connection('tenant')->transaction(function () use ($input, $existing, $name): Service {
            $categoryChanged = $existing !== null
                && (int) ($existing->service_category_id ?? 0) !== (int) ($input->serviceCategoryId ?? 0);
            $place = $input->sortOrder === null
                && ($existing === null || ($categoryChanged && ! $existing->isArchived()));

            // Ordering locks first, before this transaction writes the service
            // row: every ordering writer takes categories, then services.
            $layout = $place ? $this->ordering->lock() : null;

            $service = $existing ?? new Service;

            $service->forceFill([
                'department_id' => $input->departmentId,
                'service_category_id' => $input->serviceCategoryId,
                'name' => $name,
                'short_description' => $this->optionalText($input->shortDescription),
                'description' => $this->optionalText($input->description),
                'duration_minutes' => $input->durationMinutes,
                'price_minor' => $input->priceMinor,
                'is_active' => $input->isActive,
                'is_public' => $input->isPublic,
                'is_online_bookable' => $input->isOnlineBookable,
                'available_at_all_branches' => $input->availableAtAllBranches,
                'sort_order' => $input->sortOrder === null
                    ? ($existing->sort_order ?? 0)
                    : max(0, min(65535, $input->sortOrder)),
            ]);

            $service->save();

            if ($layout instanceof CatalogLayout) {
                $group = $input->serviceCategoryId ?? CatalogLayout::UNCATEGORISED;
                $layout->placeService($service, $layout->hasGroup($group) ? $group : CatalogLayout::UNCATEGORISED);
                $layout->persist();
            }

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

            return $service->refresh();
        });

        $this->audit->record(new AuditEvent(
            action: $existing === null ? 'catalog.service.created' : 'catalog.service.updated',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: Service::class,
            targetId: $saved->uuid,
            targetLabel: (string) $saved->name,
            before: $before,
            after: $this->snapshot($saved),
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
    private function validate(ServiceInput $input, TranslatedText $name, ?Service $service): void
    {
        if ($name->isEmpty()) {
            throw ValidationException::withMessages(['name' => 'A service needs a name.']);
        }

        if ($input->durationMinutes < 1 || $input->durationMinutes > self::MAX_DURATION_MINUTES) {
            throw ValidationException::withMessages([
                'duration_minutes' => 'A service must take between one minute and one day.',
            ]);
        }

        if ($input->priceMinor < 0 || $input->priceMinor > self::MAX_PRICE_MINOR) {
            throw ValidationException::withMessages([
                'price_minor' => 'That price is outside the allowed range.',
            ]);
        }

        // An archived department or category is refused when it is being
        // CHOSEN. One the service already sits in stays valid, so an old
        // service can still be edited without re-filing it first.
        if ($input->departmentId !== null && $input->departmentId !== (int) ($service->department_id ?? 0)
            && ! Department::query()->whereKey($input->departmentId)->whereNull('archived_at')->exists()) {
            throw ValidationException::withMessages(['department' => 'That department is not available.']);
        }

        if ($input->serviceCategoryId !== null && $input->serviceCategoryId !== (int) ($service->service_category_id ?? 0)
            && ! ServiceCategory::query()->whereKey($input->serviceCategoryId)->whereNull('archived_at')->exists()) {
            throw ValidationException::withMessages(['category' => 'That category is not available.']);
        }

        // A service restricted to no branches is available nowhere, which is
        // never what anyone meant — they meant "all branches", or they forgot
        // to pick one.
        if (! $input->availableAtAllBranches && $input->touchesBranches() && ($input->branchIds ?? []) === []) {
            throw ValidationException::withMessages([
                'branch_ids' => 'Choose at least one branch, or make the service available at all branches.',
            ]);
        }

        foreach ($input->variations ?? [] as $index => $variation) {
            if ($this->text($variation['name'])->isEmpty()) {
                throw ValidationException::withMessages(["variations.{$index}.name" => 'A variation needs a name.']);
            }

            $duration = $variation['duration_minutes'] ?? null;

            if ($duration !== null && ($duration < 1 || $duration > self::MAX_DURATION_MINUTES)) {
                throw ValidationException::withMessages([
                    "variations.{$index}.duration_minutes" => 'A variation must take between one minute and one day.',
                ]);
            }

            $price = $variation['price_minor'] ?? null;

            if ($price !== null && ($price < 0 || $price > self::MAX_PRICE_MINOR)) {
                throw ValidationException::withMessages([
                    "variations.{$index}.price_minor" => 'That price is outside the allowed range.',
                ]);
            }
        }
    }

    /**
     * Keeps only languages the platform knows, trimmed; blanks are dropped.
     *
     * @param  array<string, string|null>  $values
     */
    private function text(array $values): TranslatedText
    {
        $clean = [];

        foreach ($values as $locale => $value) {
            if ($this->languages->supports((string) $locale) && is_string($value)) {
                $clean[$locale] = trim($value);
            }
        }

        return TranslatedText::fromArray($clean);
    }

    /**
     * @param  array<string, string|null>  $values
     */
    private function optionalText(array $values): ?TranslatedText
    {
        $text = $this->text($values);

        return $text->isEmpty() ? null : $text;
    }

    /**
     * Replaces the variation set, preserving rows the caller sent back by uuid.
     *
     * Preserving matters: bookings, invoice lines and packages reference a
     * variation's id, so delete-and-recreate on every save would detach
     * history from the thing it was for. Rows left out are DEACTIVATED, never
     * deleted (see the class comment).
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
                'name' => $this->text($variation['name']),
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

        $service->variations()
            ->whereNotIn('id', $keptIds === [] ? [0] : $keptIds)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Service $service): array
    {
        return [
            'price_minor' => $service->price_minor,
            'duration_minutes' => $service->duration_minutes,
            'is_active' => $service->is_active,
            'is_public' => $service->is_public,
            'is_online_bookable' => $service->is_online_bookable,
        ];
    }
}
