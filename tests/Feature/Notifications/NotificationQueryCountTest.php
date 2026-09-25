<?php

declare(strict_types=1);

use App\Modules\Notifications\Application\Inbox;
use App\Modules\Notifications\Application\NotificationCenter;
use App\Modules\Notifications\Application\ReminderSweep;
use App\Modules\Notifications\Domain\Data\NotificationRequest;
use App\Modules\Notifications\Domain\Data\Recipient;
use App\Modules\Notifications\Domain\Enums\NotificationType;
use App\Modules\Notifications\Domain\Enums\RecipientKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Query budgets — the inbox, the unread count and the reminder sweep
|--------------------------------------------------------------------------
|
| docs/23-NOTIFICATIONS.md §§17–18.
|
| An inbox is opened on every page load and an unread count sits in the nav, so
| neither may grow with what is in it. The reminder sweep walks appointments and
| must not pay a query per row for a branch timezone that never changes.
|
*/

/**
 * How many queries `$work` costs, and how many of them matched `$needle`.
 *
 * @return array{0: int, 1: int}
 */
function inboxQueries(callable $work, string $needle = ''): array
{
    $total = 0;
    $matched = 0;

    DB::connection('tenant')->listen(function (QueryExecuted $query) use (&$total, &$matched, $needle): void {
        $total++;

        if ($needle !== '' && str_contains($query->sql, $needle)) {
            $matched++;
        }
    });

    $work();

    return [$total, $matched];
}

it('reads an inbox and its unread count in a flat number of queries', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantCustomerAccounts();
        $this->ownerWithCatalogAccess();
        $account = $this->seedCustomerAccount($this->seedCustomer());

        $me = new Recipient(RecipientKind::Customer, (int) $account->getKey());
        $notifications = app(NotificationCenter::class);
        $inbox = app(Inbox::class);

        $deliver = function (int $n) use ($notifications, $me): void {
            for ($i = 0; $i < $n; $i++) {
                $notifications->deliver(new NotificationRequest(
                    type: NotificationType::InvoiceIssued,
                    sourceType: 'invoice',
                    sourceUuid: (string) Str::uuid(),
                    params: ['number' => 'INV-00000'.$i],
                    recipients: [$me],
                ));
            }
        };

        $read = function () use ($inbox, $me): void {
            $inbox->page($me);
            $inbox->unreadCount($me);
        };

        $deliver(2);
        [$baseline] = inboxQueries($read);

        $deliver(8);
        [$after] = inboxQueries($read);

        // The page (with its notifications eager-loaded) and the count: the
        // same handful at two rows and at ten (§17).
        expect($baseline)->toBe($after)
            ->and($baseline)->toBeLessThanOrEqual(3)
            ->and($inbox->page($me))->toHaveCount(10);
    });
});

it('sweeps reminders without re-reading a branch timezone per appointment', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantCustomerAccounts();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $at = null;

        foreach ([['0750 123 4551', '10:00'], ['0750 123 4552', '11:00'], ['0750 123 4553', '12:00']] as [$phone, $time]) {
            $customer = $this->seedCustomer('Reminder '.$phone, $phone);
            $this->seedCustomerAccount($customer, 'correct-horse-'.$phone);
            $appointment = $this->bookFor($seed, $owner, $customer, time: $time);
            $at ??= CarbonImmutable::instance($appointment->starts_at)->subHours(20);
        }

        $this->travelTo($at);

        $sweep = app(ReminderSweep::class);

        [, $branchReads] = inboxQueries(static fn () => $sweep->sweep(), 'from `branches`');

        // Three appointments in ONE branch: its timezone is read once, not
        // once per row (§18).
        expect($branchReads)->toBeLessThanOrEqual(1);

        // Everything is written; a second pass finds nothing to do and costs
        // ONE query — the bounded, indexed candidate lookup (§13).
        [$idle] = inboxQueries(static fn () => $sweep->sweep());

        expect($idle)->toBe(1);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
