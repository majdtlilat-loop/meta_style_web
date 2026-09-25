<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Announcements;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A message the Super Admin sends to centers' staff.
 *
 * The words live here, once, in every platform language; each center's inbox
 * row only points at this uuid, and is rendered in the reader's language.
 *
 * @property int $id
 * @property string $uuid
 * @property TranslatedText $title
 * @property TranslatedText $body
 * @property string $severity
 * @property string $audience
 * @property list<string>|null $tenant_ids
 * @property string|null $action_url
 * @property int $centers_count
 * @property string $created_by_label
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 */
final class PlatformAnnouncement extends Model
{
    protected $connection = 'control';

    protected $table = 'platform_announcements';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'title' => Translatable::class,
            'body' => Translatable::class,
            'tenant_ids' => 'array',
            'centers_count' => 'integer',
            'recipients_count' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $announcement): void {
            $announcement->uuid ??= (string) Str::uuid();
        });
    }
}
