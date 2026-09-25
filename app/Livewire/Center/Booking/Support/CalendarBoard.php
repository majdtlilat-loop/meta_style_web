<?php

declare(strict_types=1);

namespace App\Livewire\Center\Booking\Support;

use Carbon\CarbonImmutable;

/**
 * Lays presented appointments out for the calendar — geometry, not rules.
 *
 * Input is ONLY what AppointmentPresenter already produced (masked, scoped,
 * branch-local), so this class can neither see more than the viewer may nor
 * decide anything about a booking. Output is plain arrays of numbers and
 * labels; the template positions blocks with CSS custom properties and logical
 * properties, so the same geometry mirrors itself in Arabic and Kurdish.
 *
 * ## Day: one lane per person (or per room)
 *
 * Every booked SERVICE is a block in the lane of whoever does it — a visit with
 * a haircut by Ahmed and a colour by Sara shows in both lanes, because that is
 * what each of them is doing. In the room view a service sits in every room it
 * reserved. Positions are minutes from the top of the axis; overlapping blocks
 * in one lane (an unassigned lane, a cancelled booking under its replacement)
 * share the width in tracks instead of covering each other.
 *
 * The axis runs from the branch's opening to its closing, widened to the hour
 * and to any booking outside it, and the closed parts are shaded.
 */
final class CalendarBoard
{
    private const DEFAULT_OPEN = 9 * 60;

    private const DEFAULT_CLOSE = 18 * 60;

    public const UNASSIGNED = '_unassigned';

    /**
     * @param  list<array<string, mixed>>  $appointments  presenter summaries
     * @param  list<array{uuid: string, name: string, active?: bool}>  $lanes
     * @param  list<array{from: int, to: int}>  $open  minutes from local midnight
     * @param  'team'|'rooms'  $mode
     * @return array{lanes: list<array{key: string, name: string, muted: bool, count: int}>, blocks: array<string, list<array<string, mixed>>>, hours: list<array{label: string, offset: int}>, closed: list<array{offset: int, length: int}>, minutes: int, now: int|null, empty: bool}
     */
    public static function day(
        array $appointments,
        array $lanes,
        array $open,
        string $date,
        string $mode,
        bool $onlyBusyLanes,
        ?int $nowMinute,
    ): array {
        $blocks = [];
        $extraLanes = [];

        foreach ($appointments as $appointment) {
            foreach (self::blocksOf($appointment, $date, $mode) as [$laneKey, $laneName, $block]) {
                $blocks[$laneKey][] = $block;

                if ($laneName !== null) {
                    $extraLanes[$laneKey] ??= $laneName;
                }
            }
        }

        $laneRows = [];
        $known = [];

        foreach ($lanes as $lane) {
            $known[$lane['uuid']] = true;
            $count = count($blocks[$lane['uuid']] ?? []);
            $inactive = array_key_exists('active', $lane) && $lane['active'] === false;

            // Inactive people and, for someone who sees only their own work,
            // everybody else: a lane only when it has something in it.
            if (($inactive || $onlyBusyLanes) && $count === 0) {
                continue;
            }

            $laneRows[] = ['key' => $lane['uuid'], 'name' => $lane['name'], 'muted' => $inactive, 'count' => $count];
        }

        foreach ($extraLanes as $key => $name) {
            if (! isset($known[$key]) && $key !== self::UNASSIGNED) {
                $laneRows[] = ['key' => $key, 'name' => $name, 'muted' => true, 'count' => count($blocks[$key] ?? [])];
            }
        }

        if (isset($blocks[self::UNASSIGNED])) {
            $laneRows[] = [
                'key' => self::UNASSIGNED,
                'name' => __('manager_booking.calendar.unassigned'),
                'muted' => true,
                'count' => count($blocks[self::UNASSIGNED]),
            ];
        }

        [$start, $end] = self::axis($open, $blocks);

        $placed = [];

        foreach ($laneRows as $lane) {
            $placed[$lane['key']] = self::tracks(array_map(
                static function (array $block) use ($start): array {
                    $block['offset'] = $block['from'] - $start;

                    return $block;
                },
                $blocks[$lane['key']] ?? [],
            ));
        }

        $hours = [];

        for ($minute = $start; $minute < $end; $minute += 60) {
            $hours[] = ['label' => sprintf('%02d:00', intdiv($minute, 60) % 24), 'offset' => $minute - $start];
        }

        return [
            'lanes' => $laneRows,
            'blocks' => $placed,
            'hours' => $hours,
            'closed' => self::closed($open, $start, $end),
            'minutes' => $end - $start,
            'now' => $nowMinute !== null && $nowMinute >= $start && $nowMinute <= $end ? $nowMinute - $start : null,
            'empty' => $blocks === [],
        ];
    }

