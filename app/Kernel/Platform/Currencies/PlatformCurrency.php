<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Currencies;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One currency Meta Style can price plans and bill centers in.
 *
 * @property int $id
 * @property string $code
 * @property TranslatedText $name
 * @property string $symbol
 * @property int $decimals
 * @property bool $is_enabled
 * @property bool $is_default
 * @property int $sort_order
 * @property Carbon|null $created_at
 */
final class PlatformCurrency extends Model
{
    protected $connection = 'control';

    protected $table = 'platform_currencies';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'name' => Translatable::class,
            'decimals' => 'integer',
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
