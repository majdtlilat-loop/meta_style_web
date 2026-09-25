<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Modules\Loyalty\Application\Actions\AdjustPoints;
use App\Modules\Loyalty\Application\Actions\ConfigureLoyalty;
use App\Modules\Loyalty\Application\Actions\ManageLoyaltyTier;
use App\Modules\Loyalty\Application\Actions\RedeemPoints;
use App\Modules\Loyalty\Application\LoyaltyPresenter;
use App\Modules\Loyalty\Application\LoyaltyQuery;
use App\Modules\Loyalty\Domain\Enums\PointsDirection;
use App\Modules\Loyalty\Domain\Models\LoyaltyTier;
use App\Modules\Sales\Application\SalesPresenter;
use App\Modules\Sales\Application\SalesQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Loyalty for staff: the center's rules and tiers, a customer's points, a hand
 * adjustment, and redeeming points on a draft sale
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §27).
 *
 * Validate → one Action or query → present. The Actions check the entitlement
 * and the permission; reads never write.
 */
final class LoyaltyController extends Controller
{
    public function program(Request $request, LoyaltyQuery $query, LoyaltyPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        return ApiResponse::data([
            'program' => $presenter->program($query->program($user)),
            'tiers' => array_map(fn (LoyaltyTier $tier): array => $presenter->tier($tier), $query->tiers($user, $request->boolean('archived'))),
        ]);
    }

    public function configure(Request $request, ConfigureLoyalty $configure, LoyaltyPresenter $presenter): JsonResponse
    {
        $validated = $request->validate([
            'spend_points' => ['required', 'integer', 'min:0'],
            'spend_unit_minor' => ['required', 'integer', 'min:0'],
            'min_spend_minor' => ['required', 'integer', 'min:0'],
            'visit_points' => ['required', 'integer', 'min:0'],
            'point_value_minor' => ['required', 'integer', 'min:0'],
            'min_redeem_points' => ['required', 'integer', 'min:0'],
            'expiry_days' => ['nullable', 'integer', 'min:1'],
        ]);

        $program = $configure($this->user($request), [
            'spend_points' => (int) $validated['spend_points'],
            'spend_unit_minor' => (int) $validated['spend_unit_minor'],
            'min_spend_minor' => (int) $validated['min_spend_minor'],
            'visit_points' => (int) $validated['visit_points'],
            'point_value_minor' => (int) $validated['point_value_minor'],
            'min_redeem_points' => (int) $validated['min_redeem_points'],
            'expiry_days' => isset($validated['expiry_days']) ? (int) $validated['expiry_days'] : null,
        ]);

        return ApiResponse::data(['program' => $presenter->program($program)]);
    }

    public function storeTier(Request $request, ManageLoyaltyTier $manage, LoyaltyPresenter $presenter): JsonResponse
    {
        $validated = $this->tierInput($request);

        $tier = $manage->save($this->user($request), $validated['name'], $validated['threshold_points'], $validated['benefit_note'], $validated['sort_order']);

        return ApiResponse::data(['tier' => $presenter->tier($tier)], 201);
    }

    public function updateTier(Request $request, string $uuid, LoyaltyQuery $query, ManageLoyaltyTier $manage, LoyaltyPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);
        $validated = $this->tierInput($request);

        $tier = $manage->save($user, $validated['name'], $validated['threshold_points'], $validated['benefit_note'], $validated['sort_order'], $query->tier($uuid, $user));

        return ApiResponse::data(['tier' => $presenter->tier($tier)]);
    }

    public function archiveTier(Request $request, string $uuid, LoyaltyQuery $query, ManageLoyaltyTier $manage, LoyaltyPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        return ApiResponse::data(['tier' => $presenter->tier($manage->archive($user, $query->tier($uuid, $user)))]);
    }

    public function customer(Request $request, string $uuid, LoyaltyQuery $query, LoyaltyPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);
        $found = $query->forCustomer($uuid, $user);

        return ApiResponse::data(['loyalty' => $presenter->forStaff($found['account'], $query->program($user))]);
    }

    public function adjust(Request $request, string $uuid, AdjustPoints $adjust, LoyaltyQuery $query, LoyaltyPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'direction' => ['required', 'string', 'in:in,out'],
            'points' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:190'],
        ]);

        $adjust($uuid, $user, PointsDirection::from($validated['direction']), (int) $validated['points'], (string) $validated['reason']);

        return ApiResponse::data(['loyalty' => $presenter->forStaff($query->forCustomer($uuid, $user)['account'], $query->program($user))], 201);
    }

    public function redeem(Request $request, string $uuid, RedeemPoints $redeem, SalesQuery $sales, SalesPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate(['points' => ['required', 'integer', 'min:1']]);

        $redeem->apply($sales->find($uuid, $user), $user, (int) $validated['points']);

        return ApiResponse::data(['sale' => $presenter->sale($sales->find($uuid, $user), $user)], 201);
    }

    public function withdraw(Request $request, string $uuid, RedeemPoints $redeem, SalesQuery $sales, SalesPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $redeem->withdraw($sales->find($uuid, $user), $user);

        return ApiResponse::data(['sale' => $presenter->sale($sales->find($uuid, $user), $user)]);
    }

    /**
     * @return array{name: array<string, string|null>, threshold_points: int, benefit_note: array<string, string|null>, sort_order: int}
     */
    private function tierInput(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'array'],
            'name.*' => ['nullable', 'string', 'max:80'],
            'threshold_points' => ['required', 'integer', 'min:0'],
            'benefit_note' => ['nullable', 'array'],
            'benefit_note.*' => ['nullable', 'string', 'max:190'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        /** @var array<string, string|null> $name */
        $name = $validated['name'];
        /** @var array<string, string|null> $note */
        $note = $validated['benefit_note'] ?? [];

        return [
            'name' => $name,
            'threshold_points' => (int) $validated['threshold_points'],
            'benefit_note' => $note,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
