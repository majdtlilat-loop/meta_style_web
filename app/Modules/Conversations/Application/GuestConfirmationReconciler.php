<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Application;

use App\Kernel\Reconciliation\Contracts\Reconciler;
use App\Modules\Booking\Contracts\BookingConfirmationFacts;
use App\Modules\Conversations\Domain\Enums\NoticePurpose;
use App\Modules\Conversations\Domain\Enums\NoticeStatus;
use App\Modules\Conversations\Domain\Models\WhatsAppOutboundNotice;
use Carbon\CarbonImmutable;

/**
 * The recovery half of the guest confirmation (docs/25-WHATSAPP.md §22).
 *
 * `metastyle:reconcile` runs it hourly, per center. The candidates are the
 * Booking module's answer to "which guest bookings were confirmed in the last
 * day and are still ahead" (`BookingConfirmationFacts::confirmedGuestBookings`
 * — this module never queries the booking tables itself), matched against
 * this module's own notice rows. Two jobs, both idempotent:
 *
 *   MISSED   a candidate with no notice row — the after-commit callback never
 *            ran (the process died between the commit and the callback). It is
 *            decided now, through the same `insertOrIgnore` claim, so it can
 *            never be decided twice.
 *
 *   REFUSED  a candidate whose notice is `failed` with attempts left. Claimed
 *            by a conditional UPDATE, so two passes cannot both send. `unknown`
 *            and `pending` are never touched: the customer may already have the
 *            message (§9).
 *
 * Bounded to bookings confirmed in the last day on purpose. A confirmation is
 * only worth sending while it is news; replaying a week of bookings after an
 * outage — or after a center regains the channel — would message people about
 * decisions made long before.
 */
final class GuestConfirmationReconciler implements Reconciler
{
    private const WINDOW_HOURS = 24;

    private const BATCH = 200;

    public function __construct(
        private readonly BookingConfirmationFacts $bookings,
        private readonly GuestBookingConfirmations $confirmations,
        private readonly ConversationsAccess $access,
    ) {}

    public function name(): string
    {
        return 'whatsapp_confirmations';
    }

    public function reconcile(CarbonImmutable $since): int
    {
        $now = CarbonImmutable::now()->utc();
        $window = $now->subHours(self::WINDOW_HOURS);
        $from = $since->utc()->greaterThan($window) ? $since->utc() : $window;

        ['missed' => $missed, 'refused' => $refused] = $this->candidates($from, $now);

        $repaired = 0;

        foreach ($missed as $appointmentId) {
            $repaired += $this->confirmations->confirm($appointmentId) instanceof WhatsAppOutboundNotice ? 1 : 0;
        }

        // Retrying is new activity: only while the center owns the channel.
        if (! $this->access->channelEnabled()) {
            return $repaired;
        }

        foreach ($refused as $notice) {
            $repaired += $this->confirmations->retry($notice) instanceof WhatsAppOutboundNotice ? 1 : 0;
        }

        return $repaired;
    }

    /**
     * Confirmed guest bookings, still ahead, that nobody decided about — and
     * refused confirmations of such bookings with attempts left. At most
     * {@see BATCH} of each per pass.
     *
     * @return array{missed: list<int>, refused: list<WhatsAppOutboundNotice>}
     */
    private function candidates(CarbonImmutable $from, CarbonImmutable $now): array
    {
        $missed = [];
        $refused = [];
        $afterId = 0;

        do {
            $page = $this->bookings->confirmedGuestBookings($from, $now, $afterId, self::BATCH);

            if ($page === []) {
                break;
            }

            $notices = WhatsAppOutboundNotice::query()
                ->where('purpose', NoticePurpose::BookingConfirmation->value)
                ->where('source_type', 'appointment')
                ->whereIn('source_uuid', array_values($page))
                ->get()
                ->keyBy('source_uuid');

            foreach ($page as $appointmentId => $uuid) {
                $afterId = $appointmentId;
                $notice = $notices->get($uuid);

                if (! $notice instanceof WhatsAppOutboundNotice) {
                    if (count($missed) < self::BATCH) {
                        $missed[] = $appointmentId;
                    }

                    continue;
                }

                if ($notice->status === NoticeStatus::Failed
                    && $notice->attempts < GuestBookingConfirmations::MAX_ATTEMPTS
                    && count($refused) < self::BATCH) {
                    $refused[] = $notice;
                }
            }
        } while (count($page) === self::BATCH && (count($missed) < self::BATCH || count($refused) < self::BATCH));

        return ['missed' => $missed, 'refused' => $refused];
    }
}
