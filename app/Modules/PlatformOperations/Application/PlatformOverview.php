<?php

declare(strict_types=1);

namespace App\Modules\PlatformOperations\Application;

use App\Kernel\Localization\TranslatedText;
use App\Kernel\Time\DateRange;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Super Admin overview, for one date range and its comparable period.
 *
 * Control-plane facts only — no center database is opened — and every figure
 * is an aggregate over indexed columns, so the page costs the same with ten
 * centers or ten thousand. Money stays partitioned by currency: amounts in
 * different currencies are never added into one fictional total.
 *
 * Reads never write, and nothing here is estimated or projected. Figures the
 * control plane keeps no history for (a subscription's status last month) are
 * reported as CURRENT snapshots and never given a fake comparison.
 */
final class PlatformOverview
{
    private const OPEN_INVOICES = ['issued', 'partially_paid', 'overdue'];

    private const OPEN_TICKETS = ['open', 'in_progress', 'waiting_center'];

    /**
     * @return array<string, mixed>
     */
    public function build(DateRange $range, string $locale): array
    {
        $previous = $range->previous();
        $buckets = $range->buckets($locale);

        return [
            'buckets' => array_map(static fn (array $bucket): array => ['label' => $bucket['label']], $buckets),
            'centers' => $this->centers($range, $previous, $buckets),
            'subscriptions' => $this->subscriptions(),
            'billing' => $this->billing($range, $previous, $buckets),
            'support' => $this->support($range, $previous, $buckets),
            'registrations' => $this->registrations(),
            'activity' => $this->activity(),
            'issues' => $this->issues(),
            'platform' => [
                'users' => $this->control()->table('platform_users')->where('is_active', true)->whereNull('archived_at')->count(),
                'alerts' => $this->control()->table('platform_alerts')->where('is_active', true)->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->count(),
            ],
        ];
    }

    /**
     * @param  list<array{key: string, label: string, start: mixed, end: mixed}>  $buckets
     * @return array<string, mixed>
     */
    private function centers(DateRange $range, DateRange $previous, array $buckets): array
    {
        $tenants = fn (): Builder => $this->control()->table('tenants');

        /** @var array<string, int> $byStatus */
        $byStatus = $tenants()->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status')->map(fn (mixed $count): int => (int) $count)->all();

        $newSeries = $this->series($tenants(), 'created_at', $range, $buckets);

        // Cumulative total at the close of each bucket, for the trend line.
        $running = $tenants()->where('created_at', '<', $range->startUtc())->count();
        $totals = [];
        foreach ($newSeries as $count) {
            $running += $count;
            $totals[] = $running;
        }

        return [
            'total' => $tenants()->where('created_at', '<', $range->endUtc())->count(),
            'total_previous' => $tenants()->where('created_at', '<', $previous->endUtc())->count(),
            'new' => array_sum($newSeries),
            'new_previous' => $this->between($tenants(), 'created_at', $previous)->count(),
            'new_series' => $newSeries,
            'total_series' => $totals,
            'by_status' => $byStatus,
            'active' => $byStatus['active'] ?? 0,
            'suspended' => $byStatus['suspended'] ?? 0,
        ];
    }

    /**
     * Current snapshot — subscriptions keep no status history to compare with.
     *
     * @return array<string, mixed>
     */
    private function subscriptions(): array
    {
        $subscriptions = fn (): Builder => $this->control()->table('subscriptions');

        /** @var array<string, int> $byStatus */
        $byStatus = $subscriptions()->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status')->map(fn (mixed $count): int => (int) $count)->all();

        // The name as SOLD (the snapshot); subscriptions older than snapshots
        // fall back to the plan they point at.
        $name = 'COALESCE(subscriptions.plan_name_snapshot, plans.name)';
        $byPlan = $subscriptions()
            ->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->whereIn('subscriptions.status', ['trialing', 'active', 'past_due'])
            ->selectRaw($name.' AS plan_name, COUNT(*) AS aggregate')
            ->groupByRaw($name)
            ->orderByDesc('aggregate')
            ->limit(6)
            ->get()
            ->map(fn (object $row): array => ['name' => $this->planName((string) $row->plan_name), 'count' => (int) $row->aggregate])
            ->all();

        return [
            'by_status' => $byStatus,
            'by_plan' => $byPlan,
            'live' => ($byStatus['active'] ?? 0) + ($byStatus['trialing'] ?? 0) + ($byStatus['past_due'] ?? 0),
            'trials_ending_soon' => $subscriptions()->where('status', 'trialing')->whereBetween('trial_ends_at', [now(), now()->addDays(7)])->count(),
            // Live subscriptions by the billing cycle they are on.
            'by_cycle' => $subscriptions()->whereIn('status', ['trialing', 'active', 'past_due'])
                ->selectRaw("COALESCE(billing_period_snapshot, 'monthly') AS cycle, COUNT(*) AS aggregate")
                ->groupBy('cycle')->pluck('aggregate', 'cycle')->map(fn (mixed $count): int => (int) $count)->all(),
        ];
    }

    /**
     * @param  list<array{key: string, label: string, start: mixed, end: mixed}>  $buckets
     * @return array<string, mixed>
     */
    private function billing(DateRange $range, DateRange $previous, array $buckets): array
    {
        $invoices = fn (): Builder => $this->control()->table('saas_invoices')->where('status', '!=', 'void');
        // A reversed settlement was never money received.
        $payments = fn (): Builder => $this->control()->table('saas_payments')->whereNull('reversed_at');

        $currencies = collect($invoices()->distinct()->pluck('currency'))
            ->merge($payments()->distinct()->pluck('currency'))
            ->filter()
            ->map(fn (mixed $code): string => (string) $code)
            ->unique()
            ->values()
            ->all();

        $byCurrency = [];
        foreach ($currencies as $currency) {
            $billed = $this->series($invoices()->where('currency', $currency), 'issued_at', $range, $buckets, 'total_minor');
            $collected = $this->series($payments()->where('currency', $currency), 'received_at', $range, $buckets, 'amount_minor');

            $byCurrency[] = [
                'currency' => $currency,
                'billed' => array_sum($billed),
                'billed_previous' => (int) $this->between($invoices()->where('currency', $currency), 'issued_at', $previous)->sum('total_minor'),
                'collected' => array_sum($collected),
                'collected_previous' => (int) $this->between($payments()->where('currency', $currency), 'received_at', $previous)->sum('amount_minor'),
                'billed_series' => $billed,
                'collected_series' => $collected,
                'outstanding' => (int) $this->control()->table('saas_invoices')->where('currency', $currency)->whereIn('status', self::OPEN_INVOICES)->sum(DB::raw('total_minor - paid_minor')),
            ];
        }

        // The currency with the most activity leads the KPI cards.
        usort($byCurrency, static fn (array $a, array $b): int => ($b['billed'] + $b['collected'] + $b['outstanding']) <=> ($a['billed'] + $a['collected'] + $a['outstanding']));

        /** @var array<string, int> $byStatus */
        $byStatus = $this->control()->table('saas_invoices')->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status')->map(fn (mixed $count): int => (int) $count)->all();

        return [
            'currencies' => $byCurrency,
            'by_status' => $byStatus,
            'issued' => $this->between($invoices(), 'issued_at', $range)->count(),
            'overdue' => $this->control()->table('saas_invoices')->whereIn('status', self::OPEN_INVOICES)->where('due_at', '<', now())->count(),
        ];
    }

    /**
     * @param  list<array{key: string, label: string, start: mixed, end: mixed}>  $buckets
     * @return array<string, mixed>
     */
    private function support(DateRange $range, DateRange $previous, array $buckets): array
    {
        $tickets = fn (): Builder => $this->control()->table('support_tickets');

        /** @var array<string, int> $openByStatus */
        $openByStatus = $tickets()->whereIn('status', self::OPEN_TICKETS)->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status')->map(fn (mixed $count): int => (int) $count)->all();

        /** @var array<string, int> $openByPriority */
        $openByPriority = $tickets()->whereIn('status', self::OPEN_TICKETS)->selectRaw('priority, COUNT(*) AS aggregate')->groupBy('priority')->pluck('aggregate', 'priority')->map(fn (mixed $count): int => (int) $count)->all();

        $newSeries = $this->series($tickets(), 'created_at', $range, $buckets);

        $latest = $tickets()
            ->whereIn('support_tickets.status', self::OPEN_TICKETS)
            ->leftJoin('tenants', 'tenants.id', '=', 'support_tickets.tenant_id')
            ->orderByRaw("CASE support_tickets.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderByDesc('support_tickets.last_activity_at')
            ->limit(4)
            ->get(['support_tickets.id', 'support_tickets.uuid', 'support_tickets.reference', 'support_tickets.subject', 'support_tickets.status', 'support_tickets.priority', 'support_tickets.last_activity_at', 'tenants.name as center_name'])
            ->all();

        return [
            'open' => array_sum($openByStatus),
            'open_by_status' => $openByStatus,
            'open_by_priority' => $openByPriority,
            'urgent' => ($openByPriority['urgent'] ?? 0) + ($openByPriority['high'] ?? 0),
            'new' => array_sum($newSeries),
            'new_previous' => $this->between($tickets(), 'created_at', $previous)->count(),
            'new_series' => $newSeries,
            'resolved' => $this->between($tickets(), 'resolved_at', $range)->count(),
            'latest' => $latest,
        ];
    }

    /**
     * @return list<object>
     */
    private function registrations(): array
    {
        return $this->control()->table('registrations')
            ->latest('created_at')
            ->limit(5)
            ->get(['id', 'center_name', 'requested_slug', 'status', 'created_at'])
            ->all();
    }

    /**
     * @return list<object>
     */
    private function activity(): array
    {
        return $this->control()->table('platform_audit_logs')
            ->latest('occurred_at')
            ->limit(6)
            ->get(['id', 'action', 'category', 'severity', 'actor_type', 'actor_label', 'target_type', 'target_label', 'occurred_at'])
            ->all();
    }

    /**
     * Centers a platform operator must act on. Empty in a healthy platform,
     * and then the dashboard shows nothing for it at all.
     *
     * @return list<object>
     */
    private function issues(): array
    {
        return $this->control()->table('tenants')
            ->where(fn (Builder $query) => $query->where('status', 'failed')->orWhere('provisioning_status', 'failed')->orWhere('migration_status', 'failed'))
            ->latest('updated_at')
            ->limit(5)
            ->get(['id', 'name', 'status', 'provisioning_status', 'migration_status', 'updated_at'])
            ->all();
    }

    /**
     * Sums (or counts) `$column` into the range's buckets, in bucket order.
     *
     * @param  list<array{key: string, label: string, start: mixed, end: mixed}>  $buckets
     * @return list<int>
     */
    private function series(Builder $query, string $column, DateRange $range, array $buckets, ?string $sum = null): array
    {
        $aggregate = $sum === null ? 'COUNT(*)' : 'COALESCE(SUM('.$sum.'), 0)';

        /** @var array<string, int|string> $rows */
        $rows = $this->between($query, $column, $range)
            ->selectRaw($range->bucketExpression($column).' AS bucket, '.$aggregate.' AS aggregate')
            ->groupBy('bucket')
            ->pluck('aggregate', 'bucket')
            ->all();

        return array_map(static fn (array $bucket): int => (int) ($rows[$bucket['key']] ?? 0), $buckets);
    }

    /**
     * The plan name as it was SOLD — the subscription's own snapshot, which is
     * stored translatable. Shown in the viewer's language, never as raw JSON.
     */
    private function planName(string $snapshot): string
    {
        $decoded = json_decode($snapshot, true);

        return is_array($decoded) ? TranslatedText::fromArray($decoded)->get() : $snapshot;
    }

    private function between(Builder $query, string $column, DateRange $range): Builder
    {
        return $query->where($column, '>=', $range->startUtc())->where($column, '<', $range->endUtc());
    }

    private function control(): ConnectionInterface
    {
        return DB::connection('control');
    }
}
