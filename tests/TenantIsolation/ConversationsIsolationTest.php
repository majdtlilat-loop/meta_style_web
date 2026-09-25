<?php

declare(strict_types=1);

use App\Kernel\Tenancy\Exceptions\TenantConnectionNotInitialized;
use App\Kernel\Usage\Models\UsageCounter;
use App\Kernel\Usage\Models\UsageEvent;
use App\Kernel\Usage\Usage;
use App\Modules\Booking\Application\BookingLookup;
use App\Modules\Conversations\Application\Actions\ReceiveWhatsAppWebhook;
use App\Modules\Conversations\Application\WhatsAppConnections;
use App\Modules\Conversations\Domain\Exceptions\WebhookRejected;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\Message;
use App\Modules\Conversations\Domain\Models\WhatsAppAccount;
use App\Modules\Conversations\Domain\Models\WhatsAppWebhookEvent;
use App\Modules\Rayan\Application\CustomerContext;
use App\Modules\Rayan\Domain\Models\AiRun;
use App\Modules\Rayan\Domain\Models\AiToolCall;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Conversations, RAYAN and usage — tenant isolation
|--------------------------------------------------------------------------
|
| docs/02-TENANCY.md · docs/25-WHATSAPP.md §§6–7 · docs/26-USAGE-QUOTAS.md §5.
|
| A conversation belongs to the center whose number it arrived at; a usage
| counter to the center that spent it; a booking code to the center that issued
| it. None of them is reachable from anywhere else, and with no center bound
| every query fails rather than answering from somewhere.
|
| This is a RELEASE GATE. Never skipped.
|
*/

it('keeps every Phase 13 table unreachable with no center bound', function (): void {
    /*
     * FAIL CLOSED. An unbound query must throw, never fall back to the control
     * database or to whichever center happened to be bound last
     * (docs/02-TENANCY.md §1).
     */
    foreach ([
        'whatsapp accounts' => fn () => WhatsAppAccount::query()->count(),
        'conversations' => fn () => Conversation::query()->count(),
        'messages' => fn () => Message::query()->count(),
        'webhook events' => fn () => WhatsAppWebhookEvent::query()->count(),
        'ai runs' => fn () => AiRun::query()->count(),
        'ai tool calls' => fn () => AiToolCall::query()->count(),
        'usage events' => fn () => UsageEvent::query()->count(),
        'usage counters' => fn () => UsageCounter::query()->count(),
    ] as $what => $query) {
        expect($query)->toThrow(TenantConnectionNotInitialized::class, null, $what);
    }
});

it('puts every Phase 13 table in the tenant database and none in the control plane', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');

    $tenantTables = [
        'whatsapp_accounts', 'conversations', 'messages', 'whatsapp_webhook_events',
        'ai_runs', 'ai_tool_calls', 'usage_events', 'usage_counters', 'usage_alerts',
    ];

    $this->asCenter($alpha['tenant'], function () use ($tenantTables): void {
        foreach ($tenantTables as $table) {
            expect(Schema::connection('tenant')->hasTable($table))->toBeTrue($table);

            // No `tenant_id` anywhere: the connection has already answered that
            // question, and a column repeating it is a second source of truth.
            expect(Schema::connection('tenant')->hasColumn($table, 'tenant_id'))
                ->toBeFalse($table.' carries a tenant_id');
        }
    });

    foreach ($tenantTables as $table) {
        expect(Schema::connection('control')->hasTable($table))->toBeFalse($table.' leaked into the control plane');
    }

    // And the control plane holds only what is genuinely platform-wide.
    foreach (['plan_limits', 'tenant_limit_overrides', 'tenant_usage_projections'] as $table) {
        expect(Schema::connection('control')->hasTable($table))->toBeTrue($table);
    }
});

it('never lets one center see another conversation, message or run', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $this->asCenter($alpha['tenant'], function (): void {
        $this->grantWhatsApp();
        $account = $this->seedWhatsAppAccount();
        $this->ownerWithCatalogAccess();

        app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->signedEnvelope(
            $this->metaTextNotification(messageId: 'wamid.ALPHA_1', text: 'A secret only Alpha should hold')
        ));

        app(Usage::class)->consume('ai_runs', 'ai_run', 'alpha-run-1');

        expect(Conversation::query()->count())->toBe(1)
            ->and(WhatsAppAccount::query()->count())->toBe(1);
    });

    $this->asCenter($beta['tenant'], function (): void {
        // Beta's database is its own. Nothing of Alpha's is queryable from
        // here — not by uuid, not by phone, not at all.
        expect(Conversation::query()->count())->toBe(0)
            ->and(Message::query()->count())->toBe(0)
            ->and(WhatsAppAccount::query()->count())->toBe(0)
            ->and(WhatsAppWebhookEvent::query()->count())->toBe(0)
            ->and(AiRun::query()->count())->toBe(0)
            ->and(AiToolCall::query()->count())->toBe(0)
            ->and(UsageEvent::query()->count())->toBe(0)
            ->and(UsageCounter::query()->count())->toBe(0);

        expect(Message::query()->where('body', 'like', '%only Alpha%')->count())->toBe(0);
    });
});

