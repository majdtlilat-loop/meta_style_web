<?php

declare(strict_types=1);

namespace App\Kernel\Usage;

use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Usage\Events\UsageThresholdReached;
use App\Kernel\Usage\Exceptions\QuotaExceeded;
use App\Kernel\Usage\Exceptions\UnknownResource;
use App\Kernel\Usage\Models\UsageCounter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * What a module calls to count something, and to ask whether it may.
 *
 * The whole public surface of metering, and deliberately tiny: {@see consume()}
 * for a resource with a hard ceiling, {@see meter()} for one that only records,
 * and {@see summary()} to report. Everything below it — periods, allowance
 * resolution, snapshots, atomic arithmetic — is an implementation detail no
 * caller has to hold in their head (docs/26-USAGE-QUOTAS.md §1).
 *
 * ## Generic, permanently
 *
 * It takes a resource CODE and a source identity. It has no idea what an AI run
 * or a WhatsApp message is, when RAYAN should run, or which conversation should
 * hand off — those are decisions for the modules above, and a Kernel that knew
 * them would be a Kernel that depends on business modules
 * (Phase 13 correction 3).
 *
 * ## Counting exactly once
 *
 * The event row and the counter move in ONE transaction, event first:
 *
 *   - a replay finds the unique index already satisfied, writes nothing, and
 *     reports success — a retried webhook is not a second message;
 *   - a refusal rolls the event back with it, so the evidence never claims
 *     usage that was denied.
 *
 * Nothing anywhere asks "have I already counted this". The question is answered
 * by `unique(resource, source_type, source_uuid)` (§5).
 */
