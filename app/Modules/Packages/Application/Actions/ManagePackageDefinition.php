<?php

declare(strict_types=1);

namespace App\Modules\Packages\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceVariation;
use App\Modules\Packages\Application\PackagesAccess;
use App\Modules\Packages\Application\PackagesAudit;
use App\Modules\Packages\Domain\Exceptions\PackagesFailed;
use App\Modules\Packages\Domain\Models\PackageDefinition;
use App\Modules\Packages\Domain\Models\PackageDefinitionItem;
use App\Modules\Sales\Domain\Pricing\SalePricing;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates, changes and archives the packages a center sells.
 *
 * Changing a definition — its price, its items — changes what is sold NEXT.
 * Every package already sold is a snapshot and is untouched
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §13).
 */
final class ManagePackageDefinition
{
    public const MAX_ITEMS = 20;

    public const MAX_VALIDITY_DAYS = 3_650;

    public function __construct(
        private readonly PackagesAccess $access,
        private readonly PackagesAudit $audit,
    ) {}

    /**
     * @param  array<string, string|null>  $name
     * @param  list<array{service: string, variation?: string|null, quantity: int}>  $items
     *
     * @throws PackagesFailed
     * @throws AuthorizationException
     */
    public function save(User $actingUser, array $name, int $priceMinor, int $validityDays, array $items, int $sortOrder = 0, ?PackageDefinition $definition = null): PackageDefinition
    {
        $this->access->ensure($actingUser, Permission::PackageManage, __('manager_benefits.errors.may_not_change_packages'));

        $cleanName = [];

        foreach ($name as $locale => $text) {
            $text = is_string($text) ? trim($text) : '';

            if ($text !== '') {
                if (mb_strlen($text) > 120) {
                    throw PackagesFailed::policy(__('manager_benefits.errors.package_name_long'));
                }

                $cleanName[(string) $locale] = $text;
            }
        }

        if ($cleanName === []) {
            throw PackagesFailed::policy(__('manager_benefits.errors.package_name_required'));
        }

        if ($priceMinor < 0 || $priceMinor > SalePricing::MAX_SUBTOTAL_MINOR) {
            throw PackagesFailed::policy(__('manager_benefits.errors.price_range'));
        }

        if ($validityDays < 1 || $validityDays > self::MAX_VALIDITY_DAYS) {
            throw PackagesFailed::policy(__('manager_benefits.errors.validity_range', ['max' => self::MAX_VALIDITY_DAYS]));
        }

        $resolved = $this->items($items);

        /** @var PackageDefinition $saved */
        $saved = DB::connection('tenant')->transaction(function () use ($actingUser, $cleanName, $priceMinor, $validityDays, $resolved, $sortOrder, $definition): PackageDefinition {
            $target = $definition ?? new PackageDefinition;
            $before = $definition === null ? null : ['price_minor' => $definition->price_minor, 'validity_days' => $definition->validity_days];

            $target->forceFill([
                'name' => TranslatedText::fromArray($cleanName),
                'price_minor' => $priceMinor,
                'validity_days' => $validityDays,
                'sort_order' => max(0, min(65535, $sortOrder)),
            ])->save();

            // Items describe what is sold next; sold packages keep their own copy.
            PackageDefinitionItem::query()->where('package_definition_id', $target->getKey())->delete();

            foreach ($resolved as $item) {
                PackageDefinitionItem::query()->create([
                    'package_definition_id' => $target->getKey(),
                    'service_id' => $item['service_id'],
                    'service_variation_id' => $item['service_variation_id'],
                    'quantity' => $item['quantity'],
                ]);
            }

            $this->audit->record($definition === null ? 'package.definition_created' : 'package.definition_updated',
                Actor::staff($actingUser), $target, $target->uuid,
                after: ['price_minor' => $priceMinor, 'validity_days' => $validityDays, 'items' => count($resolved)],
                before: $before,
                category: AuditCategory::Config,
            );

            return $target;
        });

        return $saved->load('items');
    }

    /**
     * @throws AuthorizationException
     */
    public function archive(User $actingUser, PackageDefinition $definition, ?CarbonImmutable $now = null): PackageDefinition
    {
        $this->access->ensure($actingUser, Permission::PackageManage, __('manager_benefits.errors.may_not_change_packages'));

        if ($definition->archived_at === null) {
            DB::connection('tenant')->transaction(function () use ($actingUser, $definition, $now): void {
                $definition->forceFill(['archived_at' => ($now ?? CarbonImmutable::now())->utc()])->save();

                $this->audit->record('package.definition_archived', Actor::staff($actingUser), $definition, $definition->uuid, category: AuditCategory::Config);
            });
        }

        return $definition;
    }

    /**
     * Puts an archived package back on sale. Packages already sold never
     * noticed it was gone — they are snapshots (§13).
     *
     * @throws AuthorizationException
     */
    public function restore(User $actingUser, PackageDefinition $definition): PackageDefinition
    {
        $this->access->ensure($actingUser, Permission::PackageManage, __('manager_benefits.errors.may_not_change_packages'));

        if ($definition->archived_at !== null) {
            DB::connection('tenant')->transaction(function () use ($actingUser, $definition): void {
                $definition->forceFill(['archived_at' => null])->save();

                $this->audit->record('package.definition_restored', Actor::staff($actingUser), $definition, $definition->uuid, category: AuditCategory::Config);
            });
        }

        return $definition;
    }

    /**
     * @param  list<array{service: string, variation?: string|null, quantity: int}>  $items
     * @return list<array{service_id: int, service_variation_id: int|null, quantity: int}>
     *
     * @throws PackagesFailed
     */
    private function items(array $items): array
    {
        if ($items === [] || count($items) > self::MAX_ITEMS) {
            throw PackagesFailed::policy(__('manager_benefits.errors.items_count', ['max' => self::MAX_ITEMS]));
        }

        $resolved = [];
        $seen = [];

        foreach ($items as $item) {
            /** @var Service|null $service */
            $service = Service::query()->active()->where('uuid', $item['service'])->first();

            if (! $service instanceof Service) {
                throw PackagesFailed::policy(__('manager_benefits.errors.item_service'));
            }

            $variationId = null;

            if (($item['variation'] ?? null) !== null && $item['variation'] !== '') {
                /** @var ServiceVariation|null $variation */
                $variation = ServiceVariation::query()->where('uuid', $item['variation'])->where('service_id', $service->getKey())->first();

                if (! $variation instanceof ServiceVariation) {
                    throw PackagesFailed::policy(__('manager_benefits.errors.item_variation'));
                }

                $variationId = (int) $variation->getKey();
            }

            if ($item['quantity'] < 1 || $item['quantity'] > 999) {
                throw PackagesFailed::policy(__('manager_benefits.errors.item_quantity'));
            }

            $key = $service->getKey().':'.($variationId ?? '*');

            if (isset($seen[$key])) {
                throw PackagesFailed::policy(__('manager_benefits.errors.item_duplicate'));
            }

            $seen[$key] = true;
            $resolved[] = ['service_id' => (int) $service->getKey(), 'service_variation_id' => $variationId, 'quantity' => $item['quantity']];
        }

        return $resolved;
    }
}
