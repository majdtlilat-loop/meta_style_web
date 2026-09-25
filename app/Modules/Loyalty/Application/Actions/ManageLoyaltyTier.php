<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Loyalty\Application\LoyaltyAccess;
use App\Modules\Loyalty\Application\LoyaltyAudit;
use App\Modules\Loyalty\Domain\Exceptions\LoyaltyFailed;
use App\Modules\Loyalty\Domain\Models\LoyaltyTier;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates, changes and archives loyalty tiers. A tier is a name, a threshold on
 * lifetime points, and a note of what it means to the center. Customers reach
 * it by earning; nobody is placed in one (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §9).
 */
final class ManageLoyaltyTier
{
    public function __construct(
        private readonly LoyaltyAccess $access,
        private readonly LoyaltyAudit $audit,
    ) {}

    /**
     * @param  array<string, string|null>  $name
     * @param  array<string, string|null>  $note
     *
     * @throws LoyaltyFailed
     * @throws AuthorizationException
     */
    public function save(User $actingUser, array $name, int $thresholdPoints, array $note = [], int $sortOrder = 0, ?LoyaltyTier $tier = null): LoyaltyTier
    {
        $this->access->ensure($actingUser, Permission::LoyaltyManage, __('manager_benefits.errors.may_not_change_tiers'));

        $cleanName = self::text($name, 80);

        if ($cleanName === []) {
            throw LoyaltyFailed::policy(__('manager_benefits.errors.tier_name_required'));
        }

        if ($thresholdPoints < 0 || $thresholdPoints > 1_000_000_000) {
            throw LoyaltyFailed::policy(__('manager_benefits.errors.tier_threshold_range'));
        }

        $cleanNote = self::text($note, 190);

        /** @var LoyaltyTier $saved */
        $saved = DB::connection('tenant')->transaction(function () use ($actingUser, $cleanName, $cleanNote, $thresholdPoints, $sortOrder, $tier): LoyaltyTier {
            $target = $tier ?? new LoyaltyTier;
            $before = $tier === null ? null : ['threshold_points' => $tier->threshold_points, 'name' => $tier->name->all()];

            $target->forceFill([
                'name' => TranslatedText::fromArray($cleanName),
                'benefit_note' => $cleanNote === [] ? null : TranslatedText::fromArray($cleanNote),
                'threshold_points' => $thresholdPoints,
                'sort_order' => max(0, min(65535, $sortOrder)),
            ])->save();

            $this->audit->record($tier === null ? 'loyalty.tier_created' : 'loyalty.tier_updated', Actor::staff($actingUser), $target, $target->uuid,
                after: ['threshold_points' => $thresholdPoints, 'name' => $cleanName],
                before: $before,
                category: AuditCategory::Config,
            );

            return $target;
        });

        return $saved;
    }

    /**
     * @throws AuthorizationException
     */
    public function archive(User $actingUser, LoyaltyTier $tier, ?CarbonImmutable $now = null): LoyaltyTier
    {
        $this->access->ensure($actingUser, Permission::LoyaltyManage, __('manager_benefits.errors.may_not_change_tiers'));

        if ($tier->archived_at !== null) {
            return $tier;
        }

        DB::connection('tenant')->transaction(function () use ($actingUser, $tier, $now): void {
            $tier->forceFill(['archived_at' => ($now ?? CarbonImmutable::now())->utc()])->save();

            $this->audit->record('loyalty.tier_archived', Actor::staff($actingUser), $tier, $tier->uuid, category: AuditCategory::Config);
        });

        return $tier;
    }

    /**
     * Brings an archived tier back. Customers reach it again by their lifetime
     * points, as they always did — nobody is placed in a tier (§9).
     *
     * @throws AuthorizationException
     */
    public function restore(User $actingUser, LoyaltyTier $tier): LoyaltyTier
    {
        $this->access->ensure($actingUser, Permission::LoyaltyManage, __('manager_benefits.errors.may_not_change_tiers'));

        if ($tier->archived_at === null) {
            return $tier;
        }

        DB::connection('tenant')->transaction(function () use ($actingUser, $tier): void {
            $tier->forceFill(['archived_at' => null])->save();

            $this->audit->record('loyalty.tier_restored', Actor::staff($actingUser), $tier, $tier->uuid, category: AuditCategory::Config);
        });

        return $tier;
    }

    /**
     * @param  array<string, string|null>  $values
     * @return array<string, string>
     *
     * @throws LoyaltyFailed
     */
    private static function text(array $values, int $max): array
    {
        $clean = [];

        foreach ($values as $locale => $text) {
            $text = is_string($text) ? trim($text) : '';

            if ($text === '') {
                continue;
            }

            if (mb_strlen($text) > $max) {
                throw LoyaltyFailed::policy(__('manager_benefits.errors.text_too_long', ['max' => $max]));
            }

            $clean[(string) $locale] = $text;
        }

        return $clean;
    }
}
