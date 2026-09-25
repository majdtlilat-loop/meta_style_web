<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $key
 * @property array<string, string> $name
 * @property array<string, string>|null $description
 * @property bool $is_system
 * @property Carbon|null $archived_at
 */
final class PlatformRole extends Model
{
    protected $connection = 'control';

    protected $table = 'platform_roles';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'is_system' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsToMany<PlatformUser, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            PlatformUser::class,
            'platform_user_roles',
            'role_id',
            'user_id',
        )->withTimestamps();
    }

    public function label(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return (string) ($this->name[$locale] ?? $this->name['en'] ?? $this->key);
    }

    /** @return list<string> */
    public function permissions(): array
    {
        /** @var list<string> $permissions */
        $permissions = $this->newQuery()
            ->from('platform_role_permissions')
            ->where('role_id', $this->getKey())
            ->pluck('permission')->all();

        return $permissions;
    }
}
