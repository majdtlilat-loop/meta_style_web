<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Memberships\Domain\Enums\CustomerMembershipStatus;
use App\Modules\Memberships\Domain\Models\CustomerMembership;
use App\Modules\Notifications\Domain\Data\NotificationRequest;
use App\Modules\Notifications\Domain\Enums\NotificationType;
use App\Modules\Notifications\Domain\Models\Notification;
use App\Modules\Packages\Domain\Enums\CustomerPackageStatus;
use App\Modules\Packages\Domain\Models\CustomerPackage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Your package runs out next week."
 *
 * ONE threshold and ONE notification per benefit. Not a drip sequence, not a
 * campaign, and not a second reminder a few days later: the notification is
 * keyed on the membership or the package itself, so however often this runs and
 * however long the benefit sits in the window, the customer is told exactly
 * once (docs/23-NOTIFICATIONS.md §14).
 *
 * A center that wants to chase expiring benefits properly is asking for
 * marketing campaigns, which is a later phase with an entitlement, an audience
 * and an unsubscribe — not a sweep quietly sending more messages.
 *
 * Both sides are bounded and indexed by their own status and expiry, and both
 * skip a customer with no account: there is no inbox to write to.
 */
final class ExpirySweep
{
    public function __construct(
        private readonly NotificationCenter $center,
        private readonly CustomerInboxes $inboxes,
    ) {}

    /**
     * Returns how many notifications it wrote.
     */
    public function sweep(?CarbonImmutable $now = null): int
    {
        if (! (bool) config('notifications.expiry.enabled', true)) {
            return 0;
        }

        $at = ($now ?? CarbonImmutable::now())->utc();
        $days = $this->leadDays();
        $until = $at->addDays($days);
        $limit = $this->maxPerRun();

        return $this->memberships($at, $until, $days, $limit)
            + $this->packages($at, $until, $days, $limit);
    }

    private function memberships(CarbonImmutable $at, CarbonImmutable $until, int $days, int $limit): int
    {
        /** @var list<CustomerMembership> $expiring */
        $expiring = CustomerMembership::query()
            ->where('status', CustomerMembershipStatus::Active->value)
            ->where('expires_at', '>', $at)
            ->where('expires_at', '<=', $until)
            ->whereNotIn('uuid', $this->alreadyTold(NotificationType::MembershipExpiring, 'customer_membership'))
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->all();

        $written = 0;

        foreach ($expiring as $membership) {
            $written += $this->tell(
                NotificationType::MembershipExpiring,
                'customer_membership',
                $membership->uuid,
                (int) $membership->customer_id,
                $days,
                $at,
            );
        }

        return $written;
    }

    private function packages(CarbonImmutable $at, CarbonImmutable $until, int $days, int $limit): int
    {
        /** @var list<CustomerPackage> $expiring */
        $expiring = CustomerPackage::query()
            ->where('status', CustomerPackageStatus::Active->value)
            ->where('expires_at', '>', $at)
            ->where('expires_at', '<=', $until)
            ->whereNotIn('uuid', $this->alreadyTold(NotificationType::PackageExpiring, 'customer_package'))
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->all();

        $written = 0;

        foreach ($expiring as $package) {
            $written += $this->tell(
                NotificationType::PackageExpiring,
                'customer_package',
                $package->uuid,
                (int) $package->customer_id,
                $days,
                $at,
            );
        }

        return $written;
    }

    private function tell(NotificationType $type, string $sourceType, string $sourceUuid, int $customerId, int $days, CarbonImmutable $at): int
    {
        $recipients = $this->inboxes->forCustomer($customerId);

        if ($recipients === []) {
            return 0;
        }

        return $this->center->deliver(new NotificationRequest(
            type: $type,
            sourceType: $sourceType,
            sourceUuid: $sourceUuid,
            params: ['days' => $days],
            recipients: $recipients,
        ), $at) > 0 ? 1 : 0;
    }

    /**
     * @return Builder<Notification>
     */
    private function alreadyTold(NotificationType $type, string $sourceType): Builder
    {
        return Notification::query()
            ->select('source_uuid')
            ->where('type', $type->value)
            ->where('source_type', $sourceType);
    }

    private function leadDays(): int
    {
        $days = (int) config('notifications.expiry.lead_days', 7);

        return max(1, min($days, 90));
    }

    private function maxPerRun(): int
    {
        $max = (int) config('notifications.expiry.max_per_run', 500);

        return max(1, min($max, 5_000));
    }
}
