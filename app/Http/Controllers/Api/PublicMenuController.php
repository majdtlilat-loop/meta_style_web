<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Http\ApiErrorCode;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\Menu\Application\PublicMenuQuery;
use App\Modules\Menu\Application\PublicMenuResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The guest-facing electronic menu.
 *
 * No authentication, by design: a customer scans a QR code on a table. The
 * center is resolved by `ResolvePublicTenant` from the public key in the path
 * (or from the center's own host, if it has one), and this route group never
 * carries an auth middleware — a test enforces that (ADR-036).
 *
 * The response is built by {@see PublicMenuResource}, which allow-lists every
 * field it emits. Nothing internal is loaded, so nothing internal can leak.
 */
final class PublicMenuController extends Controller
{
    public function __invoke(
        Request $request,
        PublicMenuQuery $query,
        PublicMenuResource $resource,
        TenantContext $tenants,
    ): JsonResponse {
        $branchUuid = $request->query('branch');

        $menu = $query->forBranch(is_string($branchUuid) && $branchUuid !== '' ? $branchUuid : null);

        if ($menu === null) {
            // Covers both "never published" and "no such public branch". One
            // answer for both: a distinguishable response would let a caller
            // map which branches a center has hidden.
            return ApiResponse::error(ApiErrorCode::NotFound, 'The requested resource was not found.');
        }

        return ApiResponse::data(
            $resource->toArray($menu, $tenants->require()->name, app()->getLocale())
        );
    }
}
