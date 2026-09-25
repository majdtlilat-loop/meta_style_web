<?php

declare(strict_types=1);

use App\Kernel\Tenancy\PlatformHosts;
use App\Modules\Booking\Application\AppointmentPresenter;
use App\Modules\Booking\Application\CalendarQuery;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Availability\AvailabilityEngine;
use App\Modules\Booking\Domain\Data\AvailabilityQuery;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Query-count guards
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 6 §§34, 42.
|
| Query COUNTS, not timings: a timing test on a shared machine is a flaky test,
| and the failure mode that actually matters here is structural. Availability
| and the calendar are the two screens whose cost must not scale with the data
| they display.
|
| The thresholds are generous. They are regression guards — "this did not become
| N+1" — not budgets, and a test that fails on a one-query refactor teaches
| people to raise the number rather than look.
|
*/

const PERF_DATE = '2026-10-14';

/**
 * @return list<string>
 */
function recordQueries(callable $work): array
{
    $sql = [];

    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$sql): void {
        $sql[] = $query->sql;
    });

    $work();

    Event::forget(QueryExecuted::class);

    return $sql;
}

function countQueries(callable $work): int
{
    return count(recordQueries($work));
}

/**
 * @param  list<string>  $sql
 */
function queriesTouching(array $sql, string $table): int
{
    return count(array_filter($sql, static fn (string $q): bool => str_contains($q, '`'.$table.'`')));
}

it('answers a day of availability in a fixed number of queries', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        // Twelve stylists, all eligible: a realistic salon, and the shape that
        // turns a per-candidate database check into thousands of round trips.
        for ($i = 0; $i < 11; $i++) {
            $this->seedBookableEmployee($seed['service'], $seed['branch'], 'Stylist '.$i);
        }

        $query = new AvailabilityQuery(
            branchUuid: $seed['branch']->uuid,
            lines: [new BookingLine($seed['service']->uuid)],
            fromDate: PERF_DATE,
            toDate: PERF_DATE,
        );

        $engine = app(AvailabilityEngine::class);
        $now = CarbonImmutable::parse('2026-10-13 06:00:00', 'UTC');

        $slots = $engine->slots($query, publicChannel: false, now: $now);

        $queries = countQueries(function () use ($engine, $query, $now): void {
            app(AvailabilityEngine::class);
            $engine->slots($query, publicChannel: false, now: $now);
        });

        // 32 slots × 12 employees is 384 availability questions. They cost a
        // handful of queries because the schedule, the eligibility set and every
        // conflicting appointment in the range are each loaded once (§34).
        expect(count($slots))->toBeGreaterThan(25)
            ->and($queries)->toBeLessThan(12);
    });
});

it('does not issue more queries as the day fills up', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        for ($i = 0; $i < 5; $i++) {
            $this->seedBookableEmployee($seed['service'], $seed['branch'], 'Stylist '.$i);
        }

        $query = new AvailabilityQuery(
            branchUuid: $seed['branch']->uuid,
            lines: [new BookingLine($seed['service']->uuid)],
            fromDate: PERF_DATE,
            toDate: PERF_DATE,
        );

        $engine = app(AvailabilityEngine::class);
        $now = CarbonImmutable::parse('2026-10-13 06:00:00', 'UTC');

        $empty = recordQueries(fn () => $engine->slots($query, publicChannel: false, now: $now));

        // Fill the morning with real bookings.
        for ($hour = 9; $hour < 12; $hour++) {
            app(BookingEngine::class)->book(
                new BookingRequest(
                    branchUuid: $seed['branch']->uuid,
                    lines: [new BookingLine($seed['service']->uuid)],
                    startsAt: $this->localTime($seed['branch'], PERF_DATE, sprintf('%02d:00', $hour)),
                    customer: CustomerRef::details('Customer '.$hour, '+96475000000'.$hour),
                ),
                BookingActor::staff($this->ownerWithCatalogAccess()),
            )->appointment;
        }

        $fresh = app(AvailabilityEngine::class);

        $busy = recordQueries(fn () => $fresh->slots($query, publicChannel: false, now: $now));

        // THE GUARD: the conflict load is ONE query whatever it returns. If it
        // ever becomes "one query per existing appointment", the busiest center
        // on the platform is the one that discovers it.
        expect(queriesTouching($busy, 'appointment_items'))->toBe(1)
            ->and(queriesTouching($empty, 'appointment_items'))->toBe(1)

            // And the total does not grow with the data. It can legitimately
            // SHRINK: the per-request booking-settings cache is already warm by
            // the second run, which is exactly the behaviour that binding
            // wanted.
            ->and(count($busy))->toBeLessThanOrEqual(count($empty));
    });
});

