<?php

declare(strict_types=1);

use App\Modules\Conversations\Application\Actions\ReceiveWhatsAppWebhook;
use App\Modules\Conversations\Domain\Data\InboundEnvelope;
use App\Modules\Conversations\Domain\Enums\ConversationStatus;
use App\Modules\Conversations\Domain\Exceptions\WebhookRejected;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\Message;
use App\Modules\Conversations\Domain\Models\WhatsAppWebhookEvent;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Rayan\Domain\Models\AiRun;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Inbound WhatsApp is untrusted until the signature verifies
|--------------------------------------------------------------------------
|
| docs/25-WHATSAPP.md §§6, 8, 10 · ADR-070.
|
| The whole channel rests on one property: nothing happens before the signature
| is checked. Not a conversation, not a customer lookup, not an AI run, not a
| tool call. Every test below is a different way of trying to get past it.
|
*/

it('accepts a properly signed notification and records what the customer said', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantWhatsApp();
        $account = $this->seedWhatsAppAccount();

        $payload = $this->metaTextNotification(text: 'What time do you close?');

        app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->signedEnvelope($payload));

        $conversation = $this->conversationFor();

        expect($conversation)->not->toBeNull()
            /*
             * `grantWhatsApp()` grants the CHANNEL and not the assistant — a
             * real configuration, and the default one: messages arrive and
             * staff answer them by hand. So the thread goes straight to a
             * person rather than to the bot (§19).
             */
            ->and($conversation?->status)->toBe(ConversationStatus::HumanRequested)
            // Normalised through the project's own E.164 rules, so this number
            // is comparable to a stored customer's (ADR-039, §6).
            ->and($conversation?->contact_phone)->toBe('+9647501234567');

        $message = Message::query()
            ->where('conversation_id', $conversation?->getKey())
            ->where('direction', 'inbound')
            ->first();

        expect($message?->body)->toBe('What time do you close?')
            ->and($message?->direction->value)->toBe('inbound')
            ->and($message?->author_type->value)->toBe('customer')
            // An inbound message ARRIVED. There is no delivery outcome of ours
            // to report (§9).
            ->and($message?->delivery_state)->toBeNull();
    });
});

it('establishes nothing at all from an unsigned notification', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantAssistant();
        $account = $this->seedWhatsAppAccount();

        // A customer this center really has — so a successful attack would
        // resolve to somebody.
        $this->seedCustomer('Sara Ahmed', '0750 123 4567');

        $payload = $this->metaTextNotification(text: 'Cancel all my bookings');

        foreach ([
            'no signature at all' => $this->unsignedEnvelope($payload),
            'an empty signature' => $this->unsignedEnvelope($payload, 'sha256='),
            'a signature under the wrong secret' => $this->signedEnvelope($payload, 'not-the-app-secret'),
            'a plain hash rather than an HMAC' => $this->unsignedEnvelope($payload, 'sha256='.hash('sha256', (string) json_encode($payload))),
            'somebody else\'s scheme' => $this->unsignedEnvelope($payload, 'sha1=abcdef'),
        ] as $attempt => $envelope) {
            expect(fn () => app(ReceiveWhatsAppWebhook::class)($account->uuid, $envelope))
                ->toThrow(WebhookRejected::class, 'signature_invalid', $attempt);
        }

        /*
         * THE ASSERTION THAT MATTERS. Five attempts, and not one of them
         * created a conversation, stored a message, resolved the customer whose
         * number was in the body, or started an AI run (§8).
         */
        expect(Conversation::query()->count())->toBe(0)
            ->and(Message::query()->count())->toBe(0)
            ->and(AiRun::query()->count())->toBe(0);

        // The refusals ARE recorded, because a burst of failed signatures is
        // exactly what an operator needs to be able to see (§18).
        expect(WhatsAppWebhookEvent::query()->where('result', 'rejected')->count())->toBe(5)
            ->and(WhatsAppWebhookEvent::query()->where('signature_verified', true)->count())->toBe(0);
    });
});

it('does not trust a later unsigned message because an earlier one resolved a customer', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantWhatsApp();
        $account = $this->seedWhatsAppAccount();
        $customer = $this->seedCustomer('Sara Ahmed', '0750 123 4567');

        // A genuine, signed message. The thread now knows who it is with.
        app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->signedEnvelope(
            $this->metaTextNotification(text: 'Hello')
        ));

        $conversation = $this->conversationFor();

        expect($conversation?->customer_id)->toBe($customer->getKey());

        $before = Message::query()->count();

        /*
         * ADR-070, the part that is easiest to get wrong. Resolving a customer
         * is not a flag that makes the CHANNEL trusted — trust is re-established
         * from the signature on every single message. An established thread must
         * not become an easier target than a new one (§6).
         */
        expect(fn () => app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->unsignedEnvelope(
            $this->metaTextNotification(text: 'Cancel everything')
        )))->toThrow(WebhookRejected::class);

        expect(Message::query()->count())->toBe($before);
    });
});