    /**
     * @param  list<string>  $days
     * @param  list<array<string, mixed>>  $appointments  decorated rows
     * @return list<array{date: string, weekday: string, label: string, today: bool, appointments: list<array<string, mixed>>}>
     */
    public static function week(array $days, array $appointments, string $today): array
    {
        $byDay = [];

        foreach ($appointments as $appointment) {
            $byDay[(string) $appointment['local_date']][] = $appointment;
        }

        return array_map(static fn (string $day): array => [
            'date' => $day,
            'weekday' => BookingFormat::weekday($day),
            'label' => BookingFormat::dayMonth($day),
            'today' => $day === $today,
            'appointments' => $byDay[$day] ?? [],
        ], $days);
    }

    /**
     * One appointment, ready for a list row or a card.
     *
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    public static function row(array $summary): array
    {
        /** @var list<array<string, mixed>> $items */
        $items = $summary['items'] ?? [];

        $services = [];
        $staff = [];
        $inactive = false;
        $unassigned = false;

        foreach ($items as $item) {
            $services[] = trim((string) $item['service'].(isset($item['variation']) ? ' · '.$item['variation'] : ''));

            if (is_array($item['employee'] ?? null)) {
                $staff[] = (string) $item['employee']['name'];
                $inactive = $inactive || (($item['employee']['active'] ?? true) === false);
            } else {
                $unassigned = true;
            }
        }

        $status = (string) $summary['status'];
        /** @var array<string, mixed>|null $customer */
        $customer = $summary['customer'] ?? null;

