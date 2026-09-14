<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Catalog\Domain\Models\Product;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Creates or edits a product.
 *
 * Gated on `pos`: a product exists to be sold at the till, and a center without
 * a POS has nowhere to sell one.
 *
 * A price change here never reaches a sale that already charged the old price.
 * Sale lines are snapshots, and a finalized invoice carries its own copies
 * (docs/18-SALES.md §37).
 */
final class SaveProduct
{
    private const MAX_PRICE_MINOR = 1_000_000_000_000;

    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array{name: array<string, string>|TranslatedText, sku?: string|null, barcode?: string|null, price_minor: int, is_active?: bool, sort_order?: int}  $input
     */
    public function __invoke(array $input, User $actingUser, ?Product $product = null): Product
    {
        $this->entitlements->ensure('pos');

        if (! $actingUser->hasPermission(Permission::ProductManage)) {
            throw new AuthorizationException('You may not manage products.');
        }

        $name = $input['name'] instanceof TranslatedText
            ? $input['name']
            : TranslatedText::fromArray($input['name']);

        if ($name->isEmpty()) {
            throw ValidationException::withMessages(['name' => 'A product needs a name.']);
        }

        $price = $input['price_minor'];

        if ($price < 0 || $price > self::MAX_PRICE_MINOR) {
            throw ValidationException::withMessages(['price_minor' => 'That price is outside the allowed range.']);
        }

        $sku = $this->code($input['sku'] ?? null, 'sku');
        $barcode = $this->code($input['barcode'] ?? null, 'barcode');

        $this->assertUnique('sku', $sku, $product);
        $this->assertUnique('barcode', $barcode, $product);

        $before = $product === null ? null : [
            'price_minor' => $product->price_minor,
            'is_active' => $product->is_active,
        ];

        $product ??= new Product;

        $product->forceFill([
            'name' => $name,
            'sku' => $sku,
            'barcode' => $barcode,
            'price_minor' => $price,
            'is_active' => $input['is_active'] ?? true,
            'sort_order' => max(0, min(65535, $input['sort_order'] ?? 0)),
        ])->save();

        $this->audit->record(new AuditEvent(
            action: $before === null ? 'catalog.product.created' : 'catalog.product.updated',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: Product::class,
            targetId: $product->uuid,
            targetLabel: (string) $product->name,
            before: $before,
            after: ['price_minor' => $product->price_minor, 'is_active' => $product->is_active],
        ));

        return $product;
    }

    public function archive(Product $product, User $actingUser): Product
    {
        $this->entitlements->ensure('pos');

        if (! $actingUser->hasPermission(Permission::ProductManage)) {
            throw new AuthorizationException('You may not manage products.');
        }

        // Archived, never deleted: a sale line references what was sold.
        $product->forceFill(['archived_at' => now(), 'is_active' => false])->save();

        $this->audit->record(new AuditEvent(
            action: 'catalog.product.archived',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: Product::class,
            targetId: $product->uuid,
            targetLabel: (string) $product->name,
        ));

        return $product;
    }

    private function code(?string $value, string $field): ?string
    {
        $value = $value === null ? null : trim($value);

        if ($value === null || $value === '') {
            return null;
        }

        // Printable ASCII, no whitespace: what a scanner emits and a label shows.
        if (preg_match('/^[A-Za-z0-9\-_.]{1,64}$/', $value) !== 1) {
            throw ValidationException::withMessages([$field => 'Use letters, digits, dashes, dots or underscores only.']);
        }

        return $value;
    }

    private function assertUnique(string $column, ?string $value, ?Product $product): void
    {
        if ($value === null) {
            return;
        }

        $taken = Product::query()
            ->where($column, $value)
            ->when($product !== null, fn ($q) => $q->whereKeyNot($product?->getKey()))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([$column => 'Another product already uses that code.']);
        }
    }
}
