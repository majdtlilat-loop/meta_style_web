<?php

declare(strict_types=1);

use App\View\AdvancedReports\AdvancedPeriod;
use App\View\AdvancedReports\ComparePresenter;
use App\View\AdvancedReports\LibraryPresenter;
use App\View\AdvancedReports\Metrics;
use App\View\AdvancedReports\Series;
use App\View\AdvancedReports\WorkspacePresenter;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Advanced Reports — honest presentation of the reader facts
|--------------------------------------------------------------------------
|
| Pure presenter checks on hand-made reader summaries (the SQL is covered by
| the workspace, comparison and isolation tests): rates share the outcome
| denominator, an entity is matched to its comparison value by id (never by
| name), a loyalty adjustment in and out are never netted, a missing rate is
| never drawn as a 0 bar, money in another currency is disclosed and never
| added, and a detail table sorts numbers as numbers.
|
*/

function advancedPresentationPeriod(): AdvancedPeriod
{
    // Last 7 days to Thursday 24 September 2026, compared with the 7 before.
    return AdvancedPeriod::resolve('last_7_days', null, null, 'previous', 'Asia/Baghdad', CarbonImmutable::parse('2026-09-24 10:00', 'Asia/Baghdad'));
}

/**
 * A visits summary as the journey reader returns it.
 *
 * @param  array<int, array{name: string, completed: int, minutes: int}>  $employees
 * @param  array<int, array{name: string, completed: int, minutes: int}>  $services
 * @return array<string, mixed>
 */
function advancedVisitFacts(array $employees, array $services): array
{
    $completed = array_sum(array_column($employees, 'completed'));

    return [
        'total' => $completed,
        'status' => ['completed' => $completed],
        'sources' => ['walk_in' => 1],
        'daily' => ['2026-09-20' => $completed],
        'hourly' => ['2026-09-20 10' => $completed],
        'stages' => [
            'status' => ['completed' => $completed],
            'services' => $services,
            'employees' => $employees,
            'completed' => $completed,
            'minutes' => array_sum(array_column($employees, 'minutes')),
            'daily' => ['2026-09-20' => $completed],
            'hourly' => ['2026-09-20 10' => $completed],
        ],
    ];
}

/**
 * A bookings summary as the booking reader returns it.
 *
 * @param  array<string, int>  $status
 * @param  array<int, array<string, mixed>>  $services
 * @param  array<int, array<string, mixed>>  $employees
 * @return array<string, mixed>
 */
function advancedBookingFacts(int $total, array $status, array $services = [], array $employees = []): array
{
    return [
        'total' => $total,
        'created' => $total,
        'status' => $status,
        'sources' => $total > 0 ? ['staff' => $total] : [],
        'daily' => $total > 0 ? ['2026-09-21' => $total] : [],
        'hourly' => $total > 0 ? ['2026-09-21 11' => $total] : [],
        'daily_status' => $total > 0 ? ['2026-09-21' => $status] : [],
        'hourly_status' => $total > 0 ? ['2026-09-21 11' => $status] : [],
        'services' => $services,
        'employees' => $employees,
    ];
}

it('rates booking outcomes over the bookings that reached one, never over open bookings', function (): void {
    $values = Metrics::values(['bookings' => advancedBookingFacts(6, ['completed' => 2, 'cancelled' => 1, 'no_show' => 1, 'booked' => 2])], 'IQD');

    // Two bookings still ahead do not dilute the rates: 2 / 1 / 1 of 4 outcomes.
    expect($values['bookings'])->toBe(6)
        ->and($values['completion_rate'])->toBe(50.0)
        ->and($values['cancellation_rate'])->toBe(25.0)
        ->and($values['no_show_rate'])->toBe(25.0);

    $open = Metrics::values(['bookings' => advancedBookingFacts(3, ['booked' => 2, 'confirmed' => 1])], 'IQD');

    // Nothing has an outcome yet: no rate at all, never 0 %.
    expect($open['completion_rate'])->toBeNull()
        ->and($open['cancellation_rate'])->toBeNull()
        ->and($open['no_show_rate'])->toBeNull();
});

