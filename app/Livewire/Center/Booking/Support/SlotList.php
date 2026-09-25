<?php

declare(strict_types=1);

namespace App\Livewire\Center\Booking\Support;

use App\Modules\Booking\Application\BookingOptions;
use App\Modules\Booking\Domain\Data\AvailabilitySlot;

/**
 * The engine's slots as the desk shows them: the instant to send back, the
 * branch-local time, and who the engine would book — the slot carries the
 * employee ids it assigned, so "with Ahmed" is the person who would actually
 * be booked, not a guess (docs/15-BOOKING.md §8).
 */
final class SlotList
{
    public function __construct(private readonly BookingOptions $options) {}

    /**
     * @param  list<AvailabilitySlot>  $slots
     * @param  string|null  $current  the instant a booking already holds, when moving it
     * @return list<array{starts_at: string, time: string, staff: string, current: bool}>
     */
    public function present(array $slots, ?string $current = null): array
    {
        $ids = [];

        foreach ($slots as $slot) {
            foreach ($slot->assignments as $id) {
                $ids[] = (int) $id;
            }
        }

        $names = $this->options->employeeNames($ids);

        return array_map(static function (AvailabilitySlot $slot) use ($names, $current): array {
            $staff = [];

            foreach ($slot->assignments as $id) {
                if ($id !== null && isset($names[$id])) {
                    $staff[] = $names[$id];
                }
            }

            $startsAt = $slot->startsAt->toIso8601String();

            return [
                'starts_at' => $startsAt,
                'time' => $slot->localTime,
                'staff' => implode(', ', array_values(array_unique($staff))),
                'current' => $current !== null && $startsAt === $current,
            ];
        }, $slots);
    }
}
