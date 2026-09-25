<?php

declare(strict_types=1);

use App\Kernel\Usage\Usage;
use App\Kernel\Usage\UsageProjector;
use App\Modules\Conversations\Application\ConversationPresenter;
use App\Modules\Conversations\Application\ConversationsQuery;
use App\Modules\Conversations\Domain\Enums\ConversationStatus;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Rayan\Application\CustomerContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Query budgets — the inbox, the timeline, the dashboard, the projection
|--------------------------------------------------------------------------
|
| docs/25-WHATSAPP.md §17 · docs/26-USAGE-QUOTAS.md §§9, 14.
|
| Every screen here grows with something a busy center accumulates: threads,
| messages, resources, centers. Each is asserted as "does not grow", not as a
| magic number — a presenter that resolved a customer per row fails the day it
| is written, rather than on the afternoon a center gets busy.
|
*/

/**
 * How many TENANT queries `$work` costs.
 *
 * Filtered by connection name on purpose. `Connection::listen()` registers on
 * the application's shared event dispatcher, so a listener attached to one
 * connection is handed every other connection's queries too — an earlier
 * version of this helper counted the control plane's reads as the tenant's and
 * reported exactly double for one screen.
 */
function conversationQueries(callable $work): int
{
    $count = 0;

    DB::connection('tenant')->listen(function (QueryExecuted $query) use (&$count): void {
        if ($query->connectionName === 'tenant') {
            $count++;
        }
    });

    $work();

    return $count;
}

it('costs the same to list three conversations as to list twelve', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantWhatsApp();
        $account = $this->seedWhatsAppAccount();
        $owner = $this->ownerWithCatalogAccess();

        $query = app(ConversationsQuery::class);
        $presenter = app(ConversationPresenter::class);

        $list = function () use ($query, $presenter, $owner): void {
            foreach ($query->inbox($owner) as $conversation) {
                $presenter->summary($conversation, $owner);
            }

            $query->waitingCount($owner);
        };

        $open = function (int $from, int $to) use ($account): void {
            for ($i = $from; $i <= $to; $i++) {
                $customer = $this->seedCustomer('Customer '.$i, '0750 900 '.str_pad((string) $i, 4, '0', STR_PAD_LEFT));

                $this->seedConversation(
                    $account,
                    ConversationStatus::HumanRequested,
                    customerId: (int) $customer->getKey(),
                    phone: '+964750900'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                );
            }
        };

        $open(1, 3);

        /*
         * Warmed first. `TenantLocales` is `scoped`, so the very first read in
         * a request also pays for the center's language settings — measuring a
         * cold cache against a warm one shows the count FALLING as rows are
         * added, which says nothing about whether the page is O(1).
         */
        $list();

        $baseline = conversationQueries($list);

        $open(4, 12);

        $after = conversationQueries($list);

        /*
         * The conversations, their customers, branches and assignees eager
         * loaded, plus the waiting count. None of it per row, which is the
         * whole claim — an inbox is opened on every page load.
         */
        expect($baseline)->toBe($after)
            ->and($baseline)->toBeLessThanOrEqual(6);
    });
});

it('costs the same to read a thread of five messages as one of forty', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantWhatsApp();
        $account = $this->seedWhatsAppAccount();
        $owner = $this->ownerWithCatalogAccess();

        $conversation = $this->seedConversation($account);

        $query = app(ConversationsQuery::class);
        $presenter = app(ConversationPresenter::class);

        $say = function (int $n) use ($conversation): void {
            for ($i = 0; $i < $n; $i++) {
                $conversation->messages()->create([
                    'direction' => $i % 2 === 0 ? 'inbound' : 'outbound',
                    'author_type' => $i % 2 === 0 ? 'customer' : 'staff',
                    'author_user_id' => $i % 2 === 0 ? null : 1,
                    'body' => 'message '.$i,
                    'delivery_state' => $i % 2 === 0 ? null : 'sent',
                ]);
            }
        };

        $read = function () use ($query, $presenter, $conversation, $owner): void {
            $found = $query->find($owner, $conversation->uuid);

            if ($found instanceof Conversation) {
                $presenter->detail($found, $query->timeline($found), $owner);
            }
        };

        $say(5);
        $read();

        $baseline = conversationQueries($read);

        $say(35);

        $after = conversationQueries($read);

        // The thread and its authors in one go. A staff name resolved per
        // message would be forty queries to render one conversation.
        expect($baseline)->toBe($after)
            ->and($baseline)->toBeLessThanOrEqual(7);
    });
});

