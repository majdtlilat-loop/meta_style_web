<?php

declare(strict_types=1);

namespace App\Livewire\Center\Dashboard;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Platform\Currencies\PlatformCurrencies;
use App\Kernel\Time\DateRange;
use App\View\Delta;
use Illuminate\Support\Facades\Route;

/**
 * Turns the overview's raw figures into what the page draws: KPI cards with
 * their comparison, and the live strip. Charts and the "now" cards are
 * {@see DashboardCards}. No business rule lives here — every number was
 * computed by a module; this only labels, formats and links it, and links
 * only where the viewer may follow.
 */
final class DashboardPresenter
{
    public function __construct(private readonly PlatformCurrencies $currencies) {}

    /**
     * @param  array<string, mixed>  $sections
     * @param  array<string, mixed>  $now
     * @param  array<string, bool>  $gates
     * @return list<array{key: string, label: string, items: list<array<string, mixed>>}>
     */
    public function kpis(array $sections, array $now, array $gates, DateRange $period, User $viewer, string $locale): array
    {
        $compare = __('ui.range.'.$period->comparisonKey());
        $groups = [];

        $operations = [];

        if (isset($sections['bookings'])) {
            $b = $sections['bookings'];
            $calendar = $this->link('center.calendar');
            $operations[] = $this->kpi('calendar', __('manager_dashboard.kpi.bookings'), $this->number($b['total']), Delta::between($b['total'], $b['total_previous']), $compare, null, $calendar, $b['series']);
            $operations[] = $this->kpi('check-circle', __('manager_dashboard.kpi.completed'), $this->number($b['completed']), Delta::between($b['completed'], $b['completed_previous']), $compare, null, $calendar);
            // Fewer is good news for these two: the tone follows the metric.
            $operations[] = $this->kpi('x-circle', __('manager_dashboard.kpi.cancelled'), $this->number($b['cancelled']), Delta::between($b['cancelled'], $b['cancelled_previous'], 'down'), $compare, null, $calendar);
            $operations[] = $this->kpi('user-x', __('manager_dashboard.kpi.no_shows'), $this->number($b['no_show']), Delta::between($b['no_show'], $b['no_show_previous'], 'down'), $compare, null, $calendar);
        }

        if (isset($sections['visits'])) {
            $v = $sections['visits'];
            $board = $this->link('center.board');
            $operations[] = $this->kpi('journey', __('manager_dashboard.kpi.completed_visits'), $this->number($v['completed']), Delta::between($v['completed'], $v['completed_previous']), $compare,
                trans_choice('manager_dashboard.kpi.walk_ins_hint', $v['walk_ins'], ['count' => $this->number($v['walk_ins'])]), $board, $v['series']);
            $operations[] = $this->kpi('scissors', __('manager_dashboard.kpi.services_performed'), $this->number($v['services_performed']), Delta::between($v['services_performed'], $v['services_performed_previous']), $compare, null, $board);
        }

        if (isset($sections['queue'])) {
            $q = $sections['queue'];
            $operations[] = $this->kpi('queue', __('manager_dashboard.kpi.queue_tickets'), $this->number($q['tickets']), Delta::between($q['tickets'], $q['tickets_previous'], 'neutral'), $compare,
                $q['first_call_seconds'] !== null ? __('manager_dashboard.kpi.first_call_hint', ['minutes' => $this->minutes((int) $q['first_call_seconds'])]) : null,
                $this->link('center.queue'), $q['series']);
        }

        if ($operations !== []) {
            $groups[] = ['key' => 'operations', 'label' => __('manager_dashboard.sections.operations'), 'items' => $operations];
        }

        $commerce = [];

        if (isset($sections['sales'])) {
            $s = $sections['sales'];
            $sales = $this->link('center.sales');
            $commerce[] = $this->kpi('sales', __('manager_dashboard.kpi.billed'), $this->money($s['billed'], $s['currency']), Delta::between($s['billed'], $s['billed_previous'], 'up', money: true), $compare,
                $this->otherCurrencies($s['other_currencies'], 'billed') ?? trans_choice('manager_dashboard.kpi.invoices_hint', $s['invoices'], ['count' => $this->number($s['invoices'])]),
                $sales, $s['series']);
            $commerce[] = $this->kpi('receipt', __('manager_dashboard.kpi.average'), $s['average'] !== null ? $this->money($s['average'], $s['currency']) : '—',
                $s['average'] !== null && $s['average_previous'] !== null ? Delta::between($s['average'], $s['average_previous'], 'up', money: true) : null,
                $compare, $s['average'] === null ? __('manager_dashboard.kpi.no_invoices') : null, $sales);
        }

        if (isset($sections['payments'])) {
            $p = $sections['payments'];
            $commerce[] = $this->kpi('wallet', __('manager_dashboard.kpi.collected'), $this->money($p['net'], $p['currency']), Delta::between($p['net'], $p['net_previous'], 'up', money: true), $compare,
                $this->otherCurrencies($p['other_currencies'], 'net') ?? ($p['refunded'] > 0 ? __('manager_dashboard.kpi.refunded_hint', ['amount' => $this->money($p['refunded'], $p['currency'])]) : null),
                $gates['finance'] ? $this->link('center.finance') : null, $p['series']);
        }

        if (isset($sections['customers'])) {
            $c = $sections['customers'];
            $customers = $viewer->hasPermission(Permission::CustomerView) ? $this->link('center.customers') : null;
            $commerce[] = $this->kpi('user-plus', __('manager_dashboard.kpi.new_customers'), $this->number($c['new']), Delta::between($c['new'], $c['new_previous']), $compare, null, $customers, $c['series_new']);
            $commerce[] = $this->kpi('users', __('manager_dashboard.kpi.returning_customers'), $this->number($c['returning']), Delta::between($c['returning'], $c['returning_previous']), $compare,
                trans_choice('manager_dashboard.kpi.served_hint', $c['served'], ['count' => $this->number($c['served'])]), $customers);
        }

        if ($commerce !== []) {
            $groups[] = ['key' => 'commerce', 'label' => __('manager_dashboard.sections.commerce'), 'items' => $commerce];
        }

        $benefits = [];
        $b = $sections['benefits'] ?? [];

        if (isset($b['loyalty'])) {
            $link = $this->link('center.loyalty');
            $benefits[] = $this->kpi('star', __('manager_dashboard.kpi.points_earned'), $this->number($b['loyalty']['earned']), Delta::between($b['loyalty']['earned'], $b['loyalty']['earned_previous'], 'neutral'), $compare, null, $link);
            $benefits[] = $this->kpi('gift', __('manager_dashboard.kpi.points_spent'), $this->number($b['loyalty']['spent']), Delta::between($b['loyalty']['spent'], $b['loyalty']['spent_previous'], 'neutral'), $compare, null, $link);
        }

        if (isset($b['packages'])) {
            $benefits[] = $this->kpi('packages', __('manager_dashboard.kpi.packages_sold'), $this->number($b['packages']['sold']), Delta::between($b['packages']['sold'], $b['packages']['sold_previous']), $compare,
                trans_choice('manager_dashboard.kpi.sessions_hint', $b['packages']['redeemed'], ['count' => $this->number($b['packages']['redeemed'])]), $this->link('center.packages'));
        }

        if (isset($b['memberships'])) {
            $benefits[] = $this->kpi('memberships', __('manager_dashboard.kpi.memberships_sold'), $this->number($b['memberships']['sold']), Delta::between($b['memberships']['sold'], $b['memberships']['sold_previous']), $compare,
                trans_choice('manager_dashboard.kpi.uses_hint', $b['memberships']['uses'], ['count' => $this->number($b['memberships']['uses'])]), $this->link('center.memberships'));
        }

        if ($benefits !== []) {
            $groups[] = ['key' => 'benefits', 'label' => __('manager_dashboard.sections.benefits'), 'items' => $benefits];
        }

        return $groups;
    }

