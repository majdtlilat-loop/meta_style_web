<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Modules\Memberships\Application\Actions\ApplyMembershipBenefit;
use App\Modules\Memberships\Application\Actions\CancelCustomerMembership;
use App\Modules\Memberships\Application\Actions\ManageMembershipPlan;
use App\Modules\Memberships\Application\MembershipsPresenter;
use App\Modules\Memberships\Application\MembershipsQuery;
use App\Modules\Memberships\Domain\Models\MembershipPlan;
use App\Modules\Sales\Application\SalesPresenter;
use App\Modules\Sales\Application\SalesQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Memberships for staff: plans, a customer's memberships, cancelling one, and a
 * member's discount on a till line (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §27).
 *
 * A membership is SOLD through the till like any line — `POST
 * sales/{uuid}/items` with `kind=offering, offering_type=membership` — never
 * through a checkout of its own.
 */
final class MembershipController extends Controller
{
    public function plans(Request $request, MembershipsQuery $query, MembershipsPresenter $presenter): JsonResponse
    {
        $plans = $query->plans($this->user($request), $request->boolean('archived'));

        return ApiResponse::data(['plans' => array_map(fn (MembershipPlan $plan): array => $presenter->plan($plan), $plans)]);
    }

    public function store(Request $request, ManageMembershipPlan $manage, MembershipsPresenter $presenter): JsonResponse
    {
        $input = $this->planInput($request);

        $plan = $manage->save($this->user($request), $input['name'], $input['price_minor'], $input['duration_days'], $input['benefits'], $input['sort_order']);

        return ApiResponse::data(['plan' => $presenter->plan($plan)], 201);
    }

    public function update(Request $request, string $uuid, MembershipsQuery $query, ManageMembershipPlan $manage, MembershipsPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);
        $input = $this->planInput($request);

        $plan = $manage->save($user, $input['name'], $input['price_minor'], $input['duration_days'], $input['benefits'], $input['sort_order'], $query->plan($uuid, $user));

        return ApiResponse::data(['plan' => $presenter->plan($plan)]);
    }

    public function archive(Request $request, string $uuid, MembershipsQuery $query, ManageMembershipPlan $manage, MembershipsPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        return ApiResponse::data(['plan' => $presenter->plan($manage->archive($user, $query->plan($uuid, $user)))]);
    }

    public function customer(Request $request, string $uuid, MembershipsQuery $query, MembershipsPresenter $presenter): JsonResponse
    {
        return ApiResponse::data(['memberships' => $presenter->forStaff($query->forCustomer($uuid, $this->user($request))['memberships'])]);
    }

    public function cancel(Request $request, string $uuid, CancelCustomerMembership $cancel, MembershipsPresenter $presenter): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:190']]);

        $membership = $cancel($uuid, $this->user($request), (string) $validated['reason']);

        return ApiResponse::data(['membership' => $presenter->forStaff([$membership->load('benefits')])[0]]);
    }

    public function apply(Request $request, string $uuid, string $lineUuid, ApplyMembershipBenefit $apply, SalesQuery $sales, SalesPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate(['benefit' => ['required', 'uuid']]);

        $apply->apply($sales->find($uuid, $user), $user, $lineUuid, (string) $validated['benefit']);

        return ApiResponse::data(['sale' => $presenter->sale($sales->find($uuid, $user), $user)], 201);
    }

    public function withdraw(Request $request, string $uuid, string $lineUuid, ApplyMembershipBenefit $apply, SalesQuery $sales, SalesPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $apply->withdraw($sales->find($uuid, $user), $user, $lineUuid);

        return ApiResponse::data(['sale' => $presenter->sale($sales->find($uuid, $user), $user)]);
    }

    /**
     * @return array{name: array<string, string|null>, price_minor: int, duration_days: int, sort_order: int, benefits: list<array{service?: string|null, discount_type: string, basis_points?: int|null, amount_minor?: int|null, uses_per_term?: int|null}>}
     */
    private function planInput(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'array'],
            'name.*' => ['nullable', 'string', 'max:120'],
            'price_minor' => ['required', 'integer', 'min:0'],
            'duration_days' => ['required', 'integer', 'min:1'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'benefits' => ['required', 'array', 'min:1', 'max:'.ManageMembershipPlan::MAX_BENEFITS],
            'benefits.*.service' => ['nullable', 'uuid'],
            'benefits.*.discount_type' => ['required', 'string', 'in:percent,fixed'],
            // Basis points for a percentage (1250 is 12.5%), minor units for a fixed amount.
            'benefits.*.basis_points' => ['nullable', 'integer', 'min:1'],
            'benefits.*.amount_minor' => ['nullable', 'integer', 'min:1'],
            'benefits.*.uses_per_term' => ['nullable', 'integer', 'min:1'],
        ]);

        /** @var array<string, string|null> $name */
        $name = $validated['name'];

        $benefits = [];

        /** @var array<string, mixed> $benefit */
        foreach ($validated['benefits'] as $benefit) {
            $benefits[] = [
                'service' => isset($benefit['service']) ? (string) $benefit['service'] : null,
                'discount_type' => (string) $benefit['discount_type'],
                'basis_points' => isset($benefit['basis_points']) ? (int) $benefit['basis_points'] : null,
                'amount_minor' => isset($benefit['amount_minor']) ? (int) $benefit['amount_minor'] : null,
                'uses_per_term' => isset($benefit['uses_per_term']) ? (int) $benefit['uses_per_term'] : null,
            ];
        }

        return [
            'name' => $name,
            'price_minor' => (int) $validated['price_minor'],
            'duration_days' => (int) $validated['duration_days'],
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'benefits' => $benefits,
        ];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
