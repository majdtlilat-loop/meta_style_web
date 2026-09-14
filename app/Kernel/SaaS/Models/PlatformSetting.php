<?php

declare(strict_types=1);

namespace App\Kernel\SaaS\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Platform behaviour Super Admin controls without a deploy.
 *
 * Business configuration — default trial length, and later default plan and
 * limits — belongs here rather than in config/ or an env var: changing it is a
 * business decision, not a release
 * (docs/12-DEPLOYMENT-MIGRATION-STRATEGY.md §9).
 *
 * @property string $key
 * @property mixed $value
 */
final class PlatformSetting extends Model
{
    public const DEFAULT_TRIAL_DAYS = 'default_trial_days';

    public const DEFAULT_PLAN_CODE = 'default_plan_code';

    protected $connection = 'control';

    protected $table = 'platform_settings';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = self::query()->where('key', $key)->first();

        if (! $row instanceof self) {
            return $default;
        }

        // Values are stored as JSON so a setting can be a scalar, a list or a
        // map without a schema change. Scalars round-trip through a one-element
        // wrapper.
        $value = $row->value;

        return is_array($value) && array_key_exists('value', $value) ? $value['value'] : $value;
    }

    public static function put(string $key, mixed $value): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => ['value' => $value]]);
    }
}