it('refuses a notification addressed to another center\'s WhatsApp account', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    /** @var string $alphaAccountUuid */
    $alphaAccountUuid = $this->asCenter($alpha['tenant'], function (): string {
        $this->grantWhatsApp();

        return $this->seedWhatsAppAccount()->uuid;
    });

    $this->asCenter($beta['tenant'], function () use ($alphaAccountUuid): void {
        $this->grantWhatsApp();
        $this->seedWhatsAppAccount();

        /*
         * A correctly SIGNED notification — Beta uses the same test app secret
         * — naming ALPHA's account uuid, delivered while Beta is bound.
         *
         * The account lookup runs on Beta's connection, so Alpha's uuid is
         * simply not there. The center is resolved from the URL's public key
         * before any of this, and the account from within that center: there is
         * no path by which an account identifier crosses a database
         * (docs/25-WHATSAPP.md §8).
         */
        expect(fn () => app(ReceiveWhatsAppWebhook::class)(
            $alphaAccountUuid,
            $this->signedEnvelope($this->metaTextNotification(text: 'Cross-tenant attempt')),
        ))->toThrow(WebhookRejected::class, 'account_unknown');

        expect(Conversation::query()->count())->toBe(0)
            ->and(Message::query()->count())->toBe(0);
    });

    // And nothing was written into Alpha either.
    $this->asCenter($alpha['tenant'], function (): void {
        expect(Conversation::query()->count())->toBe(0)
            ->and(WhatsAppWebhookEvent::query()->count())->toBe(0);
    });
});

it('resolves the same phone number to two unrelated people', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $this->asCenter($alpha['tenant'], function (): void {
        $this->grantWhatsApp();
        $account = $this->seedWhatsAppAccount();
        $this->seedCustomer('Sara at Alpha', '0750 123 4567');

        app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->signedEnvelope(
            $this->metaTextNotification(messageId: 'wamid.ALPHA_P', text: 'Hello Alpha')
        ));

        expect($this->conversationFor()?->customer_id)->not->toBeNull();
    });

    $this->asCenter($beta['tenant'], function (): void {
        $this->grantWhatsApp();
        $account = $this->seedWhatsAppAccount();

        // Beta has never met this number.
        $this->seedCustomer('Someone else entirely', '0750 999 9999');

        app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->signedEnvelope(
            $this->metaTextNotification(messageId: 'wamid.BETA_P', text: 'Hello Beta')
        ));

        /*
         * UNIDENTIFIED, and that is correct. There is no global directory to
         * consult, and building one would make Meta Style a cross-center
         * identity broker (ADR-070).
         */
        expect($this->conversationFor()?->customer_id)->toBeNull();
    });
});

