<?php

declare(strict_types=1);

use App\Kernel\Database\AfterCommitFailed;
use App\Modules\Booking\Application\Actions\TransitionAppointment;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Notifications\Application\ExpirySweep;
use App\Modules\Notifications\Application\Inbox;
use App\Modules\Notifications\Application\NotificationCleanup;
use App\Modules\Notifications\Application\NotificationPreferences;
use App\Modules\Notifications\Application\ReminderSweep;
use App\Modules\Notifications\Domain\Data\Recipient;
use App\Modules\Notifications\Domain\Enums\NotificationType;
use App\Modules\Notifications\Domain\Enums\PreferenceKey;
use App\Modules\Notifications\Domain\Enums\RecipientKind;
use App\Modules\Notifications\Domain\Models\Notification;
use App\Modules\Notifications\Domain\Models\NotificationRecipient;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Reviews\Application\Actions\SubmitReview;
use App\Modules\Reviews\Domain\Data\ReviewSubmission;
use App\Modules\Reviews\Domain\Events\ReviewSubmitted;
use App\Modules\Reviews\Domain\Models\Review;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

/*
|--------------------------------------------------------------------------
| What actually produces a notification
|--------------------------------------------------------------------------
|
| docs/23-NOTIFICATIONS.md §§3, 11–14.
|
| Every one of these runs AFTER the fact it is about has committed. The booking,
| the invoice, the activation and the review are the record; being told is not.
|
*/

/**
 * How many notifications of a type exist.
 */
function noticeCount(NotificationType $type): int
{
    return Notification::query()->where('type', $type->value)->count();
}

it('writes a reminder once, in the lead window, for a customer who can read it', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantCustomerAccounts();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $account = $this->seedCustomerAccount($customer);

        $appointment = $this->bookFor($seed, $owner, $customer);

        // Twenty hours before it, inside the one-day lead.
        $this->travelTo(CarbonImmutable::instance($appointment->starts_at)->subHours(20));

        $sweep = app(ReminderSweep::class);

        expect($sweep->sweep())->toBe(1)
            // A second pass writes nothing: the reminder is keyed on the
            // appointment, so overlapping windows are free (§12).
            ->and($sweep->sweep())->toBe(0)
            ->and(noticeCount(NotificationType::AppointmentReminder))->toBe(1);

        $me = new Recipient(RecipientKind::Customer, (int) $account->getKey());
        $page = app(Inbox::class)->page($me);

        expect($page)->toHaveCount(1)
            ->and($page[0]['type'])->toBe(NotificationType::AppointmentReminder->value)
            ->and($page[0]['source']['type'])->toBe('appointment')
            ->and($page[0]['source']['id'])->toBe($appointment->uuid)
            // The branch's own clock, carried with its offset (§13).
            ->and($page[0]['message'])->toContain('+03:00');
    });
});

it('never reminds about a visit that is too far off, cancelled, or has nobody to tell', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantCustomerAccounts();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        // Beyond the one-day lead.
        $far = $this->seedCustomer('Far Away', '0750 123 4591');
        $this->seedCustomerAccount($far);
        $this->bookFor($seed, $owner, $far, daysAhead: 6);

        // Inside the window, then cancelled.
        $gone = $this->seedCustomer('Cancelled Sara', '0750 123 4592');
        $this->seedCustomerAccount($gone);
        $cancelled = $this->bookFor($seed, $owner, $gone, time: '11:00');
        app(TransitionAppointment::class)($cancelled, AppointmentStatus::Cancelled, BookingActor::staff($owner), ['reason' => 'Called to cancel']);

        // Inside the window, but a guest with no login: there is no inbox (§4).
        $guest = $this->seedCustomer('Guest Noor', '0750 123 4593');
        $this->bookFor($seed, $owner, $guest, time: '12:00');

        // Stand ten hours before the two that are close.
        $this->travelTo(CarbonImmutable::instance($cancelled->starts_at)->subHours(10));

        expect(app(ReminderSweep::class)->sweep())->toBe(0)
            ->and(noticeCount(NotificationType::AppointmentReminder))->toBe(0);
    });
});

