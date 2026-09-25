<?php

declare(strict_types=1);

use App\Kernel\Usage\Usage;
use App\Modules\Conversations\Application\ConversationPresenter;
use App\Modules\Conversations\Application\ConversationsQuery;
use App\Modules\Rayan\Application\RayanAssistant;

/*
|--------------------------------------------------------------------------
| The Phase 13 boundary: the channel, the assistant, and the metering
|--------------------------------------------------------------------------
|
| docs/25-WHATSAPP.md · docs/27-RAYAN.md · docs/26-USAGE-QUOTAS.md.
|
| Three layers, each of which must stay where it is:
|
|     Conversations   the CHANNEL. Calls the assistant.
|          ↓
|     Rayan           the ASSISTANT. Calls the domain modules.
|          ↓
|     Booking, Customers, Catalog, Benefits
|
| Nothing below ever points back up. `Kernel\Usage` sits beside all of it and
| knows none of it.
|
| The tests here are the ones that can fire today. A rule over a namespace that
| does not exist passes for the wrong reason.
|
*/

/*
|--------------------------------------------------------------------------
| Direction
|--------------------------------------------------------------------------
*/

arch('nothing below the channel knows the channel or the assistant exists')
    ->expect([
        'App\Modules\Booking',
        'App\Modules\ServiceJourney',
        'App\Modules\Queue',
        'App\Modules\Resources',
        'App\Modules\Sales',
        'App\Modules\Payments',
        'App\Modules\Finance',
        'App\Modules\Loyalty',
        'App\Modules\Memberships',
        'App\Modules\Packages',
        'App\Modules\Customers',
        'App\Modules\Catalog',
        'App\Modules\Branches',
        'App\Modules\Employees',
        'App\Modules\Menu',
        'App\Modules\Reviews',
    ])
    ->not->toUse(['App\Modules\Conversations', 'App\Modules\Rayan']);

/*
 * The one that would be easiest to get backwards.
 *
 * The CHANNEL calls the ASSISTANT, so Conversations imports Rayan's contract
 * and its data objects. Rayan must never import Conversations — if it did, the
 * assistant would be able to write a conversation's state, and "the assistant
 * does not own conversation state" would become a convention instead of a fact
 * (docs/27-RAYAN.md §4).
 */
arch('the assistant never reaches back into the channel')
    ->expect('App\Modules\Rayan')
    ->not->toUse('App\Modules\Conversations');

arch('the kernel never learns what a conversation or an assistant is')
    ->expect('App\Kernel')
    ->not->toUse(['App\Modules\Conversations', 'App\Modules\Rayan']);

arch('the assistant is reached only through its contract')
    ->expect('App\Modules\Conversations')
    ->not->toUse([
        RayanAssistant::class,
        'App\Modules\Rayan\Application\ToolRegistry',
        'App\Modules\Rayan\Application\Tools',
        'App\Modules\Rayan\Infrastructure',
    ]);

/*
 * A guest's booking confirmation (docs/25-WHATSAPP.md §22) describes a booking
 * without holding one. The channel reads it through
 * `Booking\Contracts\BookingConfirmationFacts` and the readonly
 * `Booking\Data\BookingConfirmationData` it returns — never the Booking or
 * Employees models, which would let a channel write what it may only read
 * (CLAUDE.md: a module imports another only via Contracts/, Data/ or
 * Domain/Events/). One expectation per forbidden namespace, so each one fails
 * on its own.
 */
arch('the channel reads bookings through the Booking contract, never its models')
    ->expect('App\Modules\Conversations')
    ->not->toUse('App\Modules\Booking\Domain\Models');

arch('the channel never holds an employee model')
    ->expect('App\Modules\Conversations')
    ->not->toUse('App\Modules\Employees\Domain\Models');

/*
 * The Phase 13 files that resolve an inbound sender to a CRM customer predate
 * the rule above and are the only ones allowed the Customer model. A ratchet:
 * nothing new may join them — the booking confirmation reads its customer
 * from `BookingConfirmationData`.
 */
