<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Memberships\Application\MembershipsAccess;
use App\Modules\Memberships\Application\MembershipsAudit;
use App\Modules\Memberships\Domain\Enums\DiscountKind;
use App\Modules\Memberships\Domain\Exceptions\MembershipsFailed;
use App\Modules\Memberships\Domain\Models\MembershipPlan;
use App\Modules\Memberships\Domain\Models\MembershipPlanBenefit;
use App\Modules\Sales\Domain\Pricing\SalePricing;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates, changes and archives the membership plans a center sells.
 *
 * Changing a plan — its price, its term, its benefits — changes what is sold
 * NEXT. Every membership already sold is a snapshot and is untouched
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §10).
 *
 * A benefit is a discount on one service, or on every service, optionally
 * limited per term. Deliberately not a promotion engine: no weekdays, codes,
 * carts or campaigns (§12).
 */
final class ManageMembershipPlan
{
    public const MAX_BENEFITS = 10;

    public const MAX_DURATION_DAYS = 3_650;

    public const MAX_USES_PER_TERM = 999;

    public function __construct(
        private readonly MembershipsAccess $access,
        private readonly MembershipsAudit $audit,
    ) {}

    /**
     * @param  array<string, string|null>  $name
     * @param  list<array{service?: string|null, discount_type: string, basis_points?: int|null, amount_minor?: int|null, uses_per_term?: int|null}>  $benefits
     *
     * @throws MembershipsFailed
     * @throws AuthorizationException
     */
    public function save(User $actingUser, array $name, int $priceMinor, int $durationDays, array $benefits, int $sortOrder = 0, ?MembershipPlan $plan = null): MembershipPlan
    {
        $this->access->ensure($actingUser, Permission::MembershipManage, __('manager_benefits.errors.may_not_change_plans'));

        $cleanName = [];

        foreach ($name as $locale => $text) {
            $text = is_string($text) ? trim($text) : '';

            if ($text !== '') {
                if (mb_strlen($text) > 120) {
                    throw MembershipsFailed::policy(__('manager_benefits.errors.plan_name_long'));
                }

                $cleanName[(string) $locale] = $text;
            }
        }

        if ($cleanName === []) {
            throw MembershipsFailed::policy(__('manager_benefits.errors.plan_name_required'));
        }

        if ($priceMinor < 0 || $priceMinor > SalePricing::MAX_SUBTOTAL_MINOR) {
            throw MembershipsFailed::policy(__('manager_benefits.errors.price_range'));
        }

        if ($durationDays < 1 || $durationDays > self::MAX_DURATION_DAYS) {
            throw MembershipsFailed::policy(__('manager_benefits.errors.duration_range', ['max' => self::MAX_DURATION_DAYS]));
        }

        $resolved = $this->benefits($benefits);

        /** @var MembershipPlan $saved */
        $saved = DB::connection('tenant')->transaction(function () use ($actingUser, $cleanName, $priceMinor, $durationDays, $resolved, $sortOrder, $plan): MembershipPlan {
            $target = $plan ?? new MembershipPlan;
            $before = $plan === null ? null : ['price_minor' => $plan->price_minor, 'duration_days' => $plan->duration_days];

            $target->forceFill([
                'name' => TranslatedText::fromArray($cleanName),
                'price_minor' => $priceMinor,
                'duration_days' => $durationDays,
                'sort_order' => max(0, min(65535, $sortOrder)),
            ])->save();

            // Benefits describe what is sold next; sold memberships keep their own copy.
            MembershipPlanBenefit::query()->where('membership_plan_id', $target->getKey())->delete();

            foreach ($resolved as $benefit) {
                MembershipPlanBenefit::query()->create(['membership_plan_id' => $target->getKey(), ...$benefit]);
            }

            $this->audit->record($plan === null ? 'membership.plan_created' : 'membership.plan_updated',
                Actor::staff($actingUser), $target, $target->uuid,
                after: ['price_minor' => $priceMinor, 'duration_days' => $durationDays, 'benefits' => count($resolved)],
                before: $before,
                category: AuditCategory::Config,
            );

            return $target;
        });

        return $saved->load('benefits.service');
    }

