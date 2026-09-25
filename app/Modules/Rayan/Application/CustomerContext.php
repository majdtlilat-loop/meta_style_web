<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Application;

use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Loyalty\Application\LoyaltyAccess;
use App\Modules\Loyalty\Application\LoyaltyQuery;
use App\Modules\Memberships\Application\MembershipsQuery;
use App\Modules\Memberships\Domain\Models\CustomerMembership;
use App\Modules\Packages\Application\PackageLedger;
use App\Modules\Packages\Application\PackagesQuery;
use App\Modules\Packages\Domain\Models\CustomerPackage;
use Carbon\CarbonImmutable;

/**
 * The curated, customer-safe facts the assistant may know about one customer.
 *
 * ## An allow-list, and a NARROW one
 *
 * Everything here leaves the building: it is serialised into a tool result and
 * sent to an external model provider, and from there the model may read any of
 * it back to whoever is holding that phone. So the test for every field is not
 * "is this about the customer" but "would the center be content for this to be
 * read aloud to whoever is messaging them" (docs/27-RAYAN.md §10).
 *
 * Deliberately NARROWER than the customer's own account page. That page is
 * behind a password and shows history; this is a chat reply, and a model given
 * three years of transactions will quote them.
 *
 * ## Never here, at any point
 *
 * Staff notes, manager notes, any Finance figure, any Audit entry, internal
 * costs, internal database ids, employee personal data, any other customer,
 * loyalty internals such as unrecovered points, credentials, security
 * metadata, or the customer's own phone number and email — the model has no
 * use for contact details it did not need to reach them.
 *
 * ## Customer-visible notes do not exist
 *
 * `NoteVisibility` is deliberately not reopened in Phase 13. Every internal
 * note stays internal, and there is no path from this class to one (§10).
 */
final class CustomerContext
{
    public function __construct(
        private readonly LoyaltyAccess $loyaltyAccess,
        private readonly LoyaltyQuery $loyalty,
        private readonly MembershipsQuery $memberships,
        private readonly PackagesQuery $packages,
    ) {}

    /**
     * Two upcoming bookings is what a conversation ever needs. "And then?" is a
     * question the customer can ask.
     */
    private const UPCOMING = 2;

    /**
     * @return array<string, mixed>|null null when the customer is unknown or
     *                                   archived
     */
    public function for(int $customerId): ?array
    {
        /** @var Customer|null $customer */
        $customer = Customer::query()->whereKey($customerId)->first();

        if (! $customer instanceof Customer || $customer->isArchived()) {
            return null;
        }

        $now = CarbonImmutable::now();

        return [
            // The name only. Not the phone, not the email: the assistant
            // already reached them, and repeating contact details back is a
            // disclosure with no purpose.
            'name' => $customer->name,
            'upcoming_bookings' => $this->upcoming($customerId),
            'memberships' => $this->activeMemberships($customerId, $now),
            'packages' => $this->activePackages($customerId, $now),
            'loyalty' => $this->loyaltyPoints($customerId),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function upcoming(int $customerId): array
    {
        /** @var list<Appointment> $appointments */
        $appointments = Appointment::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', [AppointmentStatus::Booked->value, AppointmentStatus::Confirmed->value])
            ->where('starts_at', '>=', CarbonImmutable::now()->utc())
            ->with('branch')
            ->orderBy('starts_at')
            ->limit(self::UPCOMING)
            ->get()
            ->all();

        return array_map(static fn (Appointment $appointment): array => [
            'reference' => $appointment->reference,
            'date' => $appointment->localDate(),
            'time' => $appointment->localStart()->format('H:i'),
            'branch' => $appointment->branch?->name->get(),
        ], $appointments);
    }

    /**
     * Active memberships, as a NAME and an END DATE.
     *
     * Not the module's full customer shape: the benefit breakdown, the usage
     * ledger and the per-benefit remaining counts are an account-page answer,
     * and a model handed them will recite them.
     *
     * @return list<array<string, mixed>>
     */
    private function activeMemberships(int $customerId, CarbonImmutable $now): array
    {
        $active = array_values(array_filter(
            $this->memberships->ofCustomer($customerId),
            static fn (CustomerMembership $membership): bool => $membership->state($now) === 'active',
        ));

        /*
         * The SNAPSHOTTED name on the membership, not the plan's current one.
         * A center renaming "Gold" to "Premium" must not rewrite what a
         * customer was sold, which is exactly why that column exists
         * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md).
         */
        return array_map(static fn (CustomerMembership $membership): array => [
            'name' => $membership->name->get(),
            'ends_on' => $membership->expires_at->toDateString(),
        ], $active);
    }

    /**
     * Active packages, as a name and SESSIONS LEFT — the one number a customer
     * ever asks about.
     *
     * @return list<array<string, mixed>>
     */
    private function activePackages(int $customerId, CarbonImmutable $now): array
    {
        $active = array_values(array_filter(
            $this->packages->ofCustomer($customerId),
            static fn (CustomerPackage $package): bool => $package->state($now) === 'active',
        ));

        /*
         * Sessions left comes from the LEDGER, never from a column: the
         * append-only history is the only truth about what has been used, and
         * a cached count is a second answer that drifts (ADR-062/063).
         *
         * One query for every package, then summed per package — an N+1 here
         * would be a query per package on every message.
         */
        $left = PackageLedger::leftFor(array_map(
            static fn (CustomerPackage $package): int => (int) $package->getKey(),
            $active,
        ));

        return array_map(static function (CustomerPackage $package) use ($left): array {
            $perItem = $left[(int) $package->getKey()] ?? [];

            return [
                // The snapshotted name, for the same reason as memberships.
                'name' => $package->name->get(),
                'sessions_left' => array_sum(array_map(
                    static fn (int $remaining): int => max(0, $remaining),
                    $perItem,
                )),
                'expires_on' => $package->expires_at->toDateString(),
            ];
        }, $active);
    }

    /**
     * The points balance, and only when the center actually runs loyalty.
     *
     * A center that has switched loyalty off must not have its assistant
     * discussing points — and a customer with no account has no balance to
     * report, which is different from a balance of zero.
     */
    private function loyaltyPoints(int $customerId): ?int
    {
        if (! $this->loyaltyAccess->enabled()) {
            return null;
        }

        $account = $this->loyalty->accountOf($customerId);

        return $account?->balance;
    }
}