it('never opens a booking with a code minted at another center', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    /** @var array{reference: string, code: string} $alphaBooking */
    $alphaBooking = $this->asCenter($alpha['tenant'], function (): array {
        $this->grantEntitlement('booking', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $booked = $this->bookWithCode($seed, $owner);

        return ['reference' => (string) $booked['appointment']->reference, 'code' => $booked['code']];
    });

    $this->asCenter($beta['tenant'], function () use ($alphaBooking): void {
        $this->grantEntitlement('booking', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        // Beta has its own booking, which happens to carry the SAME reference —
        // references are per-center sequences, so `B-000001` exists in both.
        $betaBooking = $this->bookWithCode($seed, $owner);

        expect($betaBooking['appointment']->reference)->toBe($alphaBooking['reference']);

        $lookup = app(BookingLookup::class);

        /*
         * Alpha's CODE against the identical reference finds nothing here. The
         * digests differ because the codes do; the lookup runs on Beta's
         * connection; and the answer is the same null a wrong code gets
         * (docs/24-BOOKING-VERIFICATION.md §9).
         */
        expect($lookup->byCode($alphaBooking['reference'], $alphaBooking['code'], '+9647501234567'))
            ->toBeNull();

        // Beta's own code still opens Beta's own booking.
        expect($lookup->byCode($betaBooking['appointment']->reference, $betaBooking['code'], '+9647501230000')?->getKey())
            ->toBe($betaBooking['appointment']->getKey());
    });
});

it('builds customer context only from the bound center', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    /** @var int $alphaCustomerId */
    $alphaCustomerId = $this->asCenter($alpha['tenant'], function (): int {
        $this->grantEntitlement('booking', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $customer = $this->seedCustomer('Sara at Alpha', '0750 123 4567');
        $this->bookFor($seed, $owner, $customer);

        $context = app(CustomerContext::class)->for((int) $customer->getKey());

        expect($context)->not->toBeNull()
            ->and($context['name'])->toBe('Sara at Alpha')
            ->and($context['upcoming_bookings'])->toHaveCount(1);

        return (int) $customer->getKey();
    });

    $this->asCenter($beta['tenant'], function () use ($alphaCustomerId): void {
        $this->grantEntitlement('booking', null);
        $this->seedBookableCenter();
        $this->ownerWithCatalogAccess();

        /*
         * The SAME primary key, in a different database. Beta has no such
         * customer, so the context is null rather than Alpha's — the isolation
         * is the connection, not a filter anybody had to remember
         * (docs/27-RAYAN.md §10).
         */
        expect(app(CustomerContext::class)->for($alphaCustomerId))->toBeNull();
    });
});

it('counts one center\'s usage into its own database only', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $this->asCenter($alpha['tenant'], function (): void {
        $usage = app(Usage::class);

        for ($i = 0; $i < 9; $i++) {
            $usage->consume('ai_runs', 'ai_run', 'alpha-'.$i);
        }

        $usage->meter('wa_inbound', 'wa_inbound', 'alpha-message');

        expect($usage->counter('ai_runs')->used)->toBe(9)
            ->and($usage->counter('wa_inbound')->used)->toBe(1);
    });

    $this->asCenter($beta['tenant'], function (): void {
        $usage = app(Usage::class);

        /*
         * Beta starts at zero, with its own full allowance. A shared counter
         * would mean one busy center exhausting another's plan — which is the
         * failure the tenant-side counter exists to make impossible
         * (docs/26-USAGE-QUOTAS.md §5).
         */
        expect($usage->counter('ai_runs')->used)->toBe(0)
            ->and($usage->counter('ai_runs')->allowance_snapshot)->toBe(500)
            ->and($usage->counter('wa_inbound')->used)->toBe(0);

        // The SAME source identity is a different fact here, because the
        // unique index is per database.
        $usage->consume('ai_runs', 'ai_run', 'alpha-0');

        expect($usage->counter('ai_runs')->used)->toBe(1);
    });

    // Alpha is untouched by any of it.
    $this->asCenter($alpha['tenant'], function (): void {
        expect(app(Usage::class)->counter('ai_runs')->used)->toBe(9);
    });
});

it('composes a webhook URL from the bound center, never another', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    /** @var string $alphaUrl */
    $alphaUrl = $this->asCenter($alpha['tenant'], function (): string {
        $this->grantWhatsApp();

        return app(WhatsAppConnections::class)->webhookUrl($this->seedWhatsAppAccount());
    });

    /** @var string $betaUrl */
    $betaUrl = $this->asCenter($beta['tenant'], function (): string {
        $this->grantWhatsApp();

        return app(WhatsAppConnections::class)->webhookUrl($this->seedWhatsAppAccount());
    });

    /*
     * Each carries its OWN center and its own account uuid. Since Phase 15 that
     * is the slug its host resolved (asCenter sets it, as ResolveTenant does):
     * ResolvePublicTenant accepts a public route only with the host's slug in
     * the path, so the public-key form no longer resolves — the Payments
     * callback rule (docs/25 §8). Never the other center's slug or key.
     */
    $alphaSlug = $alpha['registration']->requested_slug;
    $betaSlug = $beta['registration']->requested_slug;

    expect($alphaUrl)->not->toBe($betaUrl)
        ->and($alphaUrl)->toContain('/whatsapp/'.$alphaSlug.'/accounts/')
        ->and($betaUrl)->toContain('/whatsapp/'.$betaSlug.'/accounts/')
        ->and($alphaUrl)->not->toContain('/whatsapp/'.$betaSlug.'/')
        ->and($alphaUrl)->not->toContain($this->publicKeyOf($beta['tenant']));
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
