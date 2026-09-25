<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Authorization\SystemRole;
use App\Modules\Notifications\Application\Inbox;
use App\Modules\Notifications\Application\NotificationCenter;
use App\Modules\Notifications\Application\NotificationPreferences;
use App\Modules\Notifications\Application\StaffTargets;
use App\Modules\Notifications\Domain\Data\NotificationRequest;
use App\Modules\Notifications\Domain\Data\Recipient;
use App\Modules\Notifications\Domain\Enums\NotificationType;
use App\Modules\Notifications\Domain\Enums\PreferenceKey;
use App\Modules\Notifications\Domain\Enums\RecipientKind;
use App\Modules\Notifications\Domain\Exceptions\NotificationsFailed;
use App\Modules\Notifications\Domain\Models\Notification;
use App\Modules\Notifications\Domain\Models\NotificationRecipient;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The inbox
|--------------------------------------------------------------------------
|
| docs/23-NOTIFICATIONS.md §§4, 12, 16.
|
| One fact, one row; one person, one copy. The identity is a KIND and an id
| together, because staff user 7 and customer account 7 are different people.
|
*/

/**
 * A notification about a made-up fact, addressed to `$to`.
 *
 * @param  list<Recipient>  $to
 */
function deliverTo(array $to, ?NotificationType $type = null, ?string $source = null): int
{
    return app(NotificationCenter::class)->deliver(new NotificationRequest(
        type: $type ?? NotificationType::InvoiceIssued,
        sourceType: 'invoice',
        sourceUuid: $source ?? (string) Str::uuid(),
        params: ['number' => 'INV-000001'],
        recipients: $to,
    ));
}

it('writes one fact and one copy per person, however many times it is asked', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantCustomerAccounts();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $account = $this->seedCustomerAccount($customer);

        $me = new Recipient(RecipientKind::Customer, (int) $account->getKey());
        $source = (string) Str::uuid();

        expect(deliverTo([$me], source: $source))->toBe(1)
            // The same fact heard again: no second row, no second inbox line.
            ->and(deliverTo([$me], source: $source))->toBe(0)
            ->and(deliverTo([$me, $me], source: $source))->toBe(0)
            ->and(Notification::query()->count())->toBe(1)
            ->and(NotificationRecipient::query()->count())->toBe(1);

        // A different fact of the same type is a different notification.
        expect(deliverTo([$me]))->toBe(1)
            ->and(Notification::query()->count())->toBe(2);

        unset($owner);
    });
});

it('never addresses a staff-only alert to a customer, or the reverse', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantCustomerAccounts();
        $owner = $this->ownerWithCatalogAccess();
        $account = $this->seedCustomerAccount($this->seedCustomer());

        $staff = new Recipient(RecipientKind::Staff, (int) $owner->getKey());
        $shopper = new Recipient(RecipientKind::Customer, (int) $account->getKey());

        // A low rating is staff-only; an invoice is customer-only. The audience
        // belongs to the TYPE, not to the call site (§6).
        expect(deliverTo([$shopper], NotificationType::LowRatingReceived))->toBe(0)
            ->and(deliverTo([$staff], NotificationType::InvoiceIssued))->toBe(0)
            ->and(NotificationRecipient::query()->count())->toBe(0);

        expect(deliverTo([$staff], NotificationType::LowRatingReceived))->toBe(1);
    });
});

it('keeps two people who share a number apart', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantCustomerAccounts();
        $owner = $this->ownerWithCatalogAccess();
        $account = $this->seedCustomerAccount($this->seedCustomer());

        /*
         * The premise, PINNED: `users` and `customer_accounts` have unrelated
         * sequences, so the center's first staff member and its first customer
         * login are both row 1. A query that filtered on the id alone would
         * hand one of them the other's mail, and would look correct in any
         * fixture where the two numbers happened to differ (§4).
         */
        expect((int) $owner->getKey())->toBe((int) $account->getKey());

        $staff = new Recipient(RecipientKind::Staff, (int) $owner->getKey());
        $shopper = new Recipient(RecipientKind::Customer, (int) $account->getKey());

        deliverTo([$staff], NotificationType::LowRatingReceived);
        deliverTo([$shopper]);

        $inbox = app(Inbox::class);

        expect($inbox->unreadCount($staff))->toBe(1)
            ->and($inbox->unreadCount($shopper))->toBe(1)
            // Same number, different mail.
            ->and($inbox->page($staff)[0]['type'])->toBe(NotificationType::LowRatingReceived->value)
            ->and($inbox->page($shopper)[0]['type'])->toBe(NotificationType::InvoiceIssued->value);

        $inbox->markAllRead($staff);

        expect($inbox->unreadCount($staff))->toBe(0)
            // Reading one inbox did not touch the other.
            ->and($inbox->unreadCount($shopper))->toBe(1);
    });
});

