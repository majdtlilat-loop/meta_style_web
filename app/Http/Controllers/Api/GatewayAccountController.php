<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Authorization\Permission;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Modules\Payments\Application\Actions\ManageGatewayAccount;
use App\Modules\Payments\Application\PaymentsPresenter;
use App\Modules\Payments\Application\PaymentsQuery;
use App\Modules\Payments\Domain\Enums\GatewayEnvironment;
use App\Modules\Payments\Domain\Models\GatewayAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A branch's payment gateway accounts.
 *
 * Credentials go IN through `configure` and never come out: every response is
 * the allow-listed presenter, which has no credentials key
 * (docs/19-PAYMENTS.md §§19, 48).
 */
final class GatewayAccountController extends Controller
{
    public function index(Request $request, string $branchUuid, PaymentsQuery $query, PaymentsPresenter $presenter): JsonResponse
    {
        $accounts = $query->gatewayAccounts($branchUuid, $this->user($request));

        return ApiResponse::data([
            'accounts' => array_map(fn (GatewayAccount $account): array => $presenter->gatewayAccount($account), $accounts),
            'providers' => $presenter->providers(),
        ]);
    }

    public function configure(Request $request, string $branchUuid, string $provider, ManageGatewayAccount $manage, PaymentsPresenter $presenter): JsonResponse
    {
        $validated = $request->validate([
            'environment' => ['required', 'string', Rule::in(array_map(static fn (GatewayEnvironment $e): string => $e->value, GatewayEnvironment::cases()))],
            'display_name' => ['required', 'string', 'min:2', 'max:120'],
            'credentials' => ['required', 'array'],
            'credentials.*' => ['string', 'max:500'],
        ]);

        /** @var array<string, mixed> $credentials */
        $credentials = $validated['credentials'];

        $account = $manage->configure(
            $branchUuid,
            $provider,
            $this->user($request),
            $credentials,
            GatewayEnvironment::from($validated['environment']),
            (string) $validated['display_name'],
        );

        return ApiResponse::data(['account' => $presenter->gatewayAccount($account)]);
    }

    public function enable(Request $request, string $uuid, PaymentsQuery $query, ManageGatewayAccount $manage, PaymentsPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);
        $account = $query->gatewayAccount($uuid, $user, Permission::PaymentGatewayManage);

        return ApiResponse::data(['account' => $presenter->gatewayAccount($manage->setEnabled($account, $user, true))]);
    }

    public function disable(Request $request, string $uuid, PaymentsQuery $query, ManageGatewayAccount $manage, PaymentsPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);
        $account = $query->gatewayAccount($uuid, $user, Permission::PaymentGatewayManage);

        return ApiResponse::data(['account' => $presenter->gatewayAccount($manage->setEnabled($account, $user, false))]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
