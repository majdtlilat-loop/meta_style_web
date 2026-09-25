<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\CustomerBenefits;
use App\Kernel\Http\ApiErrorCode;
use App\Kernel\Http\ApiResponse;
use App\Modules\Customers\Domain\Models\CustomerAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A signed-in customer's own points, memberships and packages.
 *
 * Identity comes from the `customer-api` guard, never from the request; the
 * payload is the customer allow-list (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md
 * §19).
 */
final class CustomerBenefitsController extends Controller
{
    public function index(Request $request, CustomerBenefits $benefits): JsonResponse
    {
        $account = $request->user('customer-api');

        if (! $account instanceof CustomerAccount) {
            return ApiResponse::error(ApiErrorCode::Unauthenticated, 'Authentication is required.');
        }

        return ApiResponse::data(['benefits' => $benefits->for((int) $account->customer_id)]);
    }
}