    /**
     * The "right now" strip — never affected by the date range.
     *
     * @param  array<string, mixed>  $now
     * @return list<array{label: string, value: string, hint: string|null, icon: string, tone: string|null, href: string|null}>
     */
    public function live(array $now): array
    {
        $items = [];

        if (is_array($now['today'] ?? null)) {
            $today = $now['today'];
            $items[] = $this->stat('calendar', __('manager_dashboard.live.bookings_today'), $this->number($today['total']),
                trans_choice('manager_dashboard.live.still_to_come', $today['remaining'], ['count' => $this->number($today['remaining'])]), null, $this->link('center.calendar'));
        }

        if (is_array($now['queue'] ?? null)) {
            $queue = $now['queue'];
            $waiting = (int) ($queue['states']['waiting'] ?? 0);
            $items[] = $this->stat('queue', __('manager_dashboard.live.waiting'), $this->number($waiting),
                $queue['longest_wait_minutes'] !== null ? __('manager_dashboard.live.longest_wait', ['minutes' => $this->number($queue['longest_wait_minutes'])]) : __('manager_dashboard.live.nobody_waiting'),
                $waiting > 0 ? 'warning' : null, $this->link('center.queue'));
        }

        if (is_array($now['floor'] ?? null)) {
            $floor = $now['floor'];
            $items[] = $this->stat('journey', __('manager_dashboard.live.in_progress'), $this->number($floor['active']),
                trans_choice('manager_dashboard.live.in_service', $floor['in_service'], ['count' => $this->number($floor['in_service'])]), $floor['active'] > 0 ? 'info' : null, $this->link('center.board'));
        }

        if (($now['team'] ?? null) !== null) {
            $booked = is_array($now['team_booked'] ?? null) ? count($now['team_booked']) : null;
            $items[] = $this->stat('users', $booked !== null ? __('manager_dashboard.live.team_booked') : __('manager_dashboard.live.team_active'),
                $booked !== null ? $this->number($booked) : $this->number((int) $now['team']),
                $booked !== null ? trans_choice('manager_dashboard.live.of_active', (int) $now['team'], ['count' => $this->number((int) $now['team'])]) : null,
                null, $this->link('center.staff'));
        }

        return $items;
    }