it('costs the same to show the usage dashboard whatever has been used', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $usage = app(Usage::class);

        $show = function () use ($usage): void {
            $usage->summaries('ai');
            $usage->summaries('whatsapp');
        };

        $show();

        $baseline = conversationQueries($show);

        // A month of real traffic: every resource touched, many times.
        foreach (['ai_runs', 'ai_input_tokens', 'ai_output_tokens', 'ai_tool_calls', 'ai_failed_runs'] as $resource) {
            for ($i = 0; $i < 10; $i++) {
                $usage->meter($resource, 'test', $resource.'-'.$i, 3);
            }
        }

        foreach (['wa_inbound', 'wa_outbound', 'wa_template', 'wa_failed'] as $resource) {
            for ($i = 0; $i < 10; $i++) {
                $usage->meter($resource, 'test', $resource.'-'.$i);
            }
        }

        $after = conversationQueries($show);

        /*
         * One read per resource, and NOT a scan of `usage_events`. The counter
         * is the number; summing a growing append-only table to draw a
         * dashboard gets slower exactly as a center gets busier (§5).
         */
        expect($baseline)->toBe($after)
            ->and($baseline)->toBeLessThanOrEqual(9);
    });
});

it('projects usage without a query per event', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $usage = app(Usage::class);
        $projector = app(UsageProjector::class);

        /*
         * BOTH resources touched first, so every counter row the projection
         * will ever read already exists. Measuring against a center that had
         * only ever run the assistant would show the count rising when the
         * WhatsApp counter appeared, which is growth in RESOURCES — the
         * catalog, a fixed list — and says nothing about traffic.
         */
        $usage->consume('ai_runs', 'ai_run', 'warm-up');
        $usage->meter('wa_inbound', 'wa_inbound', 'warm-up');

        $projector->project();

        $baseline = conversationQueries(fn () => $projector->project());

        // Forty times the traffic, through the same two resources.
        for ($i = 0; $i < 40; $i++) {
            $usage->consume('ai_runs', 'ai_run', 'run-'.$i);
            $usage->meter('wa_inbound', 'wa_inbound', 'in-'.$i);
        }

        $after = conversationQueries(fn () => $projector->project());

        /*
         * IDENTICAL. The projection reads COUNTERS, one per resource, and never
         * the append-only events behind them — which is what makes an hourly
         * platform-wide pass affordable as centers get busy (§9).
         */
        expect($after)->toBe($baseline);
    });
});

it('builds customer context in a flat number of queries', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('booking', null);
        $this->grantPackages();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $customer = $this->seedCustomer('Sara Ahmed', '0750 123 4567');

        $context = app(CustomerContext::class);
        $build = fn () => $context->for((int) $customer->getKey());

        $this->bookFor($seed, $owner, $customer, time: '10:00');

        $build();

        $baseline = conversationQueries($build);

        // A customer with more of everything.
        $this->bookFor($seed, $owner, $customer, daysAhead: 2, time: '11:00');
        $this->bookFor($seed, $owner, $customer, daysAhead: 3, time: '12:00');
        $this->paidPackage($seed, $owner, $customer);

        $after = conversationQueries($build);

        /*
         * Bounded by construction: two upcoming bookings, and the package
         * ledger read for every package in ONE query. This runs on every
         * message a known customer sends (§10).
         */
        expect($after)->toBeLessThanOrEqual($baseline + 3)
            ->and($baseline)->toBeLessThanOrEqual(10);
    });
});

it('counts waiting conversations without loading them', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantWhatsApp();
        $account = $this->seedWhatsAppAccount();
        $owner = $this->ownerWithCatalogAccess();

        $query = app(ConversationsQuery::class);
        $count = fn () => $query->waitingCount($owner);

        for ($i = 0; $i < 20; $i++) {
            $this->seedConversation(
                $account,
                ConversationStatus::HumanRequested,
                phone: '+964750800'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            );
        }

        $count();

        // The number in the navigation, on every page load: ONE indexed count,
        // never a collection counted in PHP.
        expect(conversationQueries($count))->toBe(1)
            ->and($count())->toBe(20);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
