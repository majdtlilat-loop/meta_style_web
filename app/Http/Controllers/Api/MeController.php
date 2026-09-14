<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Contracts\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who am I, what may I do, and what does this center own.
 *
 * The permission and entitlement lists here are for PRESENTATION only — so a
 * client can hide what it cannot use. They are never the enforcement: every
 * gated endpoint checks server-side regardless of what the client renders
 * (docs/05-ENTITLEMENTS.md §6.5).
 */
final class MeController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenants, Entitlements $entitlements): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $tenant = $tenants->require();
        $scope = $user->branchScope();

        return ApiResponse::data([
            'user' => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'is_owner' => $user->is_owner,
                'roles' => $user->roles()->pluck('key')->all(),
            ],
            'center' => [
                // The public key, never the internal id or sequence.
                'key' => $tenant->id,
                'name' => $tenant->name,
            ],
            'permissions' => $user->permissions(),
            'branch_scope' => [
                'all_branches' => $scope->isUnrestricted(),
                'branch_ids' => $scope->branchIds,
            ],
            'entitlements' => $entitlements->all(),
            'access_level' => $entitlements->accessLevel()->value,
        ]);
    }
}