    /**
     * @throws AuthorizationException
     */
    public function archive(User $actingUser, MembershipPlan $plan, ?CarbonImmutable $now = null): MembershipPlan
    {
        $this->access->ensure($actingUser, Permission::MembershipManage, __('manager_benefits.errors.may_not_change_plans'));

        if ($plan->archived_at === null) {
            DB::connection('tenant')->transaction(function () use ($actingUser, $plan, $now): void {
                $plan->forceFill(['archived_at' => ($now ?? CarbonImmutable::now())->utc()])->save();

                $this->audit->record('membership.plan_archived', Actor::staff($actingUser), $plan, $plan->uuid, category: AuditCategory::Config);
            });
        }

        return $plan;
    }

    /**
     * Puts an archived plan back on sale. Memberships already sold never
     * noticed it was gone — they are snapshots (§10).
     *
     * @throws AuthorizationException
     */
    public function restore(User $actingUser, MembershipPlan $plan): MembershipPlan
    {
        $this->access->ensure($actingUser, Permission::MembershipManage, __('manager_benefits.errors.may_not_change_plans'));

        if ($plan->archived_at !== null) {
            DB::connection('tenant')->transaction(function () use ($actingUser, $plan): void {
                $plan->forceFill(['archived_at' => null])->save();

                $this->audit->record('membership.plan_restored', Actor::staff($actingUser), $plan, $plan->uuid, category: AuditCategory::Config);
            });
        }

        return $plan;
    }

    /**
     * @param  list<array{service?: string|null, discount_type: string, basis_points?: int|null, amount_minor?: int|null, uses_per_term?: int|null}>  $benefits
     * @return list<array{service_id: int|null, discount_type: DiscountKind, basis_points: int|null, amount_minor: int|null, uses_per_term: int|null}>
     *
     * @throws MembershipsFailed
     */
    private function benefits(array $benefits): array
    {
        if ($benefits === [] || count($benefits) > self::MAX_BENEFITS) {
            throw MembershipsFailed::policy(__('manager_benefits.errors.benefits_count', ['max' => self::MAX_BENEFITS]));
        }

        $resolved = [];
        $seen = [];

        foreach ($benefits as $benefit) {
            $serviceId = null;

            if (($benefit['service'] ?? null) !== null && $benefit['service'] !== '') {
                /** @var Service|null $service */
                $service = Service::query()->active()->where('uuid', $benefit['service'])->first();

                if (! $service instanceof Service) {
                    throw MembershipsFailed::policy(__('manager_benefits.errors.benefit_service'));
                }

                $serviceId = (int) $service->getKey();
            }

            $kind = DiscountKind::tryFrom($benefit['discount_type']);

            if ($kind === null) {
                throw MembershipsFailed::policy(__('manager_benefits.errors.benefit_kind'));
            }

            $basisPoints = $kind === DiscountKind::Percent ? (int) ($benefit['basis_points'] ?? 0) : null;
            $amount = $kind === DiscountKind::Fixed ? (int) ($benefit['amount_minor'] ?? 0) : null;

            if ($basisPoints !== null && ($basisPoints < 1 || $basisPoints > SalePricing::MAX_BASIS_POINTS)) {
                throw MembershipsFailed::policy(__('manager_benefits.errors.benefit_percent'));
            }

            if ($amount !== null && ($amount < 1 || $amount > SalePricing::MAX_SUBTOTAL_MINOR)) {
                throw MembershipsFailed::policy(__('manager_benefits.errors.benefit_amount'));
            }

            $uses = $benefit['uses_per_term'] ?? null;

            if ($uses !== null && ($uses < 1 || $uses > self::MAX_USES_PER_TERM)) {
                throw MembershipsFailed::policy(__('manager_benefits.errors.benefit_uses', ['max' => self::MAX_USES_PER_TERM]));
            }

            $key = $serviceId ?? '*';

            if (isset($seen[$key])) {
                throw MembershipsFailed::policy(__('manager_benefits.errors.benefit_duplicate'));
            }

            $seen[$key] = true;
            $resolved[] = [
                'service_id' => $serviceId,
                'discount_type' => $kind,
                'basis_points' => $basisPoints,
                'amount_minor' => $amount,
                'uses_per_term' => $uses,
            ];
        }

        return $resolved;
    }
}
