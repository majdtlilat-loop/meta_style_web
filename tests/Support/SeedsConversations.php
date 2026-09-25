<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceAddon;
use App\Modules\Conversations\Domain\Data\InboundEnvelope;
use App\Modules\Conversations\Domain\Enums\ConversationStatus;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\WhatsAppAccount;
use App\Modules\Conversations\Infrastructure\Providers\MetaWhatsAppCloudProvider;
use App\Modules\Employees\Domain\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeding for the WhatsApp channel and the assistant.
 *
 * Every method assumes a tenant is already bound — call them inside
 * `asCenter()`. A helper that bound its own tenant would hide exactly the
 * context mistakes the isolation tests exist to catch.
 *
 * ## The notification bodies are built, not stubbed
 *
 * {@see metaTextNotification()} produces the real shape Meta documents, and
 * {@see signedEnvelope()} signs it with the account's real app secret. A test
 * that stubbed the parser would prove nothing about the one thing that matters
 * on this surface: that an UNSIGNED body cannot establish anything
 * (docs/25-WHATSAPP.md §8).
 */
trait SeedsConversations
{
    /** The center's own app secret in tests. Fixed, so a signature is reproducible. */
    public const APP_SECRET = 'test-app-secret-value';

    public const VERIFY_TOKEN = 'test-verify-token';

    public const PHONE_NUMBER_ID = '106540352242922';

    protected function grantWhatsApp(?string $tenantId = null): void
    {
        // `whatsapp_booking` requires `booking`, and the closure would silently
        // drop it without one (docs/05-ENTITLEMENTS.md).
        $this->grantEntitlement('booking', $tenantId);
        $this->grantEntitlement('whatsapp_booking', $tenantId);
    }

    protected function grantAssistant(?string $tenantId = null): void
    {
        $this->grantWhatsApp($tenantId);
        $this->grantEntitlement('rayan_ai', $tenantId);
    }

    /**
     * A configured, enabled WhatsApp account for the bound center.
     */
    protected function seedWhatsAppAccount(bool $enabled = true): WhatsAppAccount
    {
        /** @var WhatsAppAccount $account */
        $account = WhatsAppAccount::query()->create([
            'provider' => MetaWhatsAppCloudProvider::CODE,
            'display_name' => 'Main line',
            'phone_number_id' => self::PHONE_NUMBER_ID,
            'business_account_id' => '102290129340398',
            'display_phone_number' => '15550783881',
            'enabled' => $enabled,
            'credentials' => [
                'access_token' => 'test-access-token',
                'app_secret' => self::APP_SECRET,
                'verify_token' => self::VERIFY_TOKEN,
            ],
            'configured_at' => CarbonImmutable::now()->utc(),
        ]);

        return $account;
    }

