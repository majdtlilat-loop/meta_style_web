<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Kernel\Localization\TranslatedText;
use App\Kernel\Money\Currency;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceAddon;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use App\Modules\Catalog\Domain\Models\ServiceVariation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the till can put on a sale, as the till shows it.
 *
 * DISPLAY ONLY. The prices here are what the catalog says today, formatted for
 * a tile; the price a line is CHARGED is resolved again by
 * {@see LinePriceResolver} the moment it is added, and nothing here is ever
 * sent back as an amount (docs/18-SALES.md §9).
 *
 * Bounded (60 of each) and eager-loaded: a center with four hundred services
 * opens the till in a fixed number of queries. Names come back in the viewer's
 * language, so a view never inspects a translation object.
 */
final class TillCatalog
{
    public const LIMIT = 60;

    public function __construct(private readonly LineEmployees $employees) {}

    /**
     * Categories that have something to sell at this branch.
     *
     * @return list<array{uuid: string, name: string}>
     */
    public function categories(Branch $branch): array
    {
        $locale = app()->getLocale();

        // The categories of what is on sale here — never a category whose
        // services are all elsewhere or archived.
        $ids = Service::query()
            ->active()
            ->atBranch($branch->id)
            ->whereNotNull('service_category_id')
            ->reorder()
            ->distinct()
            ->limit(200)
            ->pluck('service_category_id')
            ->all();

        if ($ids === []) {
            return [];
        }

        /** @var list<ServiceCategory> $categories */
        $categories = ServiceCategory::query()
            ->active()
            ->whereIn('id', $ids)
            ->limit(40)
            ->get()
            ->all();

        return array_map(static fn (ServiceCategory $category): array => [
            'uuid' => $category->uuid,
            'name' => $category->name->get($locale),
        ], $categories);
    }

    /**
     * @return list<array{uuid: string, name: string, price: array{amount: int, currency: string, formatted: string}, configurable: bool}>
     */
    public function services(Branch $branch, string $search = '', string $categoryUuid = ''): array
    {
        $locale = app()->getLocale();
        $currency = Currency::default();

        $query = Service::query()
            ->active()
            ->atBranch($branch->id)
            ->withCount([
                'variations' => fn (Builder $variations) => $variations->where('is_active', true),
                'addons' => fn (Builder $addons) => $addons->where('service_addons.is_active', true),
            ])
            ->limit(self::LIMIT);

        $term = trim($search);

        if ($term !== '') {
            $query->where('name', 'like', '%'.addcslashes($term, '%_\\').'%');
        }

        if ($categoryUuid !== '') {
            $query->whereHas('category', fn (Builder $category) => $category->where('uuid', $categoryUuid));
        }

        /** @var list<Service> $services */
        $services = $query->get()->all();

        return array_map(static fn (Service $service): array => [
            'uuid' => $service->uuid,
            'name' => $service->name->get($locale),
            'price' => $service->price($currency)->toArray($locale),
            // Options or extras to choose: the tile opens a small form rather
            // than adding the line straight away.
            'configurable' => (int) $service->getAttribute('variations_count') > 0 || (int) $service->getAttribute('addons_count') > 0,
        ], $services);
    }

    /**
     * Products, a scanned barcode or SKU matched exactly first.
     *
     * @return list<array{uuid: string, name: string, price: array{amount: int, currency: string, formatted: string}, code: string|null}>
     */
    public function products(string $search = ''): array
    {
        $locale = app()->getLocale();
        $currency = Currency::default();
        $term = trim($search);

        $query = Product::query()->sellable()->limit(self::LIMIT);

        if ($term !== '') {
            $query->where(function (Builder $q) use ($term): void {
                $q->where('barcode', $term)
                    ->orWhere('sku', $term)
                    ->orWhere('name', 'like', '%'.addcslashes($term, '%_\\').'%');
            });
        }

        /** @var list<Product> $products */
        $products = $query->get()->all();

        return array_map(static fn (Product $product): array => [
            'uuid' => $product->uuid,
            'name' => $product->name->get($locale),
            'price' => $product->price($currency)->toArray($locale),
            'code' => $product->barcode ?? $product->sku,
        ], $products);
    }

    /**
     * The product list the POS settings manage — active and inactive, or the
     * archived ones — searched by name, barcode or SKU. Paginated.
     *
     * @return LengthAwarePaginator<int, Product>
     */
    public function productsPage(string $search = '', bool $archived = false, int $page = 1): LengthAwarePaginator
    {
        $query = Product::query()->orderBy('sort_order')->orderBy('id');

        $archived ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');

        $term = trim($search);

        if ($term !== '') {
            $query->where(function (Builder $q) use ($term): void {
                $q->where('barcode', $term)
                    ->orWhere('sku', $term)
                    ->orWhere('name', 'like', '%'.addcslashes($term, '%_\\').'%');
            });
        }

        return $query->paginate(20, ['*'], 'page', max(1, $page));
    }

    public function product(string $uuid): ?Product
    {
        /** @var Product|null $product */
        $product = Product::query()->where('uuid', $uuid)->first();

        return $product;
    }

    /**
     * The one product a scanned code names exactly, if any — so a scan adds the
     * item without a second tap.
     */
    public function productByCode(string $code): ?string
    {
        $code = trim($code);

        if ($code === '' || preg_match('/^[A-Za-z0-9\-_.]{3,64}$/', $code) !== 1) {
            return null;
        }

        /** @var list<string> $matches */
        $matches = Product::query()
            ->sellable()
            ->where(fn (Builder $q) => $q->where('barcode', $code)->orWhere('sku', $code))
            ->limit(2)
            ->pluck('uuid')
            ->all();

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * One service with the options and extras the till may add with it.
     *
     * @return array{uuid: string, name: string, price: array{amount: int, currency: string, formatted: string}, variations: list<array{uuid: string, name: string, price: array{amount: int, currency: string, formatted: string}}>, addons: list<array{uuid: string, name: string, price: array{amount: int, currency: string, formatted: string}}>, employees: list<array{uuid: string, name: string}>}|null
     */
    public function pick(Branch $branch, string $serviceUuid): ?array
    {
        if ($serviceUuid === '') {
            return null;
        }

        /** @var Service|null $service */
        $service = Service::query()
            ->active()
            ->atBranch($branch->id)
            ->where('uuid', $serviceUuid)
            ->with([
                'variations' => fn ($variations) => $variations->where('is_active', true),
                'addons' => fn ($addons) => $addons->where('service_addons.is_active', true),
            ])
            ->first();

        if (! $service instanceof Service) {
            return null;
        }

        $locale = app()->getLocale();
        $currency = Currency::default();
        $name = static fn (TranslatedText $text): string => $text->get($locale);

        return [
            'uuid' => $service->uuid,
            'name' => $name($service->name),
            'price' => $service->price($currency)->toArray($locale),
            'variations' => array_values($service->variations->map(static fn (ServiceVariation $variation): array => [
                'uuid' => $variation->uuid,
                'name' => $name($variation->name),
                'price' => $variation->effectivePrice($service, $currency)->toArray($locale),
            ])->all()),
            'addons' => array_values($service->addons->map(static fn (ServiceAddon $addon): array => [
                'uuid' => $addon->uuid,
                'name' => $name($addon->name),
                'price' => $addon->price($currency)->toArray($locale),
            ])->all()),
            // Who may be named as having performed it, here.
            'employees' => $this->employees->forService($branch->id, $service->id),
        ];
    }
}