it('drops a stale reminder when the visit moves, and tells the customer it moved', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantCustomerAccounts();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $account = $this->seedCustomerAccount($customer);

        $appointment = $this->bookFor($seed, $owner, $customer);

        $this->travelTo(CarbonImmutable::instance($appointment->starts_at)->subHours(20));

        expect(app(ReminderSweep::class)->sweep())->toBe(1);

        $me = new Recipient(RecipientKind::Customer, (int) $account->getKey());
        $first = app(Inbox::class)->page($me)[0]['message'];

        // The desk cancels it: the reminder for a visit that is not happening
        // is withdrawn, and the customer is told (§13).
        app(TransitionAppointment::class)($appointment, AppointmentStatus::Cancelled, BookingActor::staff($owner), ['reason' => 'Closed that day']);

        expect(noticeCount(NotificationType::AppointmentReminder))->toBe(0)
            ->and(noticeCount(NotificationType::AppointmentCancelled))->toBe(1)
            // And the sweep does not bring it back.
            ->and(app(ReminderSweep::class)->sweep())->toBe(0);

        expect($first)->toContain('+03:00');
    });
});

it('tells a customer their invoice is ready, once', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantCustomerAccounts();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $account = $this->seedCustomerAccount($customer);

        $invoice = $this->customerInvoice($seed, $owner, $customer);

        $me = new Recipient(RecipientKind::Customer, (int) $account->getKey());
        $page = app(Inbox::class)->page($me);

        expect(noticeCount(NotificationType::InvoiceIssued))->toBe(1)
            ->and($page[0]['message'])->toContain($invoice->number)
            // No money in the payload: the notification is a pointer, not a
            // second copy of a financial document (§7).
            ->and(json_encode($page[0]))->not->toContain('total');
    });
});

it('alerts the staff who can act on a low rating, and only them', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $this->grantCustomerAccounts();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        // A good review raises nothing.
        $happy = $this->customerVisit($seed, $owner, $this->seedCustomer());
        app(SubmitReview::class)->byToken($this->reviewToken($happy, $owner), new ReviewSubmission(5));

        expect(noticeCount(NotificationType::LowRatingReceived))->toBe(0);

        // At the threshold, it does.
        $unhappy = $this->customerVisit($seed, $owner, $this->seedCustomer('Unhappy Rana', '0750 123 4594'));
        $review = app(SubmitReview::class)->byToken($this->reviewToken($unhappy, $owner), new ReviewSubmission(2, 'Not good.'));

        $staff = new Recipient(RecipientKind::Staff, (int) $owner->getKey());
        $page = app(Inbox::class)->page($staff);

        expect(noticeCount(NotificationType::LowRatingReceived))->toBe(1)
            ->and($page[0]['severity'])->toBe('important')
            ->and($page[0]['source']['id'])->toBe($review->uuid)
            ->and($page[0]['message'])->toContain('2')
            // The customer's words are not copied into the alert (§7).
            ->and(json_encode($page[0]))->not->toContain('Not good.');

        // Heard again — a retry, a reconciliation — writes nothing.
        event(new ReviewSubmitted(
            (int) $review->getKey(), (int) $review->branch_id, 2, $review->customer_id === null ? null : (int) $review->customer_id,
        ));

        expect(NotificationRecipient::query()
            ->whereIn('notification_id', Notification::query()->select('id')->where('type', NotificationType::LowRatingReceived->value))
            ->count())->toBe(1);
    });
});

