<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Modules\Packages\Application\Actions\ApplyPackage;
use App\Modules\Packages\Application\Actions\CancelCustomerPackage;
use App\Modules\Packages\Application\Actions\ManagePackageDefinition;
use App\Modules\Packages\Application\PackagesPresenter;
use App\Modules\Packages\Application\PackagesQuery;
use App\Modules\Packages\Domain\Models\PackageDefinition;
use App\Modules\Sales\Application\SalesPresenter;
use App\Modules\Sales\Application\SalesQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Service packages for staff: definitions, a customer's packages, cancelling
 * one, and covering a performed service on a till line
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §27).
 *
 * A package is SOLD through the till like any line — `POST sales/{uuid}/items`
 * with `kind=offering, offering_type=package` — never through a checkout of
 * its own. Booking never consumes one.
 */
final class PackageController extends Controller
{
    public function definitions(Request $request, PackagesQuery $query, PackagesPresenter $presenter): JsonResponse
    {
        $definitions = $query->definitions($this->user($request), $request->boolean('archived'));

        return ApiResponse::data(['definitions' => array_map(fn (PackageDefinition $definition): array => $presenter->definition($definition), $definitions)]);
    }

    public function store(Request $request, ManagePackageDefinition $manage, PackagesPresenter $presenter): JsonResponse
    {
        $input = $this->definitionInput($request);

        $definition = $manage->save($this->user($request), $input['name'], $input['price_minor'], $input['validity_days'], $input['items'], $input['sort_order']);

        return ApiResponse::data(['definition' => $presenter->definition($definition->load(['items.service', 'items.variation']))], 201);
    }

    public function update(Request $request, string $uuid, PackagesQuery $query, ManagePackageDefinition $manage, PackagesPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);
        $input = $this->definitionInput($request);

        $definition = $manage->save($user, $input['name'], $input['price_minor'], $input['validity_days'], $input['items'], $input['sort_order'], $query->definition($uuid, $user));

        return ApiResponse::data(['definition' => $presenter->definition($definition->load(['items.service', 'items.variation']))]);
    }

    public function archive(Request $request, string $uuid, PackagesQuery $query, ManagePackageDefinition $manage, PackagesPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        return ApiResponse::data(['definition' => $presenter->definition($manage->archive($user, $query->definition($uuid, $user)))]);
    }

    public function customer(Request $request, string $uuid, PackagesQuery $query, PackagesPresenter $presenter): JsonResponse
    {
        return ApiResponse::data(['packages' => $presenter->forStaff($query->forCustomer($uuid, $this->user($request))['packages'])]);
    }

    public function cancel(Request $request, string $uuid, CancelCustomerPackage $cancel, PackagesPresenter $presenter): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:190']]);

        $package = $cancel($uuid, $this->user($request), (string) $validated['reason']);

        return ApiResponse::data(['package' => $presenter->forStaff([$package->load('items')])[0]]);
    }

    public function apply(Request $request, string $uuid, string $lineUuid, ApplyPackage $apply, SalesQuery $sales, SalesPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'package' => ['required', 'uuid'],
            'sessions' => ['nullable', 'integer', 'min:1', 'max:999'],
            // For a line typed at the till: the person confirms the service was
            // performed. A visit line proves it by its completed stage (§16).
            'performed' => ['nullable', 'boolean'],
        ]);

        $apply->apply($sales->find($uuid, $user), $user, $lineUuid, (string) $validated['package'],
            (int) ($validated['sessions'] ?? 1), (bool) ($validated['performed'] ?? false));

        return ApiResponse::data(['sale' => $presenter->sale($sales->find($uuid, $user), $user)], 201);
    }

    public function withdraw(Request $request, string $uuid, string $lineUuid, ApplyPackage $apply, SalesQuery $sales, SalesPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $apply->withdraw($sales->find($uuid, $user), $user, $lineUuid);

        return ApiResponse::data(['sale' => $presenter->sale($sales->find($uuid, $user), $user)]);
    }

    /**
     * @return array{name: array<string, string|null>, price_minor: int, validity_days: int, sort_order: int, items: list<array{service: string, variation?: string|null, quantity: int}>}
     */
    private function definitionInput(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'array'],
            'name.*' => ['nullable', 'string', 'max:120'],
            'price_minor' => ['required', 'integer', 'min:0'],
            'validity_days' => ['required', 'integer', 'min:1'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'items' => ['required', 'array', 'min:1', 'max:'.ManagePackageDefinition::MAX_ITEMS],
            'items.*.service' => ['required', 'uuid'],
            'items.*.variation' => ['nullable', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        /** @var array<string, string|null> $name */
        $name = $validated['name'];

        $items = [];

        /** @var array<string, mixed> $item */
        foreach ($validated['items'] as $item) {
            $items[] = [
                'service' => (string) $item['service'],
                'variation' => isset($item['variation']) ? (string) $item['variation'] : null,
                'quantity' => (int) $item['quantity'],
            ];
        }

        return [
            'name' => $name,
            'price_minor' => (int) $validated['price_minor'],
            'validity_days' => (int) $validated['validity_days'],
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'items' => $items,
        ];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
