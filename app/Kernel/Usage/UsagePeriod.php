<?php

declare(strict_types=1);

namespace App\Kernel\Usage;

use App\Kernel\SaaS\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * The window usage is counted in: two explicit UTC instants.
 *
 * ## Why not "this month"
 *
 * Because a center is billed on the day they signed up, not on the first. A
 * quota that resets on the 1st while the invoice covers the 14th to the 14th
 * gives everybody two weeks of double allowance and then a fortnight of none,
 * and every single support conversation about it starts with the customer being
 * right (docs/26-USAGE-QUOTAS.md §11).
 *
 * So the subscription's own `current_period_start` / `current_period_end` are
 * authoritative whenever the control plane has them. A trial, or a plan whose
 * billing dates have not been set, falls back to a deterministic calendar month
 * — deterministic meaning the same boundaries on every host, in every process.
 *
 * ## UTC, explicitly, always
 *
 * Never the server's local timezone. `Carbon::now()->startOfMonth()` on a host
 * set to `Asia/Baghdad` and on one set to `UTC` produce instants three hours
 * apart, which silently gives one center two different periods depending on
 * which worker answered — and the counter's unique key is the period start, so
 * the two would be different ROWS (§11).
 *
 * Branch-local time is deliberately irrelevant here. A billing period is a
 * commercial fact about the center, not an operational one about a shop floor.
 */
final readonly class UsagePeriod
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    public function contains(CarbonImmutable $at): bool
    {
        return $at >= $this->start && $at < $this->end;
    }

    /**
     * Resolves the period a moment falls in, for one tenant.
     *
     * Reads the control plane — which is why it is NOT on the hot path. A quota
     * check resolves the period once and then works entirely inside the tenant
     * database against the counter row it finds (§5).
     */
    public static function resolve(
        Config $config,
        string $tenantId,
        ?CarbonImmutable $at = null,
    ): self {
        $at = ($at ?? CarbonImmutable::now())->utc();

        if ($config->get('usage.period.strategy') === 'subscription') {
            $period = self::fromSubscription($tenantId, $at);

            if ($period instanceof self) {
                return $period;
            }
        }

        return self::calendarMonth($at);
    }

    /**
     * The subscription's own window, when it has one AND the moment is inside
     * it.
     *
     * The containment check matters: a subscription whose period ended three
     * weeks ago and has not been rolled forward would otherwise keep counting
     * today's usage into a closed period, hiding a center's current consumption
     * behind an old total. A stale window is treated as no window (§11).
     */
    private static function fromSubscription(string $tenantId, CarbonImmutable $at): ?self
    {
        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()->where('tenant_id', $tenantId)->first();

        if (! $subscription instanceof Subscription) {
            return null;
        }

        $start = $subscription->current_period_start;
        $end = $subscription->current_period_end;

        if ($start === null || $end === null) {
            return null;
        }

        // Stored as UTC and read back through a `datetime` cast under an
        // application timezone of UTC, so this converts nothing — it states the
        // zone rather than leaving it to whatever the process is set to.
        $period = new self(
            CarbonImmutable::instance($start)->utc(),
            CarbonImmutable::instance($end)->utc(),
        );

        return $period->contains($at) ? $period : null;
    }

    private static function calendarMonth(CarbonImmutable $at): self
    {
        $start = $at->startOfMonth()->startOfDay();

        // Half-open: [start, end). The next period's start IS this one's end,
        // so no instant belongs to two periods and none belongs to neither.
        return new self($start, $start->addMonth());
    }
}