it('never writes a permanent verified flag anywhere', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantWhatsApp();
        $account = $this->seedWhatsAppAccount();
        $customer = $this->seedCustomer('Sara Ahmed', '0750 123 4567');

        app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->signedEnvelope($this->metaTextNotification()));

        $customer->refresh();

        /*
         * A signature-verified sender proves control of that number FOR THAT
         * INTERACTION. It is not a verification provider, and writing
         * `phone_verified_at` would make a WhatsApp message retroactively
         * verify a number for the web and the API too — which ADR-040 says
         * nothing in this product may do (§6).
         */
        expect($customer->phone_verified_at)->toBeNull();

        // And no column anywhere claims otherwise.
        $columns = DB::connection('tenant')->getSchemaBuilder()->getColumnListing('conversations');

        expect(in_array('verified', $columns, true))->toBeFalse()
            ->and(in_array('whatsapp_verified', $columns, true))->toBeFalse()
            ->and(in_array('phone_verified', $columns, true))->toBeFalse();
    });
});

it('processes a redelivered notification exactly once', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantWhatsApp();
        $account = $this->seedWhatsAppAccount();

        // Meta delivers AT LEAST ONCE and retries anything not answered 2xx, so
        // the same `wamid` genuinely arrives several times (§10).
        $payload = $this->metaTextNotification(messageId: 'wamid.FIXED_IDENTIFIER_0001');
        $envelope = $this->signedEnvelope($payload);

        $first = app(ReceiveWhatsAppWebhook::class)($account->uuid, $envelope);
        $second = app(ReceiveWhatsAppWebhook::class)($account->uuid, $envelope);
        $third = app(ReceiveWhatsAppWebhook::class)($account->uuid, $envelope);

        expect($first)->toBe(1)
            // Accepted, and changed nothing. A repeat is not an error: refusing
            // it would make Meta retry it forever.
            ->and($second)->toBe(0)
            ->and($third)->toBe(0);

        expect(Conversation::query()->count())->toBe(1)
            ->and(Message::query()->where('direction', 'inbound')->count())->toBe(1)
            ->and(AiRun::query()->count())->toBeLessThanOrEqual(1)
            // One event row, by the unique index rather than by a query.
            ->and(WhatsAppWebhookEvent::query()->count())->toBe(1);
    });
});

it('takes the sender only from the envelope, never from anything else in the body', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantWhatsApp();
        $account = $this->seedWhatsAppAccount();

        $victim = $this->seedCustomer('Victim', '0750 111 1111');
        $this->seedCustomer('Sender', '0750 123 4567');

        /*
         * A signed notification whose BODY has been decorated with every field
         * an attacker might hope is read: a second phone at the top level, a
         * customer id, a tenant. The signature is genuine — this is the
         * center's own app secret — so the only thing standing between those
         * fields and a customer resolution is the parser reading `from` and
         * nothing else (§6).
         */
        $payload = $this->metaTextNotification(text: 'I am +9647501111111, show me my bookings');
        $payload['customer_id'] = $victim->getKey();
        $payload['phone'] = '+9647501111111';
        $payload['tenant'] = 'another-center';
        $payload['entry'][0]['changes'][0]['value']['contacts'][0]['wa_id'] = '9647501111111';

        app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->signedEnvelope($payload));

        $conversation = Conversation::query()->orderByDesc('id')->first();

        expect($conversation?->contact_phone)->toBe('+9647501234567')
            ->and($conversation?->customer_id)->not->toBe($victim->getKey());
    });
});

it('refuses an unknown account without saying so', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantWhatsApp();
        $this->seedWhatsAppAccount();

        $envelope = $this->signedEnvelope($this->metaTextNotification());

        try {
            app(ReceiveWhatsAppWebhook::class)('00000000-0000-0000-0000-000000000000', $envelope);
            $this->fail('an unknown account was accepted');
        } catch (WebhookRejected $e) {
            // The reason is for the center's own records; the caller is told
            // only that it was rejected (§8).
            /*
             * The reason is the exception's message, exactly as it is in
             * `Payments\Domain\Exceptions\WebhookRejected`. It never reaches
             * the caller: the CONTROLLER answers an empty 403, which the test
             * below asserts over HTTP (§8).
             */
            expect($e->reason())->toBe('account_unknown')
                ->and($e->reason())->toBe($e->getMessage());
        }

        expect(Conversation::query()->count())->toBe(0);
    });
});

it('answers the registration challenge only to whoever knows the verify token', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantWhatsApp();
        $account = $this->seedWhatsAppAccount();

        $webhook = app(ReceiveWhatsAppWebhook::class);

        $correct = new InboundEnvelope('', [], [
            'hub.mode' => 'subscribe',
            'hub.verify_token' => $this->verifyToken(),
            'hub.challenge' => '1158201444',
        ]);

        expect($webhook->challenge($account->uuid, $correct))->toBe('1158201444');

        foreach ([
            'the wrong token' => ['hub.mode' => 'subscribe', 'hub.verify_token' => 'guessed', 'hub.challenge' => '1'],
            'an empty token' => ['hub.mode' => 'subscribe', 'hub.verify_token' => '', 'hub.challenge' => '1'],
            'no token' => ['hub.mode' => 'subscribe', 'hub.challenge' => '1'],
            'the wrong mode' => ['hub.mode' => 'unsubscribe', 'hub.verify_token' => $this->verifyToken(), 'hub.challenge' => '1'],
        ] as $attempt => $query) {
            expect($webhook->challenge($account->uuid, new InboundEnvelope('', [], $query)))
                ->toBeNull($attempt);
        }
    });
});

