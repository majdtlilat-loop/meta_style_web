<?php

declare(strict_types=1);

namespace App\Livewire\Center\Booking\Support;

use App\Modules\ServiceJourney\Domain\Enums\JourneyStatus;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Carbon\CarbonImmutable;

/**
 * The booking drawer's labels and the visit it may already have become.
 *
 * Takes `AppointmentPresenter::detail()` — already scoped, already masked —
 * and adds only what a person reads: localized dates in the booking's own
 * timezone, status and source labels, durations. It adds no field the
 * presenter did not give it (ADR-042).
 */
final class AppointmentView
{
    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    public static function detail(array $detail): array
    {
        $timezone = (string) $detail['timezone'];
        $status = (string) $detail['status'];

        /** @var list<array<string, mixed>> $items */
        $items = $detail['items'];
        /** @var list<array<string, mixed>> $notes */
        $notes = $detail['notes'] ?? [];
        /** @var array<string, mixed>|null $cancellation */
        $cancellation = $detail['cancellation'] ?? null;
        /** @var array<string, mixed>|null $customer */
        $customer = $detail['customer'] ?? null;

        return $detail + [
            'status_label' => BookingFormat::status($status),
            'tone' => BookingFormat::tone($status),
            'source_label' => BookingFormat::source((string) $detail['source']),
            'date_label' => BookingFormat::dateLong((string) $detail['local_date']),
            'time_label' => $detail['local_start'].'–'.$detail['local_end'],
            'duration_label' => BookingFormat::duration((int) $detail['duration_minutes']),
            'customer_name' => is_array($customer) ? (string) $customer['name'] : __('manager_booking.calendar.no_customer'),
            'contact_phone' => is_array($customer) ? BookingFormat::phone($customer['phone'] ?? null, ($customer['contact_masked'] ?? false) === true) : null,
            'contact_masked' => is_array($customer) && ($customer['contact_masked'] ?? false) === true,
            'customer_uuid' => is_array($customer) ? ($customer['uuid'] ?? null) : null,
            'lines' => array_map(static fn (array $item): array => self::line($item, $timezone), $items),
            'note_rows' => array_map(static fn (array $note): array => $note + [
                'visibility_label' => __('manager_booking.notes.visibility.'.$note['visibility']),
                'when' => BookingFormat::instant(is_string($note['created_at'] ?? null) ? $note['created_at'] : null, $timezone),
            ], $notes),
            'cancelled' => $cancellation === null ? null : [
                'when' => BookingFormat::instant(is_string($cancellation['at'] ?? null) ? $cancellation['at'] : null, $timezone),
                'reason' => $cancellation['reason'] ?? null,
                'by' => $cancellation['by'] ?? null,
            ],
            'confirmed_label' => BookingFormat::instant(is_string($detail['confirmed_at'] ?? null) ? $detail['confirmed_at'] : null, $timezone),
            'completed_label' => BookingFormat::instant(is_string($detail['completed_at'] ?? null) ? $detail['completed_at'] : null, $timezone),
        ];
    }

    /**
     * The visit this booking became, if it has been checked in.
     *
     * Read here, at the surface, because Booking must never depend on the
     * journey (ADR-049): the booking module cannot ask, so the screen does.
     *
     * @return 'none'|'active'|'completed'|'aborted'
     */
    public static function visitState(int $appointmentId): string
    {
        $status = ServiceJourney::query()->where('appointment_id', $appointmentId)->value('status');

        $status = $status instanceof JourneyStatus ? $status : JourneyStatus::tryFrom((string) $status);

        return match ($status) {
            JourneyStatus::Active => 'active',
            JourneyStatus::Completed => 'completed',
            JourneyStatus::Aborted => 'aborted',
            default => 'none',
        };
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function line(array $item, string $timezone): array
    {
        $starts = CarbonImmutable::parse((string) $item['starts_at'])->setTimezone($timezone);
        /** @var array<string, mixed>|null $employee */
        $employee = $item['employee'] ?? null;
        /** @var list<array<string, mixed>> $resources */
        $resources = $item['resources'] ?? [];
        /** @var list<array<string, mixed>> $addons */
        $addons = $item['addons'] ?? [];

        return $item + [
            'time_label' => $starts->format('H:i').'–'.$starts->addMinutes((int) $item['duration_minutes'])->format('H:i'),
            'duration_label' => BookingFormat::duration((int) $item['duration_minutes']),
            'staff_name' => is_array($employee) ? (string) $employee['name'] : null,
            'staff_inactive' => is_array($employee) && ($employee['active'] ?? true) === false,
            'named' => ($item['employee_selection'] ?? null) === 'specific',
            'addons_label' => implode(', ', array_map(static fn (array $addon): string => (string) $addon['name'], $addons)),
            'rooms_label' => implode(', ', array_map(static fn (array $resource): string => (string) $resource['name'], $resources)),
            // The room form opens on the only room a service holds; with two, the desk picks.
            'room_default' => count($resources) === 1 ? (string) ($resources[0]['uuid'] ?? '') : '',
        ];
    }
}