    public function money(int $minor, string $currency): string
    {
        return $this->currencies->format($minor, strtoupper($currency), app()->getLocale());
    }

    public function number(int|float $value): string
    {
        return number_format($value);
    }

    /** Whole minutes, at least one when there was any wait at all. */
    public function minutes(int $seconds): string
    {
        return number_format($seconds > 0 ? max(1, (int) round($seconds / 60)) : 0);
    }

    public function link(string $route): ?string
    {
        return Route::has($route) ? route($route) : null;
    }

    /**
     * Another currency is DISCLOSED, never added in and never dropped.
     *
     * @param  list<array<string, mixed>>  $others
     */
    private function otherCurrencies(array $others, string $field): ?string
    {
        if ($others === []) {
            return null;
        }

        $parts = array_map(fn (array $row): string => $this->money((int) $row[$field], (string) $row['currency']), $others);

        return __('manager_dashboard.kpi.other_currencies', ['amounts' => implode(' · ', $parts)]);
    }

    /**
     * @param  list<int>  $trend
     * @return array<string, mixed>
     */
    private function kpi(string $icon, string $label, string $value, ?Delta $delta, string $comparison, ?string $hint, ?string $href, array $trend = []): array
    {
        return [
            'icon' => $icon,
            'label' => $label,
            'value' => $value,
            'delta' => $delta,
            'comparison' => $delta !== null ? $comparison : null,
            'hint' => $hint,
            'href' => $href,
            // A single point is not a trend.
            'trend' => count($trend) > 1 && array_sum($trend) > 0 ? $trend : [],
        ];
    }

    /**
     * @return array{label: string, value: string, hint: string|null, icon: string, tone: string|null, href: string|null}
     */
    private function stat(string $icon, string $label, string $value, ?string $hint, ?string $tone, ?string $href): array
    {
        return ['label' => $label, 'value' => $value, 'hint' => $hint, 'icon' => $icon, 'tone' => $tone, 'href' => $href];
    }
}
