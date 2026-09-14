<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Authorization\Permission;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Modules\Booking\Application\CalendarQuery;
use App\Modules\Booking\Domain\Availability\BlockFinder;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Employees\Application\Actions\SaveAvailabilityBlock;
use App\Modules\Employees\Domain\Models\EmployeeAvailabilityBlock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Breaks, training and personal time — the reasons somebody is not bookable.
 *
 * Creating a block does NOT cancel or move the appointments already inside it.
 * The response carries them so the person who just blocked Thursday afternoon
 * can see the three customers standing in it and decide what to do — which is
 * a decision the center makes, not the software
 * (docs/13-ROADMAP.md Phase 7 §14).
 */
final class AvailabilityBlockController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->authorize($request);

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $from = CarbonImmutable::parse($validated['from'] ?? CarbonImmutable::now()->format('Y-m-d'))->utc();
        $to = CarbonImmutable::parse($validated['to'] ?? $from->addDays(14)->format('Y-m-d'))->utc()->addDay();

        $query = EmployeeAvailabilityBlock::query()
            ->with(['employee'])
            ->overlapping($from, $to)
            ->orderBy('starts_at');

        $user->branchScope()->applyTo($query, 'branch_id');

        $blocks = $query->get()->map(static fn (EmployeeAvailabilityBlock $block): array => [
            'uuid' => $block->uuid,
            'employee' => $block->employee === null ? null : [
                'uuid' => $block->employee->uuid,
                'name' => $block->employee->name->get(),
            ],
            'starts_at' => $block->starts_at->toIso8601String(),
            'ends_at' => $block->ends_at->toIso8601String(),
            'type' => $block->type->value,
            // Staff-only, and this endpoint is staff-only.
            'internal_note' => $block->internal_note,
        ])->all();

        return ApiResponse::data(['blocks' => $blocks]);
    }

    public function store(
        Request $request,
        SaveAvailabilityBlock $save,
        BlockFinder $finder,
        CalendarQuery $calendar,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'employee' => ['required', 'string'],
            'branch' => ['required', 'string'],
            'starts_at' => ['required', 'string'],
            'ends_at' => ['required', 'string'],
            'type' => ['nullable', 'string', 'max:24'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $block = $save($validated, $user);

        return ApiResponse::data([
            'uuid' => $block->uuid,
            // Surfaced, never acted on (§14).
            'affected_appointments' => $this->affected($block, $finder, $calendar, $user),
        ], 201);
    }

    public function update(
        Request $request,
        string $uuid,
        SaveAvailabilityBlock $save,
        BlockFinder $finder,
        CalendarQuery $calendar,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'employee' => ['required', 'string'],
            'branch' => ['required', 'string'],
            'starts_at' => ['required', 'string'],
            'ends_at' => ['required', 'string'],
            'type' => ['nullable', 'string', 'max:24'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $block = $save($validated, $user, $this->block($uuid, $user));

        return ApiResponse::data([
            'uuid' => $block->uuid,
            'affected_appointments' => $this->affected($block, $finder, $calendar, $user),
        ]);
    }

    public function destroy(Request $request, string $uuid, SaveAvailabilityBlock $save): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $save->delete($this->block($uuid, $user), $user);

        return ApiResponse::data(['deleted' => true]);
    }

    /**
     * Future appointments standing inside this branch's blocks.
     *
     * @return list<array<string, mixed>>
     */
    private function affected(
        EmployeeAvailabilityBlock $block,
        BlockFinder $finder,
        CalendarQuery $calendar,
        User $user,
    ): array {
        unset($calendar);

        if (! $user->hasPermission(Permission::AppointmentView)) {
            return [];
        }

        $ids = $finder->appointmentsInsideBlocks((int) $block->branch_id, CarbonImmutable::now());

        if ($ids === []) {
            return [];
        }

        return Appointment::query()
            ->with('customer')
            ->whereIn('id', $ids)
            ->blocking()
            ->orderBy('starts_at')
            ->limit(50)
            ->get()
            ->map(static fn (Appointment $appointment): array => [
                'uuid' => $appointment->uuid,
                'starts_at' => $appointment->starts_at->toIso8601String(),
                'customer_name' => $appointment->customer?->name,
            ])
            ->all();
    }

    private function block(string $uuid, User $user): EmployeeAvailabilityBlock
    {
        $query = EmployeeAvailabilityBlock::query()->where('uuid', $uuid);

        $user->branchScope()->applyTo($query, 'branch_id');

        $block = $query->first();

        if (! $block instanceof EmployeeAvailabilityBlock) {
            throw new NotFoundHttpException;
        }

        return $block;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasPermission(Permission::AvailabilityBlockManage)) {
            throw new AuthorizationException('You may not manage availability blocks.');
        }

        return $user;
    }
}
