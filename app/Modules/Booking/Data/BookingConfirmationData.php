<?php

declare(strict_types=1);

namespace App\Modules\Booking\Data;

use App\Kernel\Localization\TranslatedText;
use App\Kernel\Time\BranchClock;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use Carbon\CarbonImmutable;

/**
 * Everything a booking confirmation may say about one booking, and nothing
 * more — read from the booking record by the Booking module's
 * `BookingConfirmationFacts` contract.
 *
 * Crosses the module boundary instead of the Eloquent models, so a channel
 * that tells a customer about a booking never holds (and so can never write)
 * the booking, its customer or its employees (docs/04-MODULE-BOUNDARIES.md §2).
 *
 * Deliberately absent: prices, the verification code (a one-time secret,
 * docs/24 §3), notes, and every id except the two a channel needs to file its
 * own record against (`appointmentId` for the event it heard, `customerId` /
 * `branchId` for the thread). The uuid is for idempotency, never for a message.
 */
final readonly class BookingConfirmationData
{
    /**
     * @param  CarbonImmutable  $startsAt  UTC
     * @param  CarbonImmutable  $endsAt  UTC
     * @param  string  $timezone  the branch clock the booking was made in — the
     *                            wall clock the customer was told
     * @param  string|null  $customerPhone  E.164, or null when the customer has
     *                                      no usable number
     * @param  list<BookingConfirmationLine>  $lines  every booked service, in
     *                                                booked order
     */
    public function __construct(
        public int $appointmentId,
        public string $uuid,
        public ?string $reference,
        public AppointmentStatus $status,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public string $timezone,
        public int $branchId,
        public TranslatedText $branchName,
        public ?string $branchPhone,
        public ?string $branchWhatsapp,
        public int $customerId,
        public string $customerName,
        public ?string $customerPhone,
        public ?string $customerLocale,
        public bool $customerHasAccount,
        public bool $customerAllowsOperationalMessages,
        public array $lines,
    ) {}

    public function isConfirmed(): bool
    {
        return $this->status === AppointmentStatus::Confirmed;
    }

    public function localStart(): CarbonImmutable
    {
        return BranchClock::toLocal($this->startsAt->utc(), $this->timezone);
    }

    public function localEnd(): CarbonImmutable
    {
        return BranchClock::toLocal($this->endsAt->utc(), $this->timezone);
    }
}