it('keeps the review when the alert about it cannot be written', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $journey = $this->customerVisit($seed, $owner, $this->seedCustomer());
        $token = $this->reviewToken($journey, $owner);

        Exceptions::fake([AfterCommitFailed::class]);

        /*
         * The notification tables are unavailable — at the CONNECTION, because
         * the inbox is written with `insertOrIgnore` and a model hook would
         * never fire (that is the idempotency, not an oversight).
         */
        $down = true;

        DB::connection('tenant')->beforeExecuting(function (string $query) use (&$down): void {
            if ($down && str_contains($query, 'notifications')) {
                throw new RuntimeException('Notification storage unavailable');
            }
        });

        // The customer's review is the truth; the alert is downstream (§11).
        $review = app(SubmitReview::class)->byToken($token, new ReviewSubmission(1, 'Awful.'));

        $down = false;

        expect($review->overall_rating)->toBe(1)
            ->and(Review::query()->count())->toBe(1)
            ->and(Notification::query()->count())->toBe(0);
    });
})->group('after-commit');

it('warns once about a membership or package that is about to run out', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantMemberships();
        $this->grantPackages();
        $this->grantCustomerAccounts();
        $this->outlastTrial();

        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $account = $this->seedCustomerAccount($customer);

        app(CollectDeskPayment::class)(
            $this->membershipInvoice($seed['branch'], $owner, $customer, $this->membershipPlan($seed, $owner)),
            $owner, PaymentMethod::Cash, 50000,
        );
        $this->paidPackage($seed, $owner, $customer);

        $sweep = app(ExpirySweep::class);

        // Nothing is close to expiring yet.
        expect($sweep->sweep())->toBe(0);

        // A week before the membership's 30-day term ends.
        $this->travel(24)->days();

        expect($sweep->sweep())->toBe(1)
            // And again writes nothing: one warning per benefit (§14).
            ->and($sweep->sweep())->toBe(0)
            ->and(noticeCount(NotificationType::MembershipExpiring))->toBe(1);

        $me = new Recipient(RecipientKind::Customer, (int) $account->getKey());

        expect(collect(app(Inbox::class)->page($me))->pluck('type'))
            ->toContain(NotificationType::MembershipActivated->value)
            ->toContain(NotificationType::PackageActivated->value)
            ->toContain(NotificationType::MembershipExpiring->value);
    });
});

it('respects a customer who turned expiry warnings off', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantMemberships();
        $this->grantCustomerAccounts();
        $this->outlastTrial();

        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $account = $this->seedCustomerAccount($customer);

        app(CollectDeskPayment::class)(
            $this->membershipInvoice($seed['branch'], $owner, $customer, $this->membershipPlan($seed, $owner)),
            $owner, PaymentMethod::Cash, 50000,
        );

        $me = new Recipient(RecipientKind::Customer, (int) $account->getKey());
        app(NotificationPreferences::class)->set($me, PreferenceKey::BenefitExpiry, false);

        $this->travel(24)->days();

        expect(app(ExpirySweep::class)->sweep())->toBe(0)
            ->and(noticeCount(NotificationType::MembershipExpiring))->toBe(0)
            // The activation, which has no switch, still arrived.
            ->and(noticeCount(NotificationType::MembershipActivated))->toBe(1);
    });
});

it('clears old notifications but never an unread important one', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $this->grantCustomerAccounts();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $this->seedCustomerAccount($customer);

        // One ordinary notification, and one important one nobody reads.
        $this->customerInvoice($seed, $owner, $customer);

        $unhappy = $this->customerVisit($seed, $owner, $this->seedCustomer('Unhappy Rana', '0750 123 4594'));
        app(SubmitReview::class)->byToken($this->reviewToken($unhappy, $owner), new ReviewSubmission(1));

        expect(Notification::query()->count())->toBe(2);

        $this->travel(config('notifications.retention.days') + 1)->days();

        expect(app(NotificationCleanup::class)->sweep())->toBe(1)
            // The one-star review nobody has looked at is kept (§15).
            ->and(noticeCount(NotificationType::LowRatingReceived))->toBe(1)
            ->and(noticeCount(NotificationType::InvoiceIssued))->toBe(0);

        // Once it IS read, it ages out like everything else.
        app(Inbox::class)->markAllRead(new Recipient(RecipientKind::Staff, (int) $owner->getKey()));

        expect(app(NotificationCleanup::class)->sweep())->toBe(1)
            ->and(Notification::query()->count())->toBe(0)
            ->and(NotificationRecipient::query()->count())->toBe(0);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
