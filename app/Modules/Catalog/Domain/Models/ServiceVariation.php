<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A variant of a service: Short Hair, Medium Hair, Long Hair.
 *
 * NULL MEANS INHERIT, not zero and not a copy. A variation that has never been
 * given its own price follows the service's price forever — so a center that
 * raises the base price of a haircut does not discover three months later that
 * two of its five variations kept the old one because they were snapshotted at
 * creation (ADR-037).
 *
 * This is as far as Phase 4 goes on pricing. No rules engine, no time-based
 * pricing, no per-branch price matrix: those need a real requirement behind
 * them, and a center with three variations does not have one.
 *
 * @property int $id
 * @property string $uuid
 * @property int $service_id
 * @property TranslatedText $name
 * @property int|null $price_minor
 * @property int|null $duration_minutes
 * @property bool $is_active
 * @property int $sort_order
 */
final class ServiceVariation extends Model
{
    use UsesTenantConnection;

    protected $table = 'service_variations';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => Translatable::class,
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $variation): void {
            $variation->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * What a customer actually pays for this variation.
     *
     * Takes the parent explicitly rather than lazy-loading it: rendering a menu
     * calls this once per variation, and a hidden `$this->service` here is an
     * N+1 that only shows up under a real catalog.
     */
    public function effectivePrice(Service $service, ?Currency $currency = null): Money
    {
        return Money::fromMinor(
            $this->price_minor ?? $service->price_minor,
            $currency ?? Currency::default(),
        );
    }

    public function effectiveDurationMinutes(Service $service): int
    {
        return $this->duration_minutes ?? $service->duration_minutes;
    }

    public function overridesPrice(): bool
    {
        return $this->price_minor !== null;
    }

    public function overridesDuration(): bool
    {
        return $this->duration_minutes !== null;
    }
}
