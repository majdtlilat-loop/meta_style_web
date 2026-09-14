<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Authorization\Permission;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Sales\Application\Actions\ManageCashierShift;
use App\Modules\Sales\Application\SalesAccess;
use App\Modules\Sales\Application\SalesPresenter;
use App\Modules\Sales\Application\SalesQuery;
use App\Modules\Sales\Domain\Models\CashierShift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Open, current, close. Not a drawer count and not a report (docs/18-SALES.md §45).
 */
final class CashierShiftController extends Controller
{
    public function open(Request $request, ManageCashierShift $shifts, SalesPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'branch' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:190'],
        ]);

        $shift = $shifts->open($validated['branch'], $user, $validated['note'] ?? null);

        return ApiResponse::data(['shift' => $presenter->shift($shift)], 201);
    }

    public function current(Request $request, ManageCashierShift $shifts, SalesAccess $access, SalesQuery $query, SalesPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate(['branch' => ['required', 'string']]);

        /** @var Branch|null $branch */
        $branch = Branch::query()->where('uuid', $validated['branch'])->first();

        if (! $branch instanceof Branch) {
            throw new NotFoundHttpException;
        }

        $access->ensure($user, Permission::CashierShiftManage, $branch->id, 'You may not use a cashier shift.');

        $shift = $shifts->currentFor($user, $branch->id);

        return ApiResponse::data([
            'shift' => $shift instanceof CashierShift ? $presenter->shift($shift, $query->shiftSummary($shift)) : null,
        ]);
    }

    public function close(Request $request, string $uuid, ManageCashierShift $shifts, SalesQuery $query, SalesPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate(['note' => ['nullable', 'string', 'max:190']]);

        /** @var CashierShift|null $shift */
        $shift = CashierShift::query()->where('uuid', $uuid)->first();

        if (! $shift instanceof CashierShift || ! $user->canAccessBranch($shift->branch_id)) {
            throw new NotFoundHttpException;
        }

        $closed = $shifts->close($shift, $user, $validated['note'] ?? null);

        return ApiResponse::data(['shift' => $presenter->shift($closed, $query->shiftSummary($closed))]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