it('matches employees and services to their comparison value by id, and never nets loyalty in and out', function (): void {
    $current = [
        'bookings' => advancedBookingFacts(5, ['completed' => 4, 'cancelled' => 1], [3 => ['name' => 'Cut', 'category' => null, 'total' => 4, 'status' => []]], [7 => ['name' => 'Sara', 'total' => 4, 'status' => []]]),
        // Two different employees who share a name.
        'visits' => advancedVisitFacts([7 => ['name' => 'Sara', 'completed' => 3, 'minutes' => 90], 9 => ['name' => 'Sara', 'completed' => 1, 'minutes' => 30]], [3 => ['name' => 'Cut', 'completed' => 4, 'minutes' => 120]]),
        'customers' => ['customers' => 3, 'new' => 2, 'returning' => 1, 'visits' => 4, 'visit_frequency' => 1.3, 'daily_new' => ['2026-09-20' => 2], 'hourly_new' => [], 'frequency' => [1 => 2, 2 => 1, 3 => 0, 4 => 0, 5 => 0], 'top' => []],
        'queue' => ['total' => 4, 'closed' => 4, 'states' => ['completed' => 3, 'cancelled' => 1], 'sources' => ['walk_in' => 3, 'appointment' => 1], 'average_first_call_seconds' => 120, 'average_service_start_seconds' => 300, 'daily' => [['date' => '2026-09-20', 'tickets' => 4]], 'hourly' => []],
        'benefits' => ['loyalty' => [
            ['kind' => 'adjustment', 'direction' => 'in', 'points' => 50],
            ['kind' => 'adjustment', 'direction' => 'out', 'points' => 20],
            ['kind' => 'earn', 'direction' => 'in', 'points' => 100],
        ], 'packages' => [], 'memberships' => [], 'activations' => []],
    ];
    $previous = [
        'bookings' => advancedBookingFacts(2, ['completed' => 2], [3 => ['name' => 'Cut', 'category' => null, 'total' => 2, 'status' => []]]),
        'visits' => advancedVisitFacts([7 => ['name' => 'Sara', 'completed' => 1, 'minutes' => 30]], [3 => ['name' => 'Cut', 'completed' => 1, 'minutes' => 30]]),
        'customers' => ['customers' => 1, 'new' => 1, 'returning' => 0, 'visits' => 1, 'visit_frequency' => 1.0, 'daily_new' => [], 'hourly_new' => [], 'frequency' => [1 => 1, 2 => 0, 3 => 0, 4 => 0, 5 => 0], 'top' => []],
        'queue' => ['total' => 0, 'closed' => 0, 'states' => [], 'sources' => [], 'average_first_call_seconds' => null, 'average_service_start_seconds' => null, 'daily' => [], 'hourly' => []],
        'benefits' => ['loyalty' => [], 'packages' => [], 'memberships' => [], 'activations' => []],
    ];
    $sections = [];
    foreach ($current as $key => $facts) {
        $sections[$key] = ['current' => $facts, 'previous' => $previous[$key]];
    }

    $page = (new WorkspacePresenter(advancedPresentationPeriod(), 'en'))->present(['sections' => $sections, 'as_of' => '2026-09-24T07:00:00+00:00']);
    $blocks = collect($page['sections'])->keyBy('key');
    $card = static fn (string $section, string $title) => collect($blocks[$section]['cards'])->firstWhere('title', __('manager_advanced.cards.'.$title));

    // The employee table: the first Sara (id 7) had 1 before; the second (id 9) had none.
    $table = collect($blocks['employees']['cards'])->firstWhere('type', 'table');
    expect(array_column($table['rows'], 2))->toBe(['1', '—'])
        ->and($table['sort'][0][1])->toBe(3)
        ->and($table['sort'][1][3])->toBeNull();

    // Bookings by status over time: one stacked part per status that occurred.
    $stacked = $card('bookings', 'status_over_time');
    expect($stacked['mode'])->toBe('status')
        ->and(array_column($stacked['series'], 'status'))->toBe(['completed', 'cancelled'])
        ->and(array_sum($stacked['series'][0]['values']))->toBe(4);

    // Booked (reserved) services against the comparison period, by id.
    expect($card('bookings', 'booked_services')['items'][0])->toMatchArray(['label' => 'Cut', 'value' => 4, 'previous' => 2])
        ->and($card('bookings', 'booked_employees')['items'][0])->toMatchArray(['label' => 'Sara', 'value' => 4, 'previous' => 0]);

    // Loyalty points per kind AND direction: +50 and −20 are two bars, not +30.
    $loyalty = $card('retention', 'loyalty_points');
    expect($loyalty['groups'])->toBe([
        __('manager_advanced.loyalty.adjustment').' · '.__('manager_advanced.direction.in'),
        __('manager_advanced.loyalty.adjustment').' · '.__('manager_advanced.direction.out'),
        __('manager_advanced.loyalty.earn'),
    ])->and($loyalty['series'][0]['values'])->toBe([50, 20, 100]);

    // Customer mix, visit frequency and the queue's sources and outcomes are real parts.
    expect(array_column($card('customers', 'customer_mix')['items'], 'value'))->toBe([2, 1])
        ->and($card('customers', 'visit_frequency')['series'][0]['values'])->toBe([2, 1, 0, 0, 0])
        ->and($card('customers', 'visit_frequency')['series'][1]['values'])->toBe([1, 0, 0, 0, 0])
        ->and(array_column($card('queue', 'ticket_sources')['items'], 'label'))->toBe([__('manager_advanced.queue_source.walk_in'), __('manager_advanced.queue_source.appointment')])
        ->and($card('queue', 'ticket_outcomes')['mode'])->toBe('status');

    // Rates on the outcome denominator (4 completed, 1 cancelled of 5 outcomes).
    $kpis = collect($page['kpis'])->keyBy('key');
    expect($kpis['cancellation_rate']['current'])->toBe(20.0)
        ->and($kpis['cancellation_rate']['previous'])->toBe(0.0)
        ->and($kpis['cancellation_rate']['higher'])->toBeFalse();
});

