<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * One customer's bookings, for the STAFF customer page (Manager CRM).
 *
 * Needs the broad `appointment.view`: a stylist who may only see their own
 * appointments has no business browsing a customer's whole booking history.
 * The viewer's BRANCH scope is part of the WHERE clause, as everywhere in
 * Booking — a customer is center-wide, their bookings are not.
 *
 * Read-only and bounded: the next bookings (soonest first) and the most recent
 * past ones (newest first), each capped, with items and employees eager-loaded
 * for the presenter.
 */
final class CustomerProfileAppointments
{
    public const UPCOMING = 20;

    public const PAST = 50;

    /**
     * @return array{upcoming: list<Appointment>, past: list<Appointment>}
     *
     * @throws AuthorizationException
     */
    public function forCustomer(User $viewer, int $customerId, ?CarbonImmutable $now = null): array
    {
        if (! $viewer->hasPermission(Permission::AppointmentView)) {
            throw new AuthorizationException(__('manager_customers.errors.may_not_view_bookings'));
        }

        $now = ($now ?? CarbonImmutable::now())->utc();

        /** @var list<Appointment> $upcoming */
        $upcoming = $this->base($viewer, $customerId)
            ->where('starts_at', '>=', $now)
            ->whereIn('status', AppointmentStatus::blockingValues())
            ->orderBy('starts_at')
            ->limit(self::UPCOMING)
            ->get()
            ->all();

        /** @var list<Appointment> $past */
        $past = $this->base($viewer, $customerId)
            ->where(fn (Builder $q) => $q
                ->where('starts_at', '<', $now)
                ->orWhereNotIn('status', AppointmentStatus::blockingValues()))
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit(self::PAST)
            ->get()
            ->all();

        return ['upcoming' => $upcoming, 'past' => $past];
    }

    /**
     * The same bookings as plain rows for the customer page, so no channel
     * outside Booking ever holds an Appointment model (BookingBoundaryTest).
     * Status stays a raw value; the page translates it.
     *
     * @return array{upcoming: list<array<string, mixed>>, past: list<array<string, mixed>>}
     */
    public function rowsForCustomer(User $viewer, int $customerId, AppointmentPresenter $presenter, string $locale, ?CarbonImmutable $now = null): array
    {
        $found = $this->forCustomer($viewer, $customerId, $now);
        $row = static function (Appointment $appointment) use ($presenter, $viewer, $locale): array {
            $shape = $presenter->summary($appointment, $viewer);
            /** @var list<array<string, mixed>> $items */
            $items = $shape['items'];

            return [
                'uuid' => $shape['uuid'],
                'reference' => $shape['reference'],
                'status' => $shape['status'],
                'date' => $appointment->localStart()->locale($locale)->isoFormat('ddd D MMM YYYY'),
                'time' => $shape['local_start'].'–'.$shape['local_end'],
                'branch' => $appointment->relationLoaded('branch') ? $appointment->branch?->name->get($locale) : null,
                'services' => array_map(static fn (array $item): array => [
                    'name' => (string) $item['service'].($item['variation'] !== null ? ' · '.$item['variation'] : ''),
                    'employee' => $item['employee']['name'] ?? null,
                ], $items),
                'total' => $shape['total']['formatted'] ?? null,
            ];
        };

        return [
            'upcoming' => array_map($row, $found['upcoming']),
            'past' => array_map($row, $found['past']),
        ];
    }

    /**
     * @return Builder<Appointment>
     */
    private function base(User $viewer, int $customerId): Builder
    {
        /** @var Builder<Appointment> $query */
        $query = $viewer->branchScope()->applyTo(
            Appointment::query()
                ->with(['items.employee', 'items.addons', 'branch'])
                ->where('customer_id', $customerId),
        );

        return $query;
    }
}