it('counts unread, marks one read and marks the rest read', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantCustomerAccounts();
        $this->ownerWithCatalogAccess();
        $account = $this->seedCustomerAccount($this->seedCustomer());

        $me = new Recipient(RecipientKind::Customer, (int) $account->getKey());

        foreach (range(1, 3) as $_) {
            deliverTo([$me]);
        }

        $inbox = app(Inbox::class);
        $page = $inbox->page($me);

        expect($page)->toHaveCount(3)
            ->and($inbox->unreadCount($me))->toBe(3)
            // Rendered at read time, from the parameters (§7).
            ->and($page[0]['message'])->toBe('Your invoice INV-000001 is ready.')
            ->and($page[0]['read_at'])->toBeNull();

        $inbox->markRead($me, (string) $page[0]['id']);

        expect($inbox->unreadCount($me))->toBe(2)
            // Marking the same one again is not an error.
            ->and(fn () => $inbox->markRead($me, (string) $page[0]['id']))->not->toThrow(NotificationsFailed::class)
            ->and($inbox->markAllRead($me))->toBe(2)
            ->and($inbox->unreadCount($me))->toBe(0)
            // And again changes nothing.
            ->and($inbox->markAllRead($me))->toBe(0);
    });
});

it('refuses to mark somebody else\'s notification, the same way it refuses one that does not exist', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantCustomerAccounts();
        $owner = $this->ownerWithCatalogAccess();
        $account = $this->seedCustomerAccount($this->seedCustomer());

        $shopper = new Recipient(RecipientKind::Customer, (int) $account->getKey());
        $staff = new Recipient(RecipientKind::Staff, (int) $owner->getKey());

        deliverTo([$shopper]);

        $inbox = app(Inbox::class);
        $theirs = (string) $inbox->page($shopper)[0]['id'];

        expect(fn () => $inbox->markRead($staff, $theirs))->toThrow(NotificationsFailed::class, 'That notification does not exist.')
            ->and(fn () => $inbox->markRead($staff, (string) Str::uuid()))->toThrow(NotificationsFailed::class, 'That notification does not exist.')
            ->and($inbox->unreadCount($shopper))->toBe(1);
    });
});

it('honours a preference for what is optional, and ignores one for what is not', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantCustomerAccounts();
        $this->ownerWithCatalogAccess();
        $account = $this->seedCustomerAccount($this->seedCustomer());

        $me = new Recipient(RecipientKind::Customer, (int) $account->getKey());
        $preferences = app(NotificationPreferences::class);

        // Absent means on (§8).
        expect($preferences->all($me))->toBe([
            'appointment_reminders' => true,
            'benefit_expiry' => true,
            'review_invitations' => true,
        ]);

        $preferences->set($me, PreferenceKey::AppointmentReminders, false);
        $preferences->set($me, PreferenceKey::BenefitExpiry, false);

        expect(app(NotificationCenter::class)->deliver(new NotificationRequest(
            type: NotificationType::AppointmentReminder,
            sourceType: 'appointment',
            sourceUuid: (string) Str::uuid(),
            recipients: [$me],
        )))->toBe(0);

        // But an issued invoice has no switch, and is delivered regardless.
        expect(deliverTo([$me]))->toBe(1)
            ->and($preferences->all($me)['appointment_reminders'])->toBeFalse();

        // Turned back on, and the next one arrives.
        $preferences->set($me, PreferenceKey::AppointmentReminders, true);

        expect(app(NotificationCenter::class)->deliver(new NotificationRequest(
            type: NotificationType::AppointmentReminder,
            sourceType: 'appointment',
            sourceUuid: (string) Str::uuid(),
            recipients: [$me],
        )))->toBe(1);
    });
});

it('targets staff by permission and branch, never by role name', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        // A second branch, and a manager who works only in it.
        $other = $this->seedBranch('Karrada');
        $manager = $this->seedStaffMember(SystemRole::Manager, [(int) $other->getKey()]);

        $targets = app(StaffTargets::class);

        $ids = static fn (array $recipients): array => array_map(static fn (Recipient $r): int => $r->id, $recipients);

        // The owner holds the whole catalog as explicit grants and works
        // everywhere (ADR-029); the manager holds `review.manage` too, but only
        // in their own branch.
        expect($ids($targets->withPermission(Permission::ReviewManage)))
            ->toContain((int) $owner->getKey())
            ->toContain((int) $manager->getKey());

        expect($ids($targets->withPermission(Permission::ReviewManage, (int) $seed['branch']->getKey())))
            ->toContain((int) $owner->getKey());

        expect(in_array((int) $manager->getKey(), $ids($targets->withPermission(Permission::ReviewManage, (int) $seed['branch']->getKey())), true))
            ->toBeFalse('a branch-scoped manager was targeted for another branch');

        expect($ids($targets->withPermission(Permission::ReviewManage, (int) $other->getKey())))
            ->toContain((int) $manager->getKey());

        /*
         * And a permission the manager does not hold reaches them nowhere.
         * `audit.view` is the manager role's documented exclusion: somebody who
         * can rewrite history is not a control (docs/06 §4).
         */
        expect(in_array((int) $manager->getKey(), $ids($targets->withPermission(Permission::AuditView)), true))
            ->toBeFalse('a manager was targeted for a permission they do not hold');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