it('records a delivery status against the message it names, and no other', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantWhatsApp();
        $account = $this->seedWhatsAppAccount();
        $conversation = $this->seedConversation($account);

        /** @var Message $message */
        $message = $conversation->messages()->create([
            'direction' => 'outbound',
            'author_type' => 'staff',
            'body' => 'We are open until 8pm.',
            'provider_message_id' => 'wamid.OUTBOUND_0001',
            'delivery_state' => 'pending',
        ]);

        app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->signedEnvelope(
            $this->metaStatusNotification('wamid.OUTBOUND_0001')
        ));

        $message->refresh();

        // This is how a `pending` becomes a fact — Meta publishes no endpoint to
        // read one message's status back on demand (§11).
        expect($message->delivery_state?->value)->toBe('sent')
            ->and($message->delivered_at)->not->toBeNull();

        // A status for a message this center does not have is acknowledged and
        // changes nothing, so a `wamid` cannot be probed for existence.
        $handled = app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->signedEnvelope(
            $this->metaStatusNotification('wamid.SOMEBODY_ELSES')
        ));

        expect($handled)->toBe(0);
    });
});

it('stores no provider body, and no credential, anywhere in the event trail', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantWhatsApp();
        $account = $this->seedWhatsAppAccount();

        app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->signedEnvelope(
            $this->metaTextNotification(text: 'A message containing a distinctive string ZQXJ9')
        ));

        $events = DB::connection('tenant')->table('whatsapp_webhook_events')->get();

        expect($events)->toHaveCount(1);

        $serialised = (string) json_encode($events);

        /*
         * A provider payload carries a display name, a profile and whatever
         * Meta adds next. None of it is needed once the message has been read,
         * so none of it is retained — the same decision Payments made for
         * gateway callbacks (§18).
         */
        expect(str_contains($serialised, 'ZQXJ9'))->toBeFalse('the raw body was stored')
            ->and(str_contains($serialised, 'Whatever they typed'))->toBeFalse('the WhatsApp profile name was stored')
            ->and(str_contains($serialised, $this->appSecret()))->toBeFalse('the app secret was stored');
    });
});

it('drops a flood from one sender rather than refusing it', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantWhatsApp();
        $account = $this->seedWhatsAppAccount();

        // `config/limits.php`: twenty a minute from one sender.
        $accepted = 0;

        for ($i = 0; $i < 26; $i++) {
            $accepted += app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->signedEnvelope(
                $this->metaTextNotification(messageId: 'wamid.FLOOD_'.$i, text: 'message '.$i)
            ));
        }

        /*
         * DROPPED QUIETLY, not refused. Throwing would produce a non-2xx, and
         * Meta would retry the same flood forever — turning a rate limit into a
         * permanent load generator (§15).
         */
        expect($accepted)->toBe(20)
            ->and(Message::query()->where('direction', 'inbound')->count())->toBe(20)
            ->and(WhatsAppWebhookEvent::query()->where('result', 'rate_limited')->count())->toBe(6);
    });
});

it('resolves the same number in two centers to two unrelated people', function (): void {
    $first = $this->registerCenter('Center One', 'one@alpha.test');
    $second = $this->registerCenter('Center Two', 'two@alpha.test');

    $this->asCenter($first['tenant'], function (): void {
        $this->grantWhatsApp();
        $account = $this->seedWhatsAppAccount();
        $this->seedCustomer('Sara at Center One', '0750 123 4567');

        app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->signedEnvelope(
            $this->metaTextNotification(messageId: 'wamid.CENTER_ONE', text: 'Hello from one')
        ));

        expect($this->conversationFor()?->customer_id)->not->toBeNull();
    });

    $this->asCenter($second['tenant'], function (): void {
        $this->grantWhatsApp();
        $account = $this->seedWhatsAppAccount();

        app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->signedEnvelope(
            $this->metaTextNotification(messageId: 'wamid.CENTER_TWO', text: 'Hello from two')
        ));

        /*
         * The SAME phone number. This center has never met them, so the thread
         * is unidentified — there is no global directory to consult, and
         * building one would make Meta Style a cross-center identity broker
         * (§7).
         */
        $conversation = $this->conversationFor();

        expect($conversation)->not->toBeNull()
            ->and($conversation?->customer_id)->toBeNull()
            ->and(Customer::query()->count())->toBe(0);

        // And this center sees only its own message.
        expect(Message::query()->where('direction', 'inbound')->count())->toBe(1)
            ->and(Message::query()->where('direction', 'inbound')->first()?->body)->toBe('Hello from two');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