arch('no new Customer model dependency in the channel')
    ->expect('App\Modules\Conversations')
    ->not->toUse('App\Modules\Customers\Domain\Models')
    ->ignoring([
        'App\Modules\Conversations\Application\ConversationRouter',
        'App\Modules\Conversations\Application\CustomerResolution',
        'App\Modules\Conversations\Domain\Models\Conversation',
    ]);

arch('phase 13 enums are string backed, so stored values survive a release')
    ->expect(['App\Modules\Conversations\Domain\Enums', 'App\Modules\Rayan\Domain\Enums', 'App\Kernel\Usage'])
    ->toBeStringBackedEnum()
    ->ignoring([
        'App\Kernel\Usage\Usage',
        'App\Kernel\Usage\UsageCatalog',
        'App\Kernel\Usage\UsageCounters',
        'App\Kernel\Usage\UsagePeriod',
        'App\Kernel\Usage\UsageSummary',
        'App\Kernel\Usage\UsageProjector',
        'App\Kernel\Usage\Allowances',
        'App\Kernel\Usage\AllowanceSync',
        'App\Kernel\Usage\ResolvedAllowance',
        'App\Kernel\Usage\Models',
        'App\Kernel\Usage\Actions',
        'App\Kernel\Usage\Events',
        'App\Kernel\Usage\Exceptions',
        'App\Kernel\Usage\Console',
    ]);

arch('the channel and the assistant do not depend on HTTP or Livewire')
    ->expect(['App\Modules\Conversations', 'App\Modules\Rayan'])
    ->not->toUse([
        'App\Http\Controllers',
        'App\Livewire',
        'Livewire\Component',
        'Illuminate\Support\Facades\Request',
        'Illuminate\Support\Facades\Auth',
        'Illuminate\Support\Facades\Session',
    ]);

/*
 * The assistant answers customers. It has no business anywhere near the
 * center's money or its audit trail — a model that could read either would be
 * one prompt away from reading it aloud (docs/27-RAYAN.md §10).
 */
arch('the assistant can reach neither money nor the audit trail')
    ->expect('App\Modules\Rayan')
    ->not->toUse([
        'App\Modules\Finance',
        'App\Modules\Payments',
        'App\Modules\Sales',
        'App\Kernel\Audit',
        'App\Kernel\Notes',
    ]);

/*
|--------------------------------------------------------------------------
| The assistant delegates; it never writes
|--------------------------------------------------------------------------
*/

it('never lets the assistant touch a domain table directly', function (): void {
    /*
     * Every change RAYAN makes goes through an approved Action, which
     * re-validates entitlement, ownership, branch and booking state. A query
     * builder or a model write inside this module would be a path around all
     * four (docs/27-RAYAN.md §4).
     *
     * Reading is allowed and is how the read tools work — what is forbidden is
     * WRITING, and raw SQL of any kind.
     */
    $violations = [];

    foreach (appSourceWithoutComments() as $path => $contents) {
        if (! str_starts_with($path, 'app/Modules/Rayan/')) {
            continue;
        }

        foreach ([
            'DB::' => 'reaches for the database facade',
            '->insert(' => 'writes rows directly',
            '->update(' => 'writes rows directly',
            '->delete(' => 'deletes rows directly',
            '->forceFill(' => 'writes model attributes directly',
            'selectRaw' => 'writes raw SQL',
            'whereRaw' => 'writes raw SQL',
            'DB::raw' => 'writes raw SQL',
        ] as $needle => $why) {
            if (str_contains($contents, $needle)) {
                $violations[] = $path.'  '.$why.' ('.$needle.')';
            }
        }
    }

    /*
     * Two narrow exceptions, both of which touch only the module's OWN rows:
     *
     *   RayanAssistant  writes `ai_runs` and `ai_tool_calls` — execution
     *                   metadata that belongs to nobody else.
     *   RayanReportAnalyst writes report-run execution metadata on `ai_runs`.
     *   RayanSettings   reads and writes this center's `settings` row, exactly
     *                   as `BookingSettings` and `TenantLocales` do.
     *
     * Neither touches a domain table, which is what the rule is about.
     */
    $allowed = [
        'app/Modules/Rayan/Application/RayanAssistant.php',
        'app/Modules/Rayan/Application/RayanReportAnalyst.php',
        'app/Modules/Rayan/Application/RayanSettings.php',
    ];

    expect(array_values(array_filter(
        $violations,
        static fn (string $violation): bool => ! in_array(explode('  ', $violation)[0], $allowed, true),
    )))->toBe([]);
});