it('never draws a missing rate as a zero bar, and discloses money in another currency without adding it', function (): void {
    $entity = static fn (string $key, int $total, array $status, array $billed): array => [
        'key' => $key,
        'name' => $key,
        'facts' => [
            'bookings' => advancedBookingFacts($total, $status),
            'sales' => ['invoices' => 1, 'voided' => 0, 'billed' => $billed, 'billed_invoices' => array_map(static fn (): int => 1, $billed), 'daily' => [], 'hourly' => [], 'items' => [], 'categories' => []],
            'payments' => ['collected' => [], 'refunded' => [], 'net' => [], 'methods' => [], 'daily' => [], 'hourly' => []],
        ],
    ];

    $data = (new ComparePresenter(advancedPresentationPeriod(), 'en'))->entities('branches', [
        $entity('Downtown', 4, ['completed' => 3, 'cancelled' => 1], ['IQD' => 50000]),
        // Only open bookings: its rates do not exist.
        $entity('Mall', 2, ['booked' => 2], ['IQD' => 20000, 'USD' => 1250]),
    ], []);

    $titles = array_column($data['cards'], 'title');
    $rates = collect($data['table'])->firstWhere('label', Metrics::label('completion_rate'));

    expect($titles)->toContain(__('manager_advanced.compare.volume'))
        ->and(in_array(__('manager_advanced.compare.outcomes'), $titles, true))->toBeFalse('a missing rate is not a 0 % bar')
        ->and($rates['cells'][1]['value'])->toBe('—')
        ->and($data['currency'])->toBe('IQD')
        ->and($data['notes'])->toBe([__('manager_advanced.values.other_currencies', ['amounts' => Series::otherCurrencies(['USD' => 1250], 'IQD')[0]])]);

    $billed = collect($data['table'])->firstWhere('label', Metrics::label('billed'));
    expect($billed['cells'][1]['value'])->toContain('20,000')
        ->and($billed['cells'][1]['value'])->not->toContain('21');
});

it('sorts a catalog report detail table by raw numbers, and judges its KPIs by the direction of each metric', function (): void {
    $report = (new LibraryPresenter('en'))->present([
        'code' => 'employee_analysis',
        'title' => 'Employee analysis',
        'description' => '',
        'kpis' => [
            ['key' => 'walk_ins', 'label' => 'Walk-ins', 'value' => 4, 'format' => 'number'],
            ['key' => 'cancelled', 'label' => 'Cancelled', 'value' => 1, 'format' => 'number'],
            ['key' => 'completed_services', 'label' => 'Completed services', 'value' => 15, 'format' => 'number'],
        ],
        'series' => [],
        'rows' => [
            ['name' => 'Sara', 'completed' => 3, 'previous_completed' => 1, 'completed_change_percent' => 200.0, 'minutes' => 90, 'previous_minutes' => 30],
            ['name' => 'Ali', 'completed' => 12, 'previous_completed' => 0, 'completed_change_percent' => null, 'minutes' => 300, 'previous_minutes' => 0],
        ],
        'glossary' => [],
        'coverage' => [],
        'currency' => null,
        'as_of' => '2026-09-24T07:00:00+00:00',
        'unavailable' => [],
        'unavailable_keys' => [],
    ]);

    $table = $report['table'];
    $completed = array_search('completed', array_column($table['columns'], 'key'), true);
    $change = array_search('completed_change_percent', array_column($table['columns'], 'key'), true);

    expect($table['rows'][0][0])->toBe('Sara')
        ->and($table['sort'][0][0])->toBe('Sara')
        // 12 sorts above 3 as a number (as text "3" > "12").
        ->and($table['sort'][1][$completed])->toBe(12)
        ->and($table['sort'][0][$completed])->toBe(3)
        ->and($table['rows'][0][$change])->toBe('+200%')
        // No percentage from a zero base: shown as a dash, sorted last.
        ->and($table['rows'][1][$change])->toBe('—')
        ->and($table['sort'][1][$change])->toBeNull()
        ->and($table['page_size'])->toBe(15)
        // More walk-ins is no judgement, more cancellations is bad news.
        ->and(array_column($report['kpis'], 'higher'))->toBe([null, false, true]);
});
