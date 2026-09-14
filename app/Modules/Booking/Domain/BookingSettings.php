<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain;

use App\Kernel\Localization\TenantLocales;
use App\Kernel\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The handful of booking knobs a center actually turns.
 *
 * Stored in the tenant's own `settings` table, the same place
 * {@see TenantLocales} keeps enabled languages: this
 * is tenant business configuration, read only inside tenant context, and
 * putting it in the control plane would be a second place to look for one
 * answer.
 *
 * FIVE VALUES. Buffer times, per-service lead times, deposits, cancellation
 * fees, no-show penalties and per-branch horizons are all things centers will
 * eventually ask for, and not one of them is needed to make the Booking Engine
 * correct. Building them now would mean designing a policy module against
 * imagined requirements and then discovering the real ones do not fit
 * (docs/13-ROADMAP.md Phase 6 §28).
 *
 * Cached per request. Bound as `scoped` in the container for the reason
 * TenantLocales is: with a transient binding, the availability engine and a
 * settings form would hold different copies, and saving in one would leave the
 * other answering from a stale cache inside the same request.
 */
final class BookingSettings
{
    private const KEY = 'booking';

    /** @var array<string, array<string, int>> */
    private array $cache = [];

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * The granularity public booking offers times on.
     *
     * Clamped to a sane band rather than trusted: a stored `0` would make slot
     * generation loop forever, and a stored `1` would offer 1,440 slots a day
     * per employee — both of which are ways a settings bug becomes an outage.
     */
    public function slotIntervalMinutes(): int
    {
        return $this->clamp($this->read('slot_interval_minutes'), 5, 120);
    }

    public function maxAdvanceDays(): int
    {
        return $this->clamp($this->read('max_advance_days'), 1, 365);
    }

    public function minLeadMinutes(): int
    {
        return $this->clamp($this->read('min_lead_minutes'), 0, 60 * 24 * 30);
    }

    public function customerCancelNoticeMinutes(): int
    {
        return $this->clamp($this->read('customer_cancel_notice_minutes'), 0, 60 * 24 * 30);
    }

    public function maxCalendarDays(): int
    {
        return $this->clamp($this->read('max_calendar_days'), 1, 92);
    }

    /**
     * @param  array<string, int>  $values
     */
    public function save(array $values): void
    {
        $current = $this->all();

        foreach ($values as $key => $value) {
            if (array_key_exists($key, $current)) {
                $current[$key] = $value;
            }
        }

        DB::connection('tenant')->table('settings')->updateOrInsert(
            ['key' => self::KEY],
            [
                'value' => json_encode($current, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $this->forget();
    }

    /**
     * @return array<string, int>
     */
    public function all(): array
    {
        $key = $this->tenants->id() ?? 'none';

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $defaults = $this->defaults();

        if (! $this->tenants->isBound()) {
            return $this->cache[$key] = $defaults;
        }

        $raw = DB::connection('tenant')->table('settings')->where('key', self::KEY)->value('value');

        $stored = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($stored)) {
            return $this->cache[$key] = $defaults;
        }

        $merged = $defaults;

        foreach ($defaults as $name => $default) {
            $value = $stored[$name] ?? null;

            $merged[$name] = is_numeric($value) ? (int) $value : $default;
        }

        return $this->cache[$key] = $merged;
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    private function read(string $name): int
    {
        return $this->all()[$name] ?? 0;
    }

    /**
     * @return array<string, int>
     */
    private function defaults(): array
    {
        /** @var array<string, mixed> $configured */
        $configured = config('metastyle.booking', []);

        return [
            'slot_interval_minutes' => $this->configured($configured, 'slot_interval_minutes', 15),
            'max_advance_days' => $this->configured($configured, 'max_advance_days', 60),
            'min_lead_minutes' => $this->configured($configured, 'min_lead_minutes', 0),
            'customer_cancel_notice_minutes' => $this->configured($configured, 'customer_cancel_notice_minutes', 0),
            'max_calendar_days' => $this->configured($configured, 'max_calendar_days', 31),
        ];
    }

    /**
     * @param  array<string, mixed>  $configured
     */
    private function configured(array $configured, string $key, int $fallback): int
    {
        $value = $configured[$key] ?? null;

        return is_numeric($value) ? (int) $value : $fallback;
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