final class Usage
{
    public function __construct(
        private readonly UsageCatalog $catalog,
        private readonly UsageCounters $counters,
        private readonly TenantContext $tenants,
        private readonly Config $config,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Spends allowance, or refuses.
     *
     * Only meaningful for a resource the catalog marks `enforced`. For a
     * metered one this behaves exactly like {@see meter()} and never throws,
     * so a caller cannot accidentally create a hard limit the product does not
     * claim to have (§3).
     *
     * @throws QuotaExceeded when an enforced allowance is spent
     * @throws UnknownResource
     */
    public function consume(
        string $resource,
        string $sourceType,
        string $sourceUuid,
        int $quantity = 1,
        ?string $provider = null,
        ?string $model = null,
    ): void {
        $this->write($resource, $sourceType, $sourceUuid, $quantity, $provider, $model, enforce: true);
    }

    /**
     * Records usage. Refuses nothing, ever.
     *
     * @throws UnknownResource
     */
    public function meter(
        string $resource,
        string $sourceType,
        string $sourceUuid,
        int $quantity = 1,
        ?string $provider = null,
        ?string $model = null,
    ): void {
        $this->write($resource, $sourceType, $sourceUuid, $quantity, $provider, $model, enforce: false);
    }

    /**
     * Would one more be allowed, without spending anything?
     *
     * ADVISORY, exactly like booking availability. A caller that wants to fail
     * early — checking before assembling an expensive request — may ask; the
     * decision that counts is still {@see consume()}, which is atomic. Two
     * callers can both be told yes for the last unit, and only one will get it.
     */
    public function allows(string $resource, int $quantity = 1): bool
    {
        $this->catalog->assertKnown($resource);

        if (! $this->catalog->isEnforced($resource)) {
            return true;
        }

        $counter = $this->counter($resource);

        if ($counter->isUnlimited()) {
            return true;
        }

        return $counter->used + $quantity <= (int) $counter->allowance_snapshot;
    }

    /**
     * What to show a manager for one resource.
     */
    public function summary(string $resource): UsageSummary
    {
        $this->catalog->assertKnown($resource);

        return UsageSummary::of(
            $resource,
            $this->catalog->group($resource),
            $this->counter($resource),
            $this->catalog->isEnforced($resource),
            $this->catalog->thresholds(),
        );
    }

    /**
     * Every resource in a group — `ai`, `whatsapp` — in catalog order.
     *
     * @return list<UsageSummary>
     */
    public function summaries(string $group): array
    {
        return array_map(
            fn (string $resource): UsageSummary => $this->summary($resource),
            $this->catalog->inGroup($group),
        );
    }

    /**
     * The counter row for the current period, created if this is the first use.
     */
    public function counter(string $resource): UsageCounter
    {
        $tenantId = $this->tenants->require()->id;

        return $this->counters->forPeriod(
            $tenantId,
            $resource,
            UsagePeriod::resolve($this->config, $tenantId),
        );
    }

    /**
     * @throws QuotaExceeded
     * @throws UnknownResource
     */
    private function write(
        string $resource,
        string $sourceType,
        string $sourceUuid,
        int $quantity,
        ?string $provider,
        ?string $model,
        bool $enforce,
    ): void {
        $this->catalog->assertKnown($resource);

        $enforce = $enforce && $this->catalog->isEnforced($resource);

        $counter = $this->counter($resource);
        $now = CarbonImmutable::now()->utc();

        $counted = DB::connection('tenant')->transaction(
            function () use ($resource, $sourceType, $sourceUuid, $quantity, $provider, $model, $counter, $enforce, $now): bool {
                $inserted = DB::connection('tenant')->table('usage_events')->insertOrIgnore([
                    'resource' => $resource,
                    'quantity' => $quantity,
                    'source_type' => $sourceType,
                    'source_uuid' => $sourceUuid,
                    'provider' => $provider,
                    'model' => $model,
                    'occurred_at' => $now,
                    'period_start' => $counter->period_start,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($inserted === 0) {
                    /*
                     * Already counted. A replayed webhook, a retried listener,
                     * a reconciler pass over the same fact. Reported as success
                     * because it IS success — the usage happened once and was
                     * recorded once (§5).
                     */
                    return false;
                }

                if (! $enforce) {
                    $this->counters->record($counter, $quantity);

                    return true;
                }

                if (! $this->counters->consume($counter, $quantity)) {
                    // Rolls the event back with it: the evidence must never
                    // record usage that was refused.
                    throw new QuotaExceeded($resource, (int) $counter->allowance_snapshot);
                }

                return true;
            }
        );

        if ($counted) {
            $this->announceThresholds($resource);
        }
    }

    /**
     * Raises each newly crossed threshold exactly once.
     *
     * AFTER the transaction, deliberately. An alert is a reaction to usage that
     * has already happened, and a failure to announce one must never undo the
     * usage or fail the call that caused it — the same rule the benefit
     * listeners follow (ADR-061, §13).
     *
     * `insertOrIgnore` on `unique(resource, period_start, threshold)` is what
     * makes "exactly once" true under concurrency: fifty simultaneous messages
     * crossing 85% produce one inserted row and forty-nine no-ops, so one
     * event is dispatched.
     */
    private function announceThresholds(string $resource): void
    {
        $counter = $this->counter($resource);
        $percent = $counter->percent();

        if ($percent === null) {
            // Unlimited. There is no threshold to cross.
            return;
        }

        $now = CarbonImmutable::now()->utc();

        foreach ($this->catalog->thresholds() as $threshold) {
            if ($percent < $threshold['percent']) {
                continue;
            }

            $inserted = DB::connection('tenant')->table('usage_alerts')->insertOrIgnore([
                'resource' => $resource,
                'period_start' => $counter->period_start,
                'threshold' => $threshold['percent'],
                'raised_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === 1) {
                $this->events->dispatch(new UsageThresholdReached(
                    resource: $resource,
                    group: $this->catalog->group($resource),
                    threshold: $threshold['percent'],
                    status: $threshold['status'],
                    used: $counter->used,
                    allowance: $counter->allowance_snapshot,
                    periodStart: $counter->period_start,
                    periodEnd: $counter->period_end,
                ));
            }
        }
    }
}
