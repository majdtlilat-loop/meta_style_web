<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application;

use App\Modules\Booking\Domain\Availability\BlockFinder;
use App\Modules\Booking\Domain\Models\Appointment;
use Carbon\CarbonImmutable;

/**
 * Upcoming bookings that now sit inside an employee's time off, as plain rows
 * for the Manager — so the person who just added the block can move them.
 *
 * Read-only, and the only place outside the engine that looks at those
 * appointments: the Manager never holds an Appointment model
 * (BookingBoundaryTest). The caller decides whether the viewer may see
 * bookings at all.
 */
final class AppointmentsInsideBlocks
{
    public const LIMIT = 25;

    public function __construct(private readonly BlockFinder $finder) {}

    /**
     * @return list<array{uuid: string, at: string, customer: string|null}>
     */
    public function forBranch(int $branchId, string $locale, ?CarbonImmutable $from = null): array
    {
        $ids = $this->finder->appointmentsInsideBlocks($branchId, $from ?? CarbonImmutable::now());
        if ($ids === []) {
            return [];
        }

        return Appointment::query()
            ->with('customer')
            ->whereIn('id', $ids)
            ->blocking()
            ->orderBy('starts_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(static fn (Appointment $appointment): array => [
                'uuid' => (string) $appointment->uuid,
                'at' => $appointment->localStart()->locale($locale)->translatedFormat('D j M, H:i'),
                'customer' => $appointment->customer?->name,
            ])
            ->values()
            ->all();
    }
}
