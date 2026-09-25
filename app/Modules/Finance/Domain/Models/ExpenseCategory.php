<?php

declare(strict_types=1);

namespace App\Modules\Finance\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The center's own name for a kind of spending. Archived, never deleted.
 *
 * @property int $id
 * @property string $uuid
 * @property TranslatedText $name
 * @property int $sort_order
 * @property Carbon|null $archived_at
 */
final class ExpenseCategory extends Model
{
    use UsesTenantConnection;

    protected $table = 'expense_categories';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => Translatable::class,
            'sort_order' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $category): void {
            $category->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @param  Builder<ExpenseCategory>  $query
     * @return Builder<ExpenseCategory>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}
