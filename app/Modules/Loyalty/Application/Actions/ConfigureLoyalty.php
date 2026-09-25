<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Loyalty\Application\EarningRules;
use App\Modules\Loyalty\Application\LoyaltyAccess;
use App\Modules\Loyalty\Application\LoyaltyAudit;
use App\Modules\Loyalty\Domain\Data\EarningMoment;
use App\Modules\Loyalty\Domain\Exceptions\LoyaltyFailed;
use App\Modules\Loyalty\Domain\Models\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Models\LoyaltyRuleVersion;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Sets the center's loyalty rules.
 *
 * A change applies to what happens NEXT: points already earned, redeemed or
 * reversed are history and are not recomputed. Bounded so every product of the
 * earning and redemption arithmetic fits a 64-bit integer
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §6).
 */
final class ConfigureLoyalty
{
    public const MAX_POINTS_PER_UNIT = 10_000;

    public const MAX_POINT_VALUE_MINOR = 1_000_000;

    public const MAX_EXPIRY_DAYS = 3_650;

    public function __construct(
        private readonly LoyaltyAccess $access,
        private readonly EarningRules $rules,
        private readonly LoyaltyAudit $audit,
    ) {}

    /**
     * @param  array{spend_points: int, spend_unit_minor: int, min_spend_minor: int, visit_points: int, point_value_minor: int, min_redeem_points: int, expiry_days: int|null}  $rules
     *
     * @throws LoyaltyFailed
     * @throws AuthorizationException
     */
    public function __invoke(User $actingUser, array $rules): LoyaltyProgram
    {
        $this->access->ensure($actingUser, Permission::LoyaltyManage, __('manager_benefits.errors.may_not_change_program'));

        $this->validate($rules);

        /** @var LoyaltyProgram $program */
        $program = DB::connection('tenant')->transaction(function () use ($actingUser, $rules): LoyaltyProgram {
            $program = LoyaltyProgram::query()->where('singleton', 1)->lockForUpdate()->first() ?? new LoyaltyProgram(['singleton' => 1]);
            $before = $program->exists ? $this->snapshot($program) : null;
            $now = CarbonImmutable::now()->utc();

            $program->forceFill([
                'spend_points' => $rules['spend_points'],
                'spend_unit_minor' => $rules['spend_unit_minor'],
                'min_spend_minor' => $rules['min_spend_minor'],
                'visit_points' => $rules['visit_points'],
                'point_value_minor' => $rules['point_value_minor'],
                'min_redeem_points' => $rules['min_redeem_points'],
                'expiry_days' => $rules['expiry_days'],
                'updated_by_id' => $actingUser->uuid,
                'updated_by_label' => $actingUser->name,
            ])->save();

            /*
             * The new rules become a VERSION, effective from now. Earning reads
             * versions, never this row: an event that has not been written yet
             * — an after-commit earning that failed — must still earn under the
             * rule that was in force when it happened
             * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §6).
             */
            $version = LoyaltyRuleVersion::query()->create([
                'effective_from' => $now,
                'spend_points' => $rules['spend_points'],
                'spend_unit_minor' => $rules['spend_unit_minor'],
                'min_spend_minor' => $rules['min_spend_minor'],
                'visit_points' => $rules['visit_points'],
                'expiry_days' => $rules['expiry_days'],
                'changed_by_id' => $actingUser->uuid,
                'changed_by_label' => $actingUser->name,
                'created_at' => $now,
            ]);

            // A point on the timeline: the center owned `loyalty` now (this
            // Action required it) and this version was effective.
            $this->rules->record(new EarningMoment($now, true, (int) $version->getKey()));

            $this->audit->record('loyalty.program_updated', Actor::staff($actingUser), $program, 'loyalty-program',
                after: $this->snapshot($program),
                before: $before,
                severity: AuditSeverity::Notice,
                category: AuditCategory::Config,
            );

            return $program;
        });

        return $program;
    }

    /**
     * @param  array{spend_points: int, spend_unit_minor: int, min_spend_minor: int, visit_points: int, point_value_minor: int, min_redeem_points: int, expiry_days: int|null}  $rules
     *
     * @throws LoyaltyFailed
     */
    private function validate(array $rules): void
    {
        if ($rules['spend_points'] < 0 || $rules['spend_points'] > self::MAX_POINTS_PER_UNIT
            || $rules['visit_points'] < 0 || $rules['visit_points'] > self::MAX_POINTS_PER_UNIT) {
            throw LoyaltyFailed::policy(__('manager_benefits.errors.points_per_rule', ['max' => self::MAX_POINTS_PER_UNIT]));
        }

        if ($rules['spend_points'] > 0 && $rules['spend_unit_minor'] < 1) {
            throw LoyaltyFailed::policy(__('manager_benefits.errors.spend_unit_required'));
        }

        if ($rules['spend_unit_minor'] < 0 || $rules['spend_unit_minor'] > 3_000_000_000
            || $rules['min_spend_minor'] < 0 || $rules['min_spend_minor'] > 3_000_000_000) {
            throw LoyaltyFailed::policy(__('manager_benefits.errors.spend_limit'));
        }

        if ($rules['point_value_minor'] < 0 || $rules['point_value_minor'] > self::MAX_POINT_VALUE_MINOR) {
            throw LoyaltyFailed::policy(__('manager_benefits.errors.point_value_range', ['max' => self::MAX_POINT_VALUE_MINOR]));
        }

        if ($rules['min_redeem_points'] < 0 || $rules['min_redeem_points'] > 1_000_000) {
            throw LoyaltyFailed::policy(__('manager_benefits.errors.min_redeem_range'));
        }

        if ($rules['expiry_days'] !== null && ($rules['expiry_days'] < 1 || $rules['expiry_days'] > self::MAX_EXPIRY_DAYS)) {
            throw LoyaltyFailed::policy(__('manager_benefits.errors.expiry_range', ['max' => self::MAX_EXPIRY_DAYS]));
        }
    }

    /**
     * @return array<string, int|null>
     */
    private function snapshot(LoyaltyProgram $program): array
    {
        return [
            'spend_points' => $program->spend_points,
            'spend_unit_minor' => $program->spend_unit_minor,
            'min_spend_minor' => $program->min_spend_minor,
            'visit_points' => $program->visit_points,
            'point_value_minor' => $program->point_value_minor,
            'min_redeem_points' => $program->min_redeem_points,
            'expiry_days' => $program->expiry_days,
        ];
    }
}