it('keeps appointment writes inside the Booking module', function (): void {
    /*
     * The Booking Engine is the only thing that writes `appointments`, and
     * Phase 13 does not change that. The assistant books by calling
     * `BookingEngine::book()` like every other channel (CLAUDE.md, "Booking").
     */
    $violations = [];

    foreach (appSourceWithoutComments() as $path => $contents) {
        if (str_starts_with($path, 'app/Modules/Booking/')) {
            continue;
        }

        foreach (['Appointment::query()->create', 'Appointment::create', "table('appointments')"] as $needle) {
            if (str_contains($contents, $needle)) {
                $violations[] = $path.'  '.$needle;
            }
        }
    }

    expect($violations)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| The tool surface
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Secrets
|--------------------------------------------------------------------------
*/

it('never stores a booking verification key, or a raw code, in any database', function (): void {
    /*
     * The pepper lives in configuration and nowhere else; the raw code exists
     * for one return value and is never persisted. A column for either would
     * mean a copy of the database opens every booking
     * (docs/24-BOOKING-VERIFICATION.md §§3–4).
     */
    $violations = [];

    foreach (glob(dirname(__DIR__, 2).'/database/migrations/*/*.php') ?: [] as $path) {
        $contents = (string) file_get_contents($path);

        foreach (["'verification_code'", "'verification_key'", "'booking_verification_key'", "'pepper'"] as $needle) {
            if (str_contains($contents, $needle)) {
                $violations[] = basename($path).'  '.$needle;
            }
        }
    }

    expect($violations)->toBe([]);
});

it('encrypts provider credentials and hides them from serialisation', function (): void {
    $model = (string) file_get_contents(
        dirname(__DIR__, 2).'/app/Modules/Conversations/Domain/Models/WhatsAppAccount.php'
    );

    expect($model)->toContain("'credentials' => 'encrypted:array'")
        ->and($model)->toContain("protected \$hidden = ['credentials']");
});

it('never lets a presenter emit a provider secret', function (): void {
    /*
     * A presenter's output reaches an API response, a Livewire payload and a
     * page. A token, an app secret or a verify token in any of them is the
     * center's WhatsApp identity handed to whoever can read a screenshot
     * (docs/25-WHATSAPP.md §20).
     */
    $violations = [];

    foreach (appSourceWithoutComments() as $path => $contents) {
        if (! str_contains($path, 'Presenter') && ! str_contains($path, 'Resource')) {
            continue;
        }

        foreach (['access_token', 'app_secret', 'verify_token', 'readableCredentials', 'api_key'] as $needle) {
            if (str_contains($contents, $needle)) {
                $violations[] = $path.'  '.$needle;
            }
        }
    }

    expect($violations)->toBe([]);
});

it('takes no outbound destination from tenant data', function (): void {
    /*
     * Where Meta and OpenAI live is platform configuration. A URL column on a
     * tenant table would be server-side request forgery by configuration — with
     * the center's own access token attached to the request (ADR-071).
     */
    $violations = [];

    /*
     * Scoped to the Phase 13 tables. `idempotency_keys.endpoint` is a Phase 6
     * column naming an API ROUTE, not an outbound destination — matching it
     * would be the scan misreading a word rather than finding a hole.
     */
    foreach (glob(dirname(__DIR__, 2).'/database/migrations/tenant/2026_09_20_1300*.php') ?: [] as $path) {
        $contents = (string) file_get_contents($path);

        foreach (["'base_url'", "'endpoint'", "'webhook_url'", "'api_url'", "'host'", "'provider_url'"] as $needle) {
            if (str_contains($contents, $needle)) {
                $violations[] = basename($path).'  '.$needle;
            }
        }
    }

    expect($violations)->toBe([]);
});

it('puts no tenant column in the Phase 13 schema', function (): void {
    $violations = [];

    foreach (glob(dirname(__DIR__, 2).'/database/migrations/tenant/2026_09_20_1300*.php') ?: [] as $path) {
        $contents = (string) file_get_contents($path);

        if (str_contains($contents, "'tenant_id'") || str_contains($contents, 'tenantId')) {
            $violations[] = basename($path);
        }
    }

    expect($violations)->toBe([])
        // Guards the check above: if the glob stopped matching, it would pass
        // by scanning nothing.
        ->and(glob(dirname(__DIR__, 2).'/database/migrations/tenant/2026_09_20_1300*.php'))->not->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Kernel\Usage stays generic
|--------------------------------------------------------------------------
*/

it('keeps RAYAN and WhatsApp out of the usage kernel', function (): void {
    /*
     * `Kernel\Usage` owns periods, allowances, atomic consumption and metering.
     * It must never learn when RAYAN should run, when WhatsApp should send, or
     * which conversation should hand off — those are decisions for the modules
     * above it (Phase 13 correction 3, docs/26-USAGE-QUOTAS.md §2).
     *
     * Resource CODES reach it as strings, validated against `config/usage.php`,
     * which is what lets it stay ignorant of what they mean.
     */
    $violations = [];

    foreach (appSourceWithoutComments() as $path => $contents) {
        if (! str_starts_with($path, 'app/Kernel/Usage/')) {
            continue;
        }

        foreach (['Rayan', 'WhatsApp', 'whatsapp', 'Conversation', 'ai_runs', 'wa_inbound', 'wa_outbound', 'OpenAi'] as $needle) {
            if (str_contains($contents, $needle)) {
                $violations[] = $path.'  '.$needle;
            }
        }
    }

    expect($violations)->toBe([]);
});

it('reads its resource catalog from configuration, not from a hardcoded list', function (): void {
    // The mechanism that keeps the Kernel generic: the codes live in
    // `config/usage.php`, so the Kernel validates names it may not know.
    $catalog = (string) file_get_contents(dirname(__DIR__, 2).'/app/Kernel/Usage/UsageCatalog.php');

    expect($catalog)->toContain("'usage.resources'");
});

/*
|--------------------------------------------------------------------------
| Scope
|--------------------------------------------------------------------------
*/

it('keeps the customer conversation product independent from report delivery', function (): void {
    /*
     * Phase 14 may reuse the provider and generic usage infrastructure, but a
     * customer conversation cannot become a report/export delivery channel.
     */
    $violations = [];

    foreach (appSourceWithoutComments() as $path => $contents) {
        if (! str_starts_with($path, 'app/Modules/Conversations/')) {
            continue;
        }

        foreach (['AdvancedReports', 'ReportBuilder', 'ScheduledReport', 'ReportExport', 'reports_advanced'] as $needle) {
            if (str_contains($contents, $needle)) {
                $violations[] = $path.'  '.$needle;
            }
        }
    }

    expect($violations)->toBe([]);
});

arch('reading conversations or usage never sends, moderates or repairs anything')
    ->expect([ConversationsQuery::class, ConversationPresenter::class])
    ->not->toUse([
        'App\Modules\Conversations\Application\Actions',
        'App\Modules\Conversations\Application\OutboundMessages',
        'App\Modules\Conversations\Application\ConversationRouter',
        Usage::class,
    ]);
