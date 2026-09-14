<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Modules\Catalog\Application\Actions\SaveProduct;
use App\Modules\Catalog\Domain\Models\Product;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The small product catalog POS sells from. No stock, no cost (docs/18-SALES.md §8).
 */
final class ProductController extends Controller
{
    private const LIST_LIMIT = 200;

    public function index(Request $request, Entitlements $entitlements): JsonResponse
    {
        $user = $this->user($request);

        $entitlements->ensure('pos');

        if (! $user->hasPermission(Permission::SaleCreate) && ! $user->hasPermission(Permission::ProductManage)) {
            throw new AuthorizationException('You may not view products.');
        }

        $locale = app()->getLocale();

        /** @var list<Product> $products */
        $products = Product::query()->sellable()->limit(self::LIST_LIMIT)->get()->all();

        return ApiResponse::data([
            'products' => array_map(static fn (Product $product): array => [
                'uuid' => $product->uuid,
                'name' => $product->name->get($locale),
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'price' => $product->price()->toArray($locale),
            ], $products),
        ]);
    }

    public function store(Request $request, SaveProduct $save): JsonResponse
    {
        $product = $save($this->input($request), $this->user($request));

        return ApiResponse::data(['product' => ['uuid' => $product->uuid]], 201);
    }

    public function update(Request $request, string $uuid, SaveProduct $save): JsonResponse
    {
        $product = $save($this->input($request), $this->user($request), $this->find($uuid));

        return ApiResponse::data(['product' => ['uuid' => $product->uuid]]);
    }

    public function archive(Request $request, string $uuid, SaveProduct $save): JsonResponse
    {
        $save->archive($this->find($uuid), $this->user($request));

        return ApiResponse::data(['archived' => true]);
    }

    /**
     * @return array{name: array<string, string>, sku?: string|null, barcode?: string|null, price_minor: int, is_active?: bool, sort_order?: int}
     */
    private function input(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'array'],
            'name.*' => ['nullable', 'string', 'max:120'],
            'sku' => ['nullable', 'string', 'max:64'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'price_minor' => ['required', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        /** @var array<string, string> $name */
        $name = array_filter($validated['name'], static fn ($value): bool => is_string($value) && $value !== '');

        return [
            'name' => $name,
            'sku' => $validated['sku'] ?? null,
            'barcode' => $validated['barcode'] ?? null,
            'price_minor' => (int) $validated['price_minor'],
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ];
    }

    private function find(string $uuid): Product
    {
        /** @var Product|null $product */
        $product = Product::query()->where('uuid', $uuid)->first();

        if (! $product instanceof Product) {
            throw new NotFoundHttpException;
        }

        return $product;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
