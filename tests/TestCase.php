<?php

declare(strict_types=1);

namespace Tests;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestDatabaseManager;

/**
 * Base test case.
 *
 * `RefreshDatabase` is not used: it assumes a single database, and Meta Style
 * has a control database plus one database per tenant, created during the test
 * itself. Instead the control schema is built once per run and its tables are
 * truncated between tests, while tenant databases are created and dropped by
 * the tests that need them (docs/11-TESTING-STRATEGY.md §3).
 */
abstract class TestCase extends BaseTestCase
{
    private static bool $suitePrepared = false;

    /**
     * Truncated between tests. Ordered so a truncate never fights a foreign
     * key, though constraints are disabled during the sweep anyway.
     *
     * @var list<string>
     */
    private const CONTROL_TABLES = [
        'center_user_directory_branches',
        'center_user_directory',
        'platform_alert_reads',
        'platform_announcements',
        'platform_provider_credentials',
        'subscription_history',
        'support_ticket_attachments',
        'support_ticket_history',
        'support_ticket_messages',
        'support_tickets',
        'platform_user_roles',
        'platform_password_reset_tokens',
        'platform_users',
        'landing_page_revisions',
        'landing_pages',
        'saas_payments',
        'saas_invoice_items',
        'saas_invoices',
        'subscription_scheduled_changes',
        'plan_price_history',
        'tenant_lifecycle_history',
        'platform_alerts',
        'tenant_operational_projections',
        'tenant_usage_projections',
        'tenant_limit_overrides',
        'platform_audit_logs',
        'tenant_operations',
        'registrations',
        'subscriptions',
        'tenant_entitlement_overrides',
        'domains',
        'tenants',
        'jobs',
        'failed_jobs',
    ];

    /**
     * Reference data, restored after each truncate rather than wiped.
     *
     * Plans and platform settings are the control plane's seeded catalog, not
     * per-test state: registration cannot run without a default plan, and
     * re-seeding them for every test would be slow and pointless.
     *
     * @var list<string>
     */
    private const CONTROL_REFERENCE_TABLES = [
        'platform_role_permissions',
        'platform_roles',
        'plan_limits',
        'plan_entitlements',
        'plans',
        'platform_settings',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$suitePrepared) {
            $this->prepareSuite();
            self::$suitePrepared = true;
        }

        $this->resetControlPlane();
        $this->sealOutboundHttp();
    }

    /**
     * The provider response the next outbound call receives.
     *
     * A test assigns one to choose what Meta does — a refusal, a 5xx, a
     * timeout — and the DEFAULT below is a documented acceptance.
     *
     * It is a property rather than a second `Http::fake()` call because
     * Laravel MERGES stubs and the FIRST match wins: a fake registered in a
     * test can never override one registered here, which silently made an
     * earlier version of this harness ignore every per-test provider
     * behaviour. The closure stub below reads this on each request, so a test
     * genuinely decides.
     */
    protected ?PromiseInterface $whatsAppResponse = null;

    /** The same, for the assistant provider. */
    protected ?PromiseInterface $openAiResponse = null;

    /**
     * NO TEST EVER REACHES THE INTERNET.
     *
     * Phase 13 added two real provider adapters — Meta's Cloud API and OpenAI —
     * both built on Laravel's HTTP client. Without this, running the suite
     * posts a center's test messages at `graph.facebook.com` and spends OpenAI
     * credits, from every developer machine and every CI run
     * (docs/13-ROADMAP.md Phase 13 §16).
     *
     * `preventStrayRequests()` is the half that matters: a request to a host
     * nothing has faked FAILS LOUDLY rather than escaping. So a future adapter
     * that forgets a fake is caught here by an error naming the URL, instead of
     * by a provider's bill.
     *
     * The defaults are each provider's DOCUMENTED success shape, so the real
     * adapter is still the thing under test — its request building, its
     * response reading, its failure mapping.
     */
    private function sealOutboundHttp(): void
    {
        $this->whatsAppResponse = null;
        $this->openAiResponse = null;

        Http::preventStrayRequests();

        Http::fake([
            // Laravel's uncompromised-password rule uses the HIBP range API.
            // An empty deterministic range means "not present" while
            // preventStrayRequests() keeps the test suite fully offline.
            'api.pwnedpasswords.com/*' => Http::response('', 200, ['Content-Type' => 'text/plain']),

            // Meta Cloud API: the documented send response (docs/25 §16).
            'graph.facebook.com/*' => fn (): PromiseInterface => $this->whatsAppResponse ?? Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '+9647501234567', 'wa_id' => '9647501234567']],
                'messages' => [['id' => 'wamid.TEST'.bin2hex(random_bytes(8)), 'message_status' => 'accepted']],
            ], 200),

            /*
             * OpenAI: a minimal Responses API turn (docs/27 §6).
             *
             * Reached only when a test exercises the real adapter rather than
             * registering `FakeAiProvider`. It exists so that path is a
             * deterministic answer rather than a live call.
             */
            'api.openai.com/*' => fn (): PromiseInterface => $this->openAiResponse ?? Http::response([
                'id' => 'resp_test',
                'status' => 'completed',
                'output' => [[
                    'id' => 'msg_test',
                    'type' => 'message',
                    'role' => 'assistant',
                    'status' => 'completed',
                    'content' => [['type' => 'output_text', 'text' => 'A deterministic test answer.']],
                ]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
            ], 200),
        ]);
    }

    /**
     * Builds the control schema from scratch and clears anything a previous,
     * possibly crashed, run left behind.
     *
     * The control database is recreated rather than migrated in place. A run
     * that dies part-way can leave tables present but the migration history
     * gone, after which every later run fails on "table already exists" — a
     * confusing failure that has nothing to do with the code under test.
     */
    private function prepareSuite(): void
    {
        TestDatabaseManager::recreateControlDatabase();

        // A run that died mid-provision also leaves orphan tenant databases.
        // They all carry the test prefix, so they can be swept safely.
        TestDatabaseManager::dropAll();

        $this->artisan('metastyle:control:migrate', ['--force' => true])->run();
    }

    /**
     * Seeds plans and platform settings if they are missing.
     */
    private function seedReferenceData(): void
    {
        if (DB::connection('control')->table('plans')->exists()) {
            return;
        }

        $this->artisan('db:seed', ['--force' => true])->run();
    }

    private function resetControlPlane(): void
    {
        $schema = Schema::connection('control');

        $schema->disableForeignKeyConstraints();

        foreach (self::CONTROL_TABLES as $table) {
            if ($schema->hasTable($table)) {
                DB::connection('control')->table($table)->truncate();
            }
        }

        $schema->enableForeignKeyConstraints();

        $this->seedReferenceData();
    }
}
