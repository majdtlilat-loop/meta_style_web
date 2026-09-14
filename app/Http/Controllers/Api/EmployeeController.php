<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Authorization\Permission;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Modules\Employees\Application\Actions\CreateEmployee;
use App\Modules\Employees\Application\Actions\SetEmployeeStatus;
use App\Modules\Employees\Domain\Data\NewEmployee;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use App\Modules\Employees\Domain\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff management.
 *
 * Thin by design: validate, call one Action, return a Resource. The
 * authorization and the business rules live in the Actions, because a WhatsApp
 * webhook or a console command must reach the same checks without passing
 * through a controller (docs/01-ARCHITECTURE.md §4).
 */
final class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasPermission(Permission::StaffView)) {
            throw new AuthorizationException('You may not view staff.');
        }

        $scope = $user->branchScope();

        $query = Employee::query()->with('branches');

        // Branch scope applied in the QUERY, not filtered afterwards: a manager
        // scoped to one branch must not receive another branch's staff at all,
        // not even to have them hidden client-side.
        if (! $scope->isUnrestricted()) {
            $ids = $scope->branchIds ?? [];

            $query->whereHas('branches', fn (Builder $q) => $q->whereIn('branches.id', $ids));
        }

        $payload = [];

        foreach ($query->orderBy('id')->get() as $employee) {
            $payload[] = $this->present($employee);
        }

        return ApiResponse::data($payload);
    }

    public function store(Request $request, CreateEmployee $createEmployee): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'array'],
            'name.*' => ['required', 'string', 'max:190'],
            'branch_ids' => ['array'],
            'branch_ids.*' => ['integer'],
            'role_ids' => ['array'],
            'role_ids.*' => ['integer'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'regex:/^\+[1-9][0-9]{7,17}$/'],
        ]);

        /** @var User $actingUser */
        $actingUser = $request->user();

        /** @var array<string, string> $name */
        $name = $validated['name'];
        /** @var list<int> $branchIds */
        $branchIds = array_map('intval', $validated['branch_ids'] ?? []);
        /** @var list<int> $roleIds */
        $roleIds = array_map('intval', $validated['role_ids'] ?? []);

        $result = $createEmployee(
            new NewEmployee(
                name: $name,
                branchIds: $branchIds,
                roleIds: $roleIds,
                email: $validated['email'] ?? null,
                phone: $validated['phone'] ?? null,
            ),
            $actingUser,
        );

        return ApiResponse::data([
            'uuid' => $result['employee']->uuid,
            'name' => (string) $result['employee']->name,
            'has_login' => $result['user'] instanceof User,
            // Returned exactly once, for the manager to hand over. Never stored
            // in plaintext, and Meta Style sends it nowhere in Phase 3.
            'activation_token' => $result['activation_token'],
        ], 201);
    }

    public function updateStatus(Request $request, string $uuid, SetEmployeeStatus $setStatus): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:active,inactive'],
        ]);

        /** @var User $actingUser */
        $actingUser = $request->user();

        $employee = Employee::query()->with('user', 'branches')->where('uuid', $uuid)->firstOrFail();

        $setStatus($employee, EmployeeStatus::from((string) $validated['status']), $actingUser);

        return ApiResponse::data([
            'uuid' => $employee->uuid,
            'status' => $employee->status->value,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Employee $employee): array
    {
        $branches = [];

        foreach ($employee->branches as $branch) {
            $branches[] = $branch->uuid;
        }

        return [
            'uuid' => $employee->uuid,
            'name' => (string) $employee->name,
            'name_translations' => $employee->name->all(),
            'status' => $employee->status->value,
            'has_login' => $employee->hasLogin(),
            'branches' => $branches,
        ];
    }
}
