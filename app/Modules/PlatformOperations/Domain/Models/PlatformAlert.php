<?php

declare(strict_types=1);

namespace App\Modules\PlatformOperations\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class PlatformAlert extends Model
{
    protected $connection = 'control';

    protected $table = 'platform_alerts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['title' => Translatable::class, 'body' => Translatable::class, 'is_active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $alert): void {
            $alert->uuid ??= (string) Str::uuid();
        });
    }
}
