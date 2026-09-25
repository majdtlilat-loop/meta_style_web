<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application;

use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Booking\Contracts\BookingConfirmationFacts;
use App\Modules\Booking\Data\BookingConfirmationData;
use App\Modules\Booking\Data\BookingConfirmationLine;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Enums\EmployeeSelection;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Booking\Domain\Models\AppointmentItem;
use App\Modules\Booking\Domain\Models\AppointmentItemAddon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The booking record, as a confirmation may use it (docs/25-WHATSAPP.md §22).
 *
 * READ-ONLY: one eager-loaded read per booking, turned into a
 * `BookingConfirmationData` so the model never leaves the Booking module.
 */
final class AppointmentConfirmationFacts implements BookingConfirmationFacts
{
    public function find(int $appointmentId): ?BookingConfirmationData
    {
        return $this->read(static fn (Builder $query) => $query->whereKey($appointmentId));
    }

    public function findByUuid(string $uuid): ?BookingConfirmationData
    {
        return $this->read(static fn (Builder $query) => $query->where('uuid', $uuid));
    }

    public function confirmedGuestBookings(
        CarbonImmutable $confirmedFrom,
        CarbonImmutable $startsAfter,
        int $afterId,
        int $limit,
    ): array {
        $ids = [];

        Appointment::query()
            ->where('status', AppointmentStatus::Confirmed->value)
            ->where('starts_at', '>', $startsAfter->utc())
            ->where('confirmed_at', '>=', $confirmedFrom->utc())
            ->where('id', '>', $afterId)
            // A guest is a customer with no account row — never a flag (ADR-041).
            ->whereHas('customer', static fn (Builder $customer) => $customer->whereDoesntHave('account'))
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get(['id', 'uuid'])
            ->each(static function (Appointment $appointment) use (&$ids): void {
                $ids[(int) $appointment->id] = (string) $appointment->uuid;
            });

        return $ids;
    }

    /**
     * @param  callable(Builder<Appointment>): mixed  $where
     */
    private function read(callable $where): ?BookingConfirmationData
    {
        $query = Appointment::query()->with([
            // `account` loaded, so "is this a guest" costs no second query.
            'customer.account',
            'branch',
            'items.employee',
            'items.addons',
        ]);

        $where($query);

        /** @var Appointment|null $appointment */
        $appointment = $query->first();
        $customer = $appointment?->customer;

        if ($appointment === null || $customer === null) {
            return null;
        }

        $branch = $appointment->branch;

        return new BookingConfirmationData(
            appointmentId: (int) $appointment->id,
            uuid: $appointment->uuid,
            reference: $appointment->reference,
            status: $appointment->status,
            startsAt: $appointment->starts_at->utc(),
            endsAt: $appointment->ends_at->utc(),
            timezone: $appointment->booked_timezone,
            branchId: (int) $appointment->branch_id,
            branchName: $branch->name ?? new TranslatedText,
            branchPhone: $branch?->phone,
            branchWhatsapp: $branch?->whatsapp,
            customerId: (int) $appointment->customer_id,
            customerName: (string) $customer->name,
            customerPhone: PhoneNumber::parse($customer->phone)?->e164,
            customerLocale: $customer->preferred_locale,
            customerHasAccount: $customer->isRegistered(),
            customerAllowsOperationalMessages: (bool) $customer->allow_operational_messages,
            lines: array_values($appointment->items
                ->map(static fn (AppointmentItem $item): BookingConfirmationLine => new BookingConfirmationLine(
                    service: $item->service_name,
                    variation: $item->variation_name,
                    addons: array_values($item->addons
                        ->map(static fn (AppointmentItemAddon $addon): TranslatedText => $addon->name)
                        ->all()),
                    // Named only when the customer asked for that person.
                    employee: $item->employee_selection === EmployeeSelection::Specific
                        ? $item->employee?->name
                        : null,
                ))
                ->all()),
        );
    }
}