it('renders a week calendar without a query per appointment', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        for ($i = 0; $i < 8; $i++) {
            app(BookingEngine::class)->book(
                new BookingRequest(
                    branchUuid: $seed['branch']->uuid,
                    lines: [new BookingLine($seed['service']->uuid)],
                    startsAt: $this->localTime($seed['branch'], PERF_DATE, sprintf('%02d:%02d', 9 + intdiv($i, 2), 30 * ($i % 2))),
                    customer: CustomerRef::details('Customer '.$i, '+9647500000'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)),
                ),
                BookingActor::staff($owner),
            )->appointment;
        }

        $presenter = app(AppointmentPresenter::class);

        // One render first. The center's content locales (`default_locale`,
        // `enabled_locales`) are read ONCE per request, by the first
        // TranslatedText::get() in tenant context, and memoised (TenantLocales
        // is scoped). That is a constant per request, not a cost per
        // appointment; left in the first measurement only, it made the two
        // counts differ by exactly those two reads.
        app(CalendarQuery::class)->forRange(PERF_DATE, PERF_DATE, $owner)
            ->each(fn (Appointment $a) => $presenter->summary($a, $owner));

        $one = countQueries(function () use ($owner, $presenter): void {
            $page = app(CalendarQuery::class)->forRange(PERF_DATE, PERF_DATE, $owner);

            // Rendering is part of the measurement: a presenter that lazy-loads
            // is an N+1 the query itself would not show.
            $page->each(fn (Appointment $a) => $presenter->summary($a, $owner));
        });

        expect($one)->toBeLessThan(15);

        // Double the appointments; the query count must not follow.
        for ($i = 8; $i < 16; $i++) {
            app(BookingEngine::class)->book(
                new BookingRequest(
                    branchUuid: $seed['branch']->uuid,
                    lines: [new BookingLine($seed['service']->uuid)],
                    startsAt: $this->localTime($seed['branch'], PERF_DATE, sprintf('%02d:%02d', 9 + intdiv($i, 2), 30 * ($i % 2))),
                    customer: CustomerRef::details('Customer '.$i, '+9647500000'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)),
                ),
                BookingActor::staff($owner),
            )->appointment;
        }

        $two = countQueries(function () use ($owner, $presenter): void {
            $page = app(CalendarQuery::class)->forRange(PERF_DATE, PERF_DATE, $owner);

            $page->each(fn (Appointment $a) => $presenter->summary($a, $owner));
        });

        expect($two)->toBe($one);
    });
});

it('keeps public availability free of anything private', function (): void {
    $center = $this->registerCenter();
    // The guest API lives on the center's own host since Phase 15.
    $slug = $center['registration']->requested_slug;

    $seed = $this->asCenter($center['tenant'], function (): array {
        $seed = $this->seedBookableCenter();
        $this->publishMenu();

        $customer = $this->seedCustomer('Sara Ahmed', '0750 123 4567');

        app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                startsAt: $this->localTime($seed['branch'], PERF_DATE, '10:00'),
                customer: CustomerRef::existing($customer->uuid),
            ),
            BookingActor::staff($this->ownerWithCatalogAccess()),
        )->appointment;

        return $seed;
    });

    $response = $this->getJson(app(PlatformHosts::class)->centerUrl($slug, "/api/v1/menu/{$slug}/availability?").http_build_query([
        'branch' => $seed['branch']->uuid,
        'from' => PERF_DATE,
        'services' => [['service' => $seed['service']->uuid]],
    ]))->assertOk();

    $body = $response->getContent();

    // Availability says WHEN, never WHO. A guest must not be able to read the
    // shop's book — who is coming, or which stylist is occupied — out of the
    // response that tells them 10:00 is taken.
    expect($body)->not->toContain('Sara Ahmed')
        ->and($body)->not->toContain('9647501234567')
        ->and($body)->not->toContain('employee')
        ->and($body)->not->toContain('customer')
        ->and($response->json('data.slots.0'))->toHaveKeys(['starts_at', 'ends_at', 'date', 'time']);
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
