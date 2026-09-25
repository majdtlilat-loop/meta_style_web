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
use App\Modules\Catalog\Application\Ordering\CatalogOrdering;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceVariation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Copies a service as a starting point for a similar one.
 *
 * THE COPY IS INACTIVE AND NOT ON THE MENU, like a restored service: a copy is
 * a draft, and a duplicate that went straight onto the public menu at the
 * original's price is how a center ends up selling "Haircut (copy)".
 *
 * Copied: department, category, texts, price, duration, the online-booking
 * intent, branch availability, the ACTIVE variations (new uuids, and a null
 * price or duration stays null so it keeps inheriting — ADR-037), add-ons and
 * eligible employees. Not copied: images (never share a stored path), internal
 * notes, and resource requirements — those belong to the Resources module,
 * which imports Catalog rather than the other way round; the caller copies
 * them in the same transaction when it may (docs/04-MODULE-BOUNDARIES.md).
 */
final class DuplicateService
{
    public function __construct(
        private readonly Audit $audit,
        private readonly CatalogOrdering $ordering,
    ) {}

    /**
     * @param  array<string, string|null>  $name  the copy's name; empty keeps the original's
     */
    public function __invoke(Service $source, User $actingUser, array $name = []): Service
    {
        if (! $actingUser->hasPermission(Permission::ServiceCreate)) {
            throw new AuthorizationException('You may not add services.');
        }

        if ($source->isArchived()) {
            throw ValidationException::withMessages([
                'service' => 'Restore an archived service before copying it.',
            ]);
        }

        $copyName = TranslatedText::fromArray($name);

        /** @var Service $copy */
        $copy = DB::connection('tenant')->transaction(function () use ($source, $copyName): Service {
            $layout = $this->ordering->lock();

            if ($layout->locateService((int) $source->id) === null) {
                throw ValidationException::withMessages([
                    'service' => 'Restore an archived service before copying it.',
                ]);
            }

            $copy = new Service;
            $copy->forceFill([
                'department_id' => $source->department_id,
                'service_category_id' => $source->service_category_id,
                'name' => $copyName->isEmpty() ? $source->name : $copyName,
                'short_description' => $source->short_description,
                'description' => $source->description,
                'duration_minutes' => $source->duration_minutes,
                'price_minor' => $source->price_minor,
                'is_active' => false,
                'is_public' => false,
                'is_online_bookable' => $source->is_online_bookable,
                'available_at_all_branches' => $source->available_at_all_branches,
                'sort_order' => 0,
            ])->save();

            $source->variations()->where('is_active', true)->get()
                ->each(function (ServiceVariation $variation) use ($copy): void {
                    $copy->variations()->create([
                        'name' => $variation->name,
                        'price_minor' => $variation->price_minor,
                        'duration_minutes' => $variation->duration_minutes,
                        'is_active' => true,
                        'sort_order' => $variation->sort_order,
                    ]);
                });

            $addons = [];
            $links = DB::connection('tenant')->table('service_addon_service')
                ->where('service_id', $source->id)
                ->get(['service_addon_id', 'sort_order']);
            foreach ($links as $link) {
                $addons[(int) $link->service_addon_id] = ['sort_order' => (int) $link->sort_order];
            }
            $copy->addons()->sync($addons);

            if (! $source->available_at_all_branches) {
                $copy->branches()->sync($source->branches()->pluck('branches.id')->all());
            }

            $copy->eligibleEmployees()->sync($source->eligibleEmployees()->pluck('employees.id')->all());

            $layout->placeServiceAfter($copy, $source);
            $layout->persist();

            return $copy;
        });

        $this->audit->record(new AuditEvent(
            action: 'catalog.service.duplicated',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: Service::class,
            targetId: $copy->uuid,
            targetLabel: (string) $copy->name,
            after: [
                'source' => $source->uuid,
                'price_minor' => $copy->price_minor,
                'duration_minutes' => $copy->duration_minutes,
                'is_active' => false,
                'is_public' => false,
            ],
        ));

        return $copy->refresh();
    }
}