    /**
     * Meta's documented inbound TEXT notification, verified 2026-09-20.
     *
     * @return array<string, mixed>
     */
    protected function metaTextNotification(
        string $fromPhone = '9647501234567',
        string $text = 'Hello',
        ?string $messageId = null,
        string $phoneNumberId = self::PHONE_NUMBER_ID,
    ): array {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '102290129340398',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '15550783881',
                            'phone_number_id' => $phoneNumberId,
                        ],
                        'contacts' => [[
                            // Meta sends it; Meta Style deliberately ignores it.
                            'profile' => ['name' => 'Whatever they typed'],
                            'wa_id' => $fromPhone,
                        ]],
                        'messages' => [[
                            'from' => $fromPhone,
                            'id' => $messageId ?? 'wamid.'.Str::random(24),
                            'timestamp' => (string) CarbonImmutable::now()->getTimestamp(),
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    /**
     * Meta's documented delivery STATUS notification.
     *
     * @return array<string, mixed>
     */
    protected function metaStatusNotification(
        string $providerMessageId,
        string $status = 'delivered',
        string $phoneNumberId = self::PHONE_NUMBER_ID,
    ): array {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '102290129340398',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => $phoneNumberId],
                        'statuses' => [[
                            'id' => $providerMessageId,
                            'status' => $status,
                            'timestamp' => (string) CarbonImmutable::now()->getTimestamp(),
                            'recipient_id' => '9647501234567',
                            'conversation' => ['id' => Str::random(16), 'origin' => ['type' => 'service']],
                            // Meta's own words about what it charged. Recorded,
                            // never derived (§13).
                            'pricing' => ['billable' => true, 'pricing_model' => 'CBP', 'category' => 'service'],
                            'errors' => [],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    /**
     * A notification signed the way Meta signs one: HMAC-SHA256 over the RAW
     * BODY with the app secret, hex, prefixed `sha256=`.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function signedEnvelope(array $payload, ?string $secret = null): InboundEnvelope
    {
        $raw = (string) json_encode($payload);

        return new InboundEnvelope(
            rawBody: $raw,
            headers: ['x-hub-signature-256' => 'sha256='.hash_hmac('sha256', $raw, $secret ?? self::APP_SECRET)],
        );
    }

    /**
     * The same body with NO usable signature — the case every security test on
     * this surface turns on.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function unsignedEnvelope(array $payload, ?string $signature = null): InboundEnvelope
    {
        $raw = (string) json_encode($payload);

        return new InboundEnvelope(
            rawBody: $raw,
            headers: $signature === null ? [] : ['x-hub-signature-256' => $signature],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function signedHeaders(array $payload, ?string $secret = null): array
    {
        return [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', (string) json_encode($payload), $secret ?? self::APP_SECRET),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * A booking AND the raw verification code it was created with.
     *
     * `bookFor()` unwraps to the appointment, which is what almost every test
     * wants. The verification tests want the other half — the one-time secret
     * that exists in the return of the creating call and nowhere else
     * (docs/24-BOOKING-VERIFICATION.md §3).
     *
     * @param  array{branch: Branch, service: Service, employee: Employee, addon: ServiceAddon}  $seed
     * @return array{appointment: Appointment, code: string}
     */
    protected function bookWithCode(
        array $seed,
        User $user,
        string $phone = '0750 123 4567',
        string $time = '10:00',
        string $name = 'Sara Ahmed',
    ): array {
        $result = app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine(serviceUuid: $seed['service']->uuid)],
                // The branch's tomorrow, never the UTC one.
                startsAt: $this->localTime($seed['branch'], CarbonImmutable::now($seed['branch']->timezone)->addDay()->toDateString(), $time),
                customer: CustomerRef::details($name, $phone),
            ),
            BookingActor::staff($user),
        );

        return ['appointment' => $result->appointment, 'code' => (string) $result->verificationCode];
    }

    /**
     * The center's own app secret and verify token, as accessors.
     *
     * Tests read them through a method rather than the constant so that a
     * future per-center fixture can vary them without every assertion being
     * rewritten.
     */
    /**
     * Sets this center's allowance for a metered resource, and makes the
     * current period's counter reflect it.
     *
     * Writes the control-plane override and then FORGETS the counter row, so
     * the next read resolves a fresh snapshot — which is what a center's first
     * use of the period would do anyway. Editing the snapshot directly would
     * test a state the application never produces
     * (docs/26-USAGE-QUOTAS.md §7).
     */
    protected function setAllowance(string $resource, ?int $allowance): void
    {
        $tenantId = (string) app(TenantContext::class)->require()->id;
        $now = CarbonImmutable::now()->utc();

        DB::connection('control')->table('tenant_limit_overrides')->updateOrInsert(
            ['tenant_id' => $tenantId, 'resource' => $resource],
            [
                'allowance' => $allowance,
                'version' => 1,
                'enforce_immediately' => false,
                'reason' => 'set in a Phase 13 test',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        DB::connection('tenant')->table('usage_counters')
            ->where('resource', $resource)
            ->delete();
    }

    protected function appSecret(): string
    {
        return self::APP_SECRET;
    }

    protected function verifyToken(): string
    {
        return self::VERIFY_TOKEN;
    }

    protected function conversationFor(string $phone = '+9647501234567'): ?Conversation
    {
        /** @var Conversation|null $conversation */
        $conversation = Conversation::query()->where('contact_phone', $phone)->orderByDesc('id')->first();

        return $conversation;
    }

    protected function seedConversation(
        WhatsAppAccount $account,
        ConversationStatus $status = ConversationStatus::AiActive,
        ?int $customerId = null,
        ?int $branchId = null,
        string $phone = '+9647501234567',
    ): Conversation {
        /** @var Conversation $conversation */
        $conversation = Conversation::query()->create([
            'channel' => 'whatsapp',
            'whatsapp_account_id' => $account->getKey(),
            'customer_id' => $customerId,
            'branch_id' => $branchId,
            'contact_phone' => $phone,
            'status' => $status,
            'locale' => 'en',
            'last_message_at' => CarbonImmutable::now()->utc(),
        ]);

        return $conversation;
    }
}