        return $summary + [
            'customer_name' => is_array($customer) ? (string) $customer['name'] : __('manager_booking.calendar.no_customer'),
            'contact_phone' => is_array($customer) ? BookingFormat::phone($customer['phone'] ?? null, ($customer['contact_masked'] ?? false) === true) : null,
            'date_label' => BookingFormat::dateShort((string) $summary['local_date']),
            'time_label' => $summary['local_start'].'–'.$summary['local_end'],
            'services_label' => implode(', ', $services),
            'staff_label' => implode(', ', array_values(array_unique($staff))),
            'needs_staff' => $inactive,
            'unassigned' => $unassigned,
            'status_label' => BookingFormat::status($status),
            'tone' => BookingFormat::tone($status),
            'muted' => in_array($status, ['cancelled', 'no_show'], true),
            'duration_label' => BookingFormat::duration((int) $summary['duration_minutes']),
        ];
    }

    /**
     * @param  array<string, mixed>  $appointment
     * @param  'team'|'rooms'  $mode
     * @return list<array{0: string, 1: string|null, 2: array<string, mixed>}>
     */
    private static function blocksOf(array $appointment, string $date, string $mode): array
    {
        $timezone = (string) $appointment['timezone'];
        $midnight = CarbonImmutable::parse($date.' 00:00:00', $timezone);
        $status = (string) $appointment['status'];
        /** @var array<string, mixed>|null $customer */
        $customer = $appointment['customer'] ?? null;
        $out = [];

        /** @var list<array<string, mixed>> $items */
        $items = $appointment['items'] ?? [];
        $count = count($items);

        foreach ($items as $item) {
            $starts = CarbonImmutable::parse((string) $item['starts_at'])->setTimezone($timezone);
            $from = (int) $midnight->diffInMinutes($starts, false);
            $length = max(5, (int) $item['duration_minutes']);

            $block = [
                'appointment' => (string) $appointment['uuid'],
                'key' => (string) $appointment['uuid'].'-'.(string) $item['uuid'],
                'from' => $from,
                'length' => $length,
                'time' => $starts->format('H:i').'–'.$starts->addMinutes((int) $item['duration_minutes'])->format('H:i'),
                'title' => is_array($customer) ? (string) $customer['name'] : __('manager_booking.calendar.no_customer'),
                'service' => (string) $item['service'].(isset($item['variation']) ? ' · '.$item['variation'] : ''),
                'more' => $count > 1 ? $count - 1 : 0,
                'status' => $status,
                'status_label' => BookingFormat::status($status),
                'tone' => BookingFormat::tone($status),
                'muted' => in_array($status, ['cancelled', 'no_show'], true),
                'staff' => is_array($item['employee'] ?? null) ? (string) $item['employee']['name'] : null,
            ];

            if ($mode === 'rooms') {
                /** @var list<array<string, mixed>> $resources */
                $resources = $item['resources'] ?? [];

                foreach ($resources as $resource) {
                    if (is_string($resource['uuid'] ?? null)) {
                        $out[] = [$resource['uuid'], (string) $resource['name'], $block];
                    }
                }

                continue;
            }

            $employee = $item['employee'] ?? null;

            $out[] = is_array($employee)
                ? [(string) $employee['uuid'], (string) $employee['name'], $block]
                : [self::UNASSIGNED, null, $block];
        }

        return $out;
    }

    /**
     * The axis: opening to closing, widened to whole hours and to any booking
     * outside them; a plain working day when the branch is closed and empty.
     *
     * @param  list<array{from: int, to: int}>  $open
     * @param  array<string, list<array<string, mixed>>>  $blocks
     * @return array{0: int, 1: int}
     */
    private static function axis(array $open, array $blocks): array
    {
        $starts = array_map(static fn (array $o): int => $o['from'], $open);
        $ends = array_map(static fn (array $o): int => $o['to'], $open);

        foreach ($blocks as $lane) {
            foreach ($lane as $block) {
                $starts[] = (int) $block['from'];
                $ends[] = (int) $block['from'] + (int) $block['length'];
            }
        }

        if ($starts === []) {
            return [self::DEFAULT_OPEN, self::DEFAULT_CLOSE];
        }

        $start = max(0, intdiv(max(0, min($starts)), 60) * 60);
        $end = min(24 * 60, (int) ceil(max($ends) / 60) * 60);

        return $end - $start < 60 ? [$start, min(24 * 60, $start + 60)] : [$start, $end];
    }

    /**
     * The closed stretches of the axis, for shading.
     *
     * @param  list<array{from: int, to: int}>  $open
     * @return list<array{offset: int, length: int}>
     */
    private static function closed(array $open, int $start, int $end): array
    {
        usort($open, static fn (array $a, array $b): int => $a['from'] <=> $b['from']);

        $closed = [];
        $cursor = $start;

        foreach ($open as $interval) {
            $from = max($start, min($end, $interval['from']));
            $to = max($start, min($end, $interval['to']));

            if ($from > $cursor) {
                $closed[] = ['offset' => $cursor - $start, 'length' => $from - $cursor];
            }

            $cursor = max($cursor, $to);
        }

        if ($cursor < $end) {
            $closed[] = ['offset' => $cursor - $start, 'length' => $end - $cursor];
        }

        return $closed;
    }

    /**
     * Side-by-side tracks for overlapping blocks within one lane.
     *
     * Greedy interval colouring, cluster by cluster: blocks that overlap
     * (directly or through a chain) share one width, split into as many tracks
     * as the busiest moment of the cluster needs.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private static function tracks(array $blocks): array
    {
        usort($blocks, static fn (array $a, array $b): int => [$a['offset'], -$a['length']] <=> [$b['offset'], -$b['length']]);

        // First the clusters, then the tracks inside each.
        $clusters = [];
        $clusterEnd = null;

        foreach ($blocks as $block) {
            $from = (int) $block['offset'];
            $to = $from + (int) $block['length'];

            if ($clusterEnd === null || $from >= $clusterEnd) {
                $clusters[] = [];
                $clusterEnd = $to;
            }

            $clusters[array_key_last($clusters)][] = $block;
            $clusterEnd = max($clusterEnd, $to);
        }

        $placed = [];

        foreach ($clusters as $cluster) {
            $trackEnds = [];
            $inCluster = [];

            foreach ($cluster as $block) {
                $from = (int) $block['offset'];
                $track = null;

                foreach ($trackEnds as $index => $trackEnd) {
                    if ($trackEnd <= $from) {
                        $track = $index;

                        break;
                    }
                }

                $track ??= count($trackEnds);
                $trackEnds[$track] = $from + (int) $block['length'];
                $block['track'] = $track;
                $inCluster[] = $block;
            }

            foreach ($inCluster as $block) {
                $block['tracks'] = max(1, count($trackEnds));
                $placed[] = $block;
            }
        }

        return $placed;
    }
}
