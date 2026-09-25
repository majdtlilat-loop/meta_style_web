<?php

declare(strict_types=1);

namespace App\Modules\LandingCms\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property string $uuid
 * @property string $slug
 * @property array<string, mixed> $draft_content
 * @property array<string, mixed>|null $published_content
 * @property int $draft_version
 * @property int|null $published_version
 */
final class LandingPage extends Model
{
    protected $connection = 'control';

    protected $table = 'landing_pages';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'title' => Translatable::class,
            'draft_content' => 'array',
            'published_content' => 'array',
            'draft_version' => 'integer',
            'published_version' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $page): void {
            $page->uuid ??= (string) Str::uuid();
        });
    }
}
