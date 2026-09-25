<?php

declare(strict_types=1);

namespace App\Providers;

use App\Kernel\Diagnostics\ProductionReadiness;
use App\Kernel\Http\MiddlewareOrderGuard;
use App\Kernel\Identity\Models\PersonalAccessToken;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\Http\Middleware\SetLocale;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Observability\RequestId;
use App\Kernel\Platform\Branding\PlatformBranding;
use App\Kernel\Platform\Currencies\PlatformCurrencies;
use App\Kernel\Platform\Settings\PlatformPreferences;
use App\Kernel\Reconciliation\Console\ReconcileCommand;
use App\Kernel\Reporting\ReportConnection;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Http\Middleware\ResolvePublicTenant;
use App\Kernel\Tenancy\Http\Middleware\ResolveTenant;
use App\Kernel\Tenancy\PlatformHosts;
use App\Kernel\Tenancy\TenantConnectionGuard;
use App\Kernel\Usage\AllowanceSync;
use App\Kernel\Usage\Events\UsageThresholdReached;
use App\Modules\AdvancedReports\Contracts\ReportAnalyst;
use App\Modules\Booking\Application\AppointmentConfirmationFacts;
use App\Modules\Booking\Application\MetaStyleBookingEngine;
use App\Modules\Booking\Application\SqlBookingReportReader;
use App\Modules\Booking\Contracts\BookingConfirmationFacts;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Contracts\BookingReportReader;
use App\Modules\Booking\Domain\BookingSettings;
use App\Modules\Booking\Domain\Events\AppointmentCancelled;
use App\Modules\Booking\Domain\Events\AppointmentConfirmed;
use App\Modules\Booking\Domain\Events\AppointmentRescheduled;
use App\Modules\Branches\Application\SiteBranchQuery;
use App\Modules\Branches\Application\SqlReportBranchReader;
use App\Modules\Branches\Contracts\ReportBranchReader;
use App\Modules\Branches\Contracts\SiteBranchReader;
use App\Modules\Catalog\Application\SiteCatalogQuery;
use App\Modules\Catalog\Application\SqlReportServiceCategories;
use App\Modules\Catalog\Contracts\ReportServiceCategories;
use App\Modules\Catalog\Contracts\SiteCatalogReader;
use App\Modules\CenterSite\Application\PublicCenterBrand;
use App\Modules\CenterSite\Contracts\CenterBrandReader;
use App\Modules\CenterSite\Http\CenterPublicShellComposer;
use App\Modules\Conversations\Application\GuestConfirmationReconciler;
use App\Modules\Conversations\Application\Listeners\AuditAssistantSettings;
use App\Modules\Conversations\Application\Listeners\ConfirmGuestBookingOnWhatsApp;
use App\Modules\Conversations\Application\MessagingProviderRegistry;
use App\Modules\Conversations\Domain\Events\ProviderSendFailed;
use App\Modules\Conversations\Domain\Events\TakeoverRequested;
use App\Modules\Conversations\Infrastructure\Providers\MetaWhatsAppCloudProvider;
use App\Modules\Customers\Application\SqlCustomerReportReader;
use App\Modules\Customers\Contracts\CustomerReportReader;
use App\Modules\Customers\Domain\Models\CustomerAccount;
use App\Modules\Employees\Application\PublicTeamQuery;
use App\Modules\Employees\Contracts\PublicTeamReader;
use App\Modules\Finance\Application\RecordMoneyMovements;
use App\Modules\Finance\Application\SqlFinanceReportReader;
use App\Modules\Finance\Contracts\FinanceReportReader;
use App\Modules\Loyalty\Application\LoyaltySync;
use App\Modules\Loyalty\Application\RedeemedPoints;
use App\Modules\Loyalty\Application\SqlBenefitReportReader;
use App\Modules\Loyalty\Contracts\BenefitReportReader;
use App\Modules\Memberships\Application\ActivateMemberships;
use App\Modules\Memberships\Application\MembershipCatalog;
use App\Modules\Memberships\Application\MembershipSaleGuard;
use App\Modules\Memberships\Application\SiteMembershipQuery;
use App\Modules\Memberships\Application\UsedBenefits;
use App\Modules\Memberships\Contracts\SiteMembershipReader;
use App\Modules\Memberships\Domain\Events\MembershipActivated;
use App\Modules\Notifications\Application\Listeners\NotifyOnBooking;
use App\Modules\Notifications\Application\Listeners\NotifyOnCommerce;
use App\Modules\Notifications\Application\Listeners\NotifyOnConversations;
use App\Modules\Notifications\Application\Listeners\NotifyOnPlatformAnnouncement;
use App\Modules\Notifications\Application\Listeners\NotifyOnReviews;
use App\Modules\Packages\Application\ActivatePackages;
use App\Modules\Packages\Application\PackageCatalog;
use App\Modules\Packages\Application\PackageSaleGuard;
use App\Modules\Packages\Application\RedeemedSessions;
use App\Modules\Packages\Application\SitePackageQuery;
use App\Modules\Packages\Contracts\SitePackageReader;
use App\Modules\Packages\Domain\Events\PackageActivated;
use App\Modules\Payments\Application\PaymentProviderRegistry;
use App\Modules\Payments\Application\PaymentsVoidGuard;
use App\Modules\Payments\Application\SqlPaymentReportReader;
use App\Modules\Payments\Contracts\PaymentReportReader;
use App\Modules\Payments\Domain\Events\PaymentSucceeded;
use App\Modules\Payments\Domain\Events\RefundSucceeded;
use App\Modules\Payments\Infrastructure\Providers\FibProvider;
use App\Modules\Payments\Infrastructure\Providers\UnsupportedProvider;
use App\Modules\PlatformOperations\Domain\Events\PlatformAnnouncementPublished;
use App\Modules\Queue\Application\SqlQueueReportReader;
use App\Modules\Queue\Application\SyncTicketsWithJourney;
use App\Modules\Queue\Contracts\QueueReportReader;
use App\Modules\Rayan\Application\AiProviderRegistry;
use App\Modules\Rayan\Application\RayanAssistant;
use App\Modules\Rayan\Application\RayanReportAnalyst;
use App\Modules\Rayan\Application\RayanSettings;
use App\Modules\Rayan\Application\ToolRegistry;
use App\Modules\Rayan\Application\Tools\CancelBookingTool;
use App\Modules\Rayan\Application\Tools\CreateBookingTool;
use App\Modules\Rayan\Application\Tools\GetAvailableSlotsTool;
use App\Modules\Rayan\Application\Tools\GetBookingDetailsTool;
use App\Modules\Rayan\Application\Tools\GetCustomerBookingsTool;
use App\Modules\Rayan\Application\Tools\GetCustomerContextTool;
use App\Modules\Rayan\Application\Tools\GetServiceDetailsTool;
use App\Modules\Rayan\Application\Tools\ListBranchesTool;
use App\Modules\Rayan\Application\Tools\ListServicesTool;
use App\Modules\Rayan\Application\Tools\RescheduleBookingTool;
use App\Modules\Rayan\Contracts\Assistant;
use App\Modules\Rayan\Domain\Events\AssistantSettingsChanged;
use App\Modules\Rayan\Infrastructure\Providers\OpenAiProvider;
use App\Modules\Reviews\Application\PublicRatingQuery;
use App\Modules\Reviews\Application\ReviewSync;
use App\Modules\Reviews\Application\SqlReviewReportReader;
use App\Modules\Reviews\Contracts\PublicRatingReader;
use App\Modules\Reviews\Contracts\ReviewReportReader;
use App\Modules\Reviews\Domain\Events\ReviewInvitationIssued;
use App\Modules\Reviews\Domain\Events\ReviewSubmitted;
use App\Modules\Sales\Application\Offerings;
use App\Modules\Sales\Application\ReleaseStaleBenefits;
use App\Modules\Sales\Application\SaleFinalizationGuards;
use App\Modules\Sales\Application\SaleVoidGuards;
use App\Modules\Sales\Application\SqlSalesReportReader;
use App\Modules\Sales\Contracts\SalesReportReader;
use App\Modules\Sales\Domain\Events\SaleBenefitReleased;
use App\Modules\Sales\Domain\Events\SaleDraftDiscarded;
use App\Modules\Sales\Domain\Events\SaleFinalized;
use App\Modules\Sales\Domain\Events\SaleVoided;
use App\Modules\ServiceJourney\Application\SqlJourneyReportReader;
use App\Modules\ServiceJourney\Contracts\JourneyReportReader;
use App\Modules\ServiceJourney\Domain\Events\JourneyAborted;
use App\Modules\ServiceJourney\Domain\Events\JourneyCompleted;
use App\Modules\ServiceJourney\Domain\Events\JourneyStageSettled;
use App\Modules\ServiceJourney\Domain\Events\JourneyStageStarted;
use App\View\Composers\LanguageSwitcherComposer;
use App\View\Composers\ManagerShellComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * The only database connections Meta Style supports.
     *
     * `tenant` is absent on purpose — it is created at runtime by the tenancy
     * layer when a tenant is initialised, and removed when tenancy ends
     * (docs/02-TENANCY.md §4). Pruning happens during register(), long before
     * any tenant is bootstrapped, so the runtime connection is unaffected.
     *
     * @var list<string>
     */
    private const SUPPORTED_CONNECTIONS = ['control', 'tenant_template', 'reporting_template'];

    public function register(): void
    {
        $this->restrictDatabaseConnections();

        // Scoped so each request, queued job, and command gets its own
        // correlation id, and long-running workers do not leak one between
        // jobs (docs/10-API-FOUNDATION.md §3).
        $this->app->scoped(RequestId::class, fn (): RequestId => new RequestId);

        $this->app->singleton(TenantConnectionGuard::class);
        $this->app->singleton(ReportConnection::class);
        $this->app->singleton(PlatformHosts::class);

        $this->app->bind(ReportBranchReader::class, SqlReportBranchReader::class);
        $this->app->bind(BookingReportReader::class, SqlBookingReportReader::class);
        $this->app->bind(JourneyReportReader::class, SqlJourneyReportReader::class);
        $this->app->bind(QueueReportReader::class, SqlQueueReportReader::class);
        $this->app->bind(SalesReportReader::class, SqlSalesReportReader::class);
        $this->app->bind(PaymentReportReader::class, SqlPaymentReportReader::class);
        $this->app->bind(FinanceReportReader::class, SqlFinanceReportReader::class);
        $this->app->bind(CustomerReportReader::class, SqlCustomerReportReader::class);
        $this->app->bind(ReviewReportReader::class, SqlReviewReportReader::class);
        $this->app->bind(BenefitReportReader::class, SqlBenefitReportReader::class);
        // Standard Reports: services grouped by their menu category (ADR-037 keeps categories out of Journey/Queue).
        $this->app->bind(ReportServiceCategories::class, SqlReportServiceCategories::class);
        $this->app->bind(ReportAnalyst::class, RayanReportAnalyst::class);

        /*
         * Payment providers: one adapter per code.
         *
         * FIB is implemented against its published documentation. ZainCash, Qi
         * and FastPay are listed but UNSUPPORTED — no adapter has been written
         * against a verified contract or proven with a sandbox transaction, and
         * a fake integration would be worse than none (docs/19-PAYMENTS.md §16).
         */
        $this->app->singleton(PaymentProviderRegistry::class, fn ($app): PaymentProviderRegistry => new PaymentProviderRegistry([
            $app->make(FibProvider::class),
            new UnsupportedProvider('zaincash', 'ZainCash'),
            new UnsupportedProvider('qi', 'Qi Card'),
            new UnsupportedProvider('fastpay', 'FastPay'),
        ]));

        /*
         * Payments may veto a sale void — money collected, or an online payment
         * in flight. Sales asks every guard under this tag and names no
         * implementation; Sales must never import Payments
         * (docs/19-PAYMENTS.md §18).
         */
        $this->app->tag([PaymentsVoidGuard::class], SaleVoidGuards::TAG);

        /*
         * Benefits plug into Sales through its generic seams, never the other
         * way round: a package or a membership is an offering the till can
         * sell, and a sale carrying one needs a customer to hand it to. Sales
         * never imports
         * Loyalty, Memberships or Packages
         * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §4).
         */
        $this->app->tag([PackageCatalog::class, MembershipCatalog::class], Offerings::TAG);
        $this->app->tag([PackageSaleGuard::class, MembershipSaleGuard::class], SaleFinalizationGuards::TAG);

        /*
         * Every after-commit benefit reaction has a reconciler that replays it
         * from the canonical Sales and Payments facts — `metastyle:reconcile`,
         * hourly (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §1).
         */
        $this->app->tag([
            LoyaltySync::class,
            ActivatePackages::class,
            ActivateMemberships::class,
            // Sales' own: a benefit held on a draft nobody finished expires,
            // so a cart can never hold a customer's points for ever (§7).
            ReleaseStaleBenefits::class,
            // And Phase 12's: a visit that completed while the review table was
            // briefly unavailable still gets its invitation
            // (docs/22-REVIEWS.md §4).
            ReviewSync::class,
            /*
             * Phase 13's: the allowance SNAPSHOT on a center's usage counter is
             * a copy of a control-plane number, and a copy can be stale if the
             * write that should have updated it was lost. This repairs it —
             * upwards only, unless an override says otherwise
             * (docs/26-USAGE-QUOTAS.md §8).
             */
            AllowanceSync::class,
            // A guest's WhatsApp booking confirmation whose after-commit
            // callback never ran, or that the provider refused (docs/25 §22).
            GuestConfirmationReconciler::class,
        ], ReconcileCommand::TAG);

        /*
         * Phase 13 — the WhatsApp channel and the assistant.
         *
         * Both are provider seams with ONE real adapter each, registered the
         * same way payment providers are: a code, an adapter, and nothing that
         * resolves a class name from data (docs/25-WHATSAPP.md §5,
         * docs/27-RAYAN.md §6).
         */
        $this->app->singleton(MessagingProviderRegistry::class, fn ($app): MessagingProviderRegistry => new MessagingProviderRegistry([
            $app->make(MetaWhatsAppCloudProvider::class),
        ]));

        $this->app->singleton(AiProviderRegistry::class, fn ($app): AiProviderRegistry => new AiProviderRegistry([
            $app->make(OpenAiProvider::class),
        ]));

        /*
         * THE ALLOW-LIST, as a container binding.
         *
         * Ten handlers, named explicitly. Deliberately NOT discovered by
         * scanning a directory or a tag: a tool is a capability handed to a
         * language model, and "whatever classes happen to be in this folder" is
         * not a decision anybody made. Adding one is a line here, in a diff
         * somebody reviews (docs/27-RAYAN.md §11).
         */
        $this->app->singleton(ToolRegistry::class, fn ($app): ToolRegistry => new ToolRegistry([
            $app->make(ListBranchesTool::class),
            $app->make(ListServicesTool::class),
            $app->make(GetServiceDetailsTool::class),
            $app->make(GetAvailableSlotsTool::class),
            $app->make(GetCustomerContextTool::class),
            $app->make(GetCustomerBookingsTool::class),
            $app->make(GetBookingDetailsTool::class),
            $app->make(CreateBookingTool::class),
            $app->make(RescheduleBookingTool::class),
            $app->make(CancelBookingTool::class),
        ]));

        // The channel depends on the CONTRACT. That is what lets Conversations
        // call the assistant without RAYAN ever knowing the channel exists.
        $this->app->bind(Assistant::class, RayanAssistant::class);

        // Scoped for the same reason `BookingSettings` is: a settings form and
        // a running conversation must see one center's settings within a
        // request, or saving in one leaves the other on a stale cache.
        $this->app->scoped(RayanSettings::class);

        // ONE per request, not one per injection. `TenantLocales` caches the
        // center's enabled languages, and with a transient binding the locale
        // middleware, a presenter and a form would each hold their own copy —
        // so changing the enabled set would update one of them and leave the
        // others answering from a stale cache within the same request.
        $this->app->scoped(TenantLocales::class);

        // One read of the platform catalog and settings per request.
        $this->app->scoped(PlatformCurrencies::class);
        $this->app->scoped(PlatformPreferences::class);
        $this->app->scoped(PlatformBranding::class);

        // Same reasoning: the availability engine, a booking Action and a
        // settings form must all see one center's booking settings within a
        // request, or saving in one leaves the others answering from a stale
        // cache.
        $this->app->scoped(BookingSettings::class);

        // Channels depend on the CONTRACT. The concrete engine is an
        // implementation detail, and binding it here is what lets a WhatsApp
        // adapter type-hint the boundary rather than the class
        // (docs/04-MODULE-BOUNDARIES.md §4.1).
        $this->app->bind(BookingEngine::class, MetaStyleBookingEngine::class);

        // A channel confirming a booking to its customer reads it through
        // this contract, never the Booking models (docs/25-WHATSAPP.md §22).
        $this->app->bind(BookingConfirmationFacts::class, AppointmentConfirmationFacts::class);

        // [area:site-builder] The center's own public site reads other modules
        // only through these contracts; the brand reader is shared with the
        // menu, booking and print appearance.
        $this->app->bind(CenterBrandReader::class, PublicCenterBrand::class);
        $this->app->bind(SiteCatalogReader::class, SiteCatalogQuery::class);
        $this->app->bind(PublicTeamReader::class, PublicTeamQuery::class);
        $this->app->bind(SiteBranchReader::class, SiteBranchQuery::class);
        $this->app->bind(SiteMembershipReader::class, SiteMembershipQuery::class);
        $this->app->bind(SitePackageReader::class, SitePackageQuery::class);
        $this->app->bind(PublicRatingReader::class, PublicRatingQuery::class);
        // [/area:site-builder]

        /*
         * The availability collaborators are deliberately NOT scoped or
         * singletons. They each cache per instance — a branch's opening hours,
         * an employee eligibility set, the resources of a type — and that cache
         * is only safe for as long as one computation runs.
         *
         * Registering them scoped was tried and reverted: a scoped instance
         * outlives an HTTP request in a test process and under Octane, so a
         * booking made after a room was archived was still offered the room
         * from a cache built before it. A transient binding makes the cache
         * exactly as long-lived as the work it belongs to (ADR-048).
         */
    }

    /**
     * Laravel merges the framework's base config over the application's, so
     * deleting `sqlite`, `pgsql` and `sqlsrv` from config/database.php does not
     * actually remove them — they reappear from the framework defaults.
     *
     * That would leave SQLite reachable via `DB_CONNECTION=sqlite` or
     * `DB::connection('sqlite')`, which is exactly the failure ADR-016 exists
     * to prevent: a suite that is green on SQLite and broken on MySQL. Pruning
     * them here turns that into an immediate "connection not configured" error.
     */
    private function restrictDatabaseConnections(): void
    {
        $config = $this->app->make('config');

        /** @var array<string, mixed> $connections */
        $connections = $config->get('database.connections', []);

        $config->set('database.connections', array_intersect_key(
            $connections,
            array_flip(self::SUPPORTED_CONNECTIONS),
        ));
    }

    public function boot(): void
    {
        // Mass assignment must be declared explicitly, never inferred
        // (docs/08-AUDIT-SECURITY.md §12).
        Model::unguard(false);

        // Accessing a relation that was not loaded is a bug, not a feature —
        // it is how N+1 queries reach production. Local and testing only, so
        // production degrades rather than fails.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventAccessingMissingAttributes(! $this->app->isProduction());

        // Sanctum must resolve tokens on the TENANT connection. Without this
        // it would query the default connection — the control plane — where no
        // token rows exist, and every API request would fail confusingly
        // (docs/DECISIONS.md ADR-027).
        /*
         * Queue listens to Journey, synchronously.
         *
         * The events are dispatched inside the Journey Actions' transactions,
         * and this listener is a plain class — no `ShouldQueue`, no broadcast —
         * so a ticket and the stage it belongs to commit together or not at
         * all. A queued listener would leave the board and the television
         * showing a stage `in_service` beside a ticket still `called`
         * (docs/17-QUEUE.md §12, Phase 8 correction 4).
         *
         * Registered HERE rather than in Journey, because Journey must not know
         * Queue exists — an architecture test enforces the direction.
         */
        Event::listen(JourneyStageStarted::class, [SyncTicketsWithJourney::class, 'handleStageStarted']);
        Event::listen(JourneyStageSettled::class, [SyncTicketsWithJourney::class, 'handleStageSettled']);
        Event::listen(JourneyAborted::class, [SyncTicketsWithJourney::class, 'handleJourneyAborted']);

        /*
         * Finance records Payments' money facts, synchronously.
         *
         * Same pattern, same reason: `PaymentSucceeded` and `RefundSucceeded`
         * are dispatched inside the transaction that moved the money, and the
         * ledger entry commits with it or rolls it back. Registered here
         * because Payments must not know Finance exists (docs/20-FINANCE.md §37).
         */
        Event::listen(PaymentSucceeded::class, [RecordMoneyMovements::class, 'handlePaymentSucceeded']);
        Event::listen(RefundSucceeded::class, [RecordMoneyMovements::class, 'handleRefundSucceeded']);

        /*
         * Loyalty, memberships and packages react to money — AFTER it commits.
         *
         * The OPPOSITE of the Finance pattern above, on purpose. A ledger entry
         * is part of the money fact; points and a package are consequences of
         * it, and a failure there must never roll back or misreport a payment.
         * These handlers only schedule their work with `AfterCommit`; every one
         * is idempotent and has a reconciler for the callback that never ran
         * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §1).
         */
        Event::listen(PaymentSucceeded::class, [LoyaltySync::class, 'handlePaymentSucceeded']);
        Event::listen(RefundSucceeded::class, [LoyaltySync::class, 'handleRefundSucceeded']);
        Event::listen(JourneyCompleted::class, [LoyaltySync::class, 'handleJourneyCompleted']);
        Event::listen(PaymentSucceeded::class, [ActivatePackages::class, 'handlePaymentSucceeded']);
        Event::listen(SaleFinalized::class, [ActivatePackages::class, 'handleSaleFinalized']);
        Event::listen(PaymentSucceeded::class, [ActivateMemberships::class, 'handlePaymentSucceeded']);
        Event::listen(SaleFinalized::class, [ActivateMemberships::class, 'handleSaleFinalized']);

        /*
         * Giving back what a void or a discarded draft had consumed stays
         * SYNCHRONOUS, inside the Sales transaction: it moves no money, and a
         * sale voided while its points stay spent would be a lie. A failure
         * refuses the void rather than stranding the benefit.
         */
        Event::listen(SaleVoided::class, [RedeemedPoints::class, 'handleSaleVoided']);
        Event::listen(SaleDraftDiscarded::class, [RedeemedPoints::class, 'handleSaleDraftDiscarded']);
        Event::listen(SaleVoided::class, [RedeemedSessions::class, 'handleSaleVoided']);
        Event::listen(SaleDraftDiscarded::class, [RedeemedSessions::class, 'handleSaleDraftDiscarded']);
        Event::listen(SaleVoided::class, [UsedBenefits::class, 'handleSaleVoided']);
        Event::listen(SaleDraftDiscarded::class, [UsedBenefits::class, 'handleSaleDraftDiscarded']);

        // And when a hold on an abandoned draft expires, for the same reason.
        Event::listen(SaleBenefitReleased::class, [RedeemedPoints::class, 'handleBenefitReleased']);
        Event::listen(SaleBenefitReleased::class, [RedeemedSessions::class, 'handleBenefitReleased']);
        Event::listen(SaleBenefitReleased::class, [UsedBenefits::class, 'handleBenefitReleased']);

        /*
         * Reviews, and then notifications about them.
         *
         * `JourneyCompleted` is heard by Reviews, which mints the invitation
         * after the visit commits and announces it; Notifications hears THAT
         * and puts it in a signed-in customer's inbox. Journey imports neither
         * module and never learns that either of them exists
         * (docs/22-REVIEWS.md §2).
         */
        Event::listen(JourneyCompleted::class, [ReviewSync::class, 'handleJourneyCompleted']);

        /*
         * Notifications LISTEN. Booking, Journey, Sales, Payments, Memberships,
         * Packages and Reviews emit facts and import nothing from here — three
         * architecture tests enforce the direction. Every handler below only
         * schedules work with `AfterCommit`, so a notification that could not
         * be written can never roll back the booking, the invoice, the
         * activation or the review it was about
         * (docs/23-NOTIFICATIONS.md §§2, 11).
         */
        Event::listen(AppointmentConfirmed::class, [NotifyOnBooking::class, 'handleConfirmed']);
        Event::listen(AppointmentRescheduled::class, [NotifyOnBooking::class, 'handleRescheduled']);
        Event::listen(AppointmentCancelled::class, [NotifyOnBooking::class, 'handleCancelled']);
        Event::listen(SaleFinalized::class, [NotifyOnCommerce::class, 'handleSaleFinalized']);
        Event::listen(MembershipActivated::class, [NotifyOnCommerce::class, 'handleMembershipActivated']);
        Event::listen(PackageActivated::class, [NotifyOnCommerce::class, 'handlePackageActivated']);
        Event::listen(ReviewInvitationIssued::class, [NotifyOnReviews::class, 'handleInvitationIssued']);
        Event::listen(ReviewSubmitted::class, [NotifyOnReviews::class, 'handleReviewSubmitted']);

        /*
         * Phase 13. Conversations and `Kernel\\Usage` emit facts; Notifications
         * listens. Nothing in either direction imports the other (ADR-067).
         */
        Event::listen(TakeoverRequested::class, [NotifyOnConversations::class, 'handleTakeoverRequested']);
        Event::listen(ProviderSendFailed::class, [NotifyOnConversations::class, 'handleProviderSendFailed']);
        Event::listen(UsageThresholdReached::class, [NotifyOnConversations::class, 'handleUsageThreshold']);

        // RAYAN may not reach the audit trail; the channel records changes to
        // the assistant it answers through (docs/25-WHATSAPP.md §20).
        Event::listen(AssistantSettingsChanged::class, [AuditAssistantSettings::class, 'handle']);

        // A guest (no customer account) whose booking is confirmed hears it on
        // WhatsApp — after the commit, from the channel module, never from
        // Booking or the in-app Notifications (docs/25-WHATSAPP.md §22).
        Event::listen(AppointmentConfirmed::class, [ConfirmGuestBookingOnWhatsApp::class, 'handleConfirmed']);

        // Phase 15. The Super Admin's announcements reach a center's staff
        // through the same inbox (docs/23 §6).
        Event::listen(PlatformAnnouncementPublished::class, [NotifyOnPlatformAnnouncement::class, 'handle']);

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // A token remains cryptographically valid after its owner is
        // deactivated. Authorization must not stop at "this token exists":
        // access is withdrawn the moment the account is, not whenever the
        // token happens to expire.
        //
        // This callback only ever NARROWS validity. Sanctum has already checked
        // that the token's owner matches the guard's provider model, which is
        // what keeps staff and customer tokens from crossing over; returning
        // true here could not undo that even if it wanted to.
        Sanctum::authenticateAccessTokensUsing(
            static function (PersonalAccessToken $accessToken, bool $isValid): bool {
                if (! $isValid) {
                    return false;
                }

                $owner = $accessToken->tokenable;

                return match (true) {
                    $owner instanceof User => $owner->is_active,
                    $owner instanceof CustomerAccount => $owner->canAuthenticate(),
                    // An unknown owner type is a token this application did not
                    // mean to issue. Refuse it.
                    default => false,
                };
            }
        );

        $this->rateLimiters();

        $this->persistLivewireMiddleware();

        // [area:shell] The Manager layout reads one presenter (navigation,
        // account menu, subscription banner) instead of computing in Blade.
        View::composer('components.layouts.app', ManagerShellComposer::class);
        // [area:shell] The shared interface-language menu, computed in PHP too.
        View::composer('components.navigation.language-switcher', LanguageSwitcherComposer::class);

        // [area:site-builder] The center's public pages share one frame (brand,
        // header, footer) built from the published site.
        View::composer('layouts.center-public.app', CenterPublicShellComposer::class);

        // Fatal, on purpose: a pipeline that authenticates before it resolves
        // the tenant is not a degraded mode (docs/06-AUTH-ROLES-PERMISSIONS.md §2.5).
        $guard = $this->app->make(MiddlewareOrderGuard::class);

        $guard->assert($this->app->make(Router::class));
        $guard->assertLivewirePersists(Livewire::getPersistentMiddleware());

        $this->warnAboutProductionConfiguration();
    }

    /**
     * Carries tenant resolution and locale onto Livewire's update endpoint.
     *
     * A Livewire component action does NOT post to the route that rendered it.
     * It posts to `/livewire/update`, which carries only the `web` group;
     * Livewire then replays a filtered subset of the original route's
     * middleware — the ones registered here. Without this, `auth:web` runs on
     * that endpoint with no tenant bound, and every action in the center area
     * dies in `TenantConnectionGuard`.
     *
     * Both resolvers are registered. Only the one actually on the original
     * route is ever gathered, so the public menu's path-based resolver cannot
     * leak onto an authenticated route (ADR-036); and `SetLocale` comes too, or
     * a Livewire update would re-render half a page in the platform fallback
     * language.
     */
    private function persistLivewireMiddleware(): void
    {
        Livewire::addPersistentMiddleware([
            ResolveTenant::class,
            ResolvePublicTenant::class,
            SetLocale::class,
        ]);
    }

    /**
     * Makes a misconfigured production deployment noisy instead of silent.
     *
     * Logged rather than thrown: unlike middleware ordering, these are
     * conditions a running site can survive, and refusing to boot would turn a
     * degraded deployment into an outage. But they must not pass unremarked —
     * a rate limiter on a per-process store looks identical to a working one
     * (docs/08-AUDIT-SECURITY.md §13). `metastyle:doctor` is the version of
     * this that runs before the deployment goes live.
     */
    private function warnAboutProductionConfiguration(): void
    {
        if (! $this->app->isProduction()) {
            return;
        }

        foreach ($this->app->make(ProductionReadiness::class)->failures() as $failure) {
            Log::critical('Production readiness check failed: '.$failure->name, [
                'detail' => $failure->detail,
                'remedy' => $failure->remedy,
            ]);
        }
    }

    /**
     * Rate limits for the endpoints that matter.
     *
     * Two rules shape these (docs/08-AUDIT-SECURITY.md §13):
     *
     *  - Unauthenticated endpoints that CREATE things — registration — and
     *    endpoints that accept credentials are the abusable ones, so they are
     *    tight and keyed by IP.
     *  - Anything inside tenant context is keyed by TENANT as well as by user,
     *    so one center cannot exhaust another's allowance. A shared bucket
     *    would let a single busy center throttle the whole platform.
     */
    private function rateLimiters(): void
    {
        // Creating a tenant and a database is expensive; a handful an hour from
        // one address is generous for a real signup and hostile to a script.
        RateLimiter::for('registration', fn (Request $request) => [
            Limit::perHour(10)->by($request->ip()),
            Limit::perDay(30)->by($request->ip()),
        ]);

        RateLimiter::for('registration-status', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        // Keyed by IP and by the center being targeted, so brute force against
        // one center cannot be spread across addresses without also being
        // visible per center.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perMinute(20)->by('center:'.(string) $request->input('center_key')),
        ]);

        // The public menu is a read-only page a customer opens from a QR code,
        // so the limit is generous — but it is keyed by TENANT as well as by
        // address, so hammering one center's menu cannot exhaust another's.
        RateLimiter::for('public-menu', function (Request $request) {
            $tenantId = app(TenantContext::class)->id() ?? 'none';

            return Limit::perMinute(120)->by($tenantId.'|'.$request->ip());
        });

        // A page of the center's site can carry dozens of images, so media is
        // far more generous than the page itself — still keyed by tenant and
        // address, so one center's gallery cannot exhaust another's.
        RateLimiter::for('public-media', function (Request $request) {
            $tenantId = app(TenantContext::class)->id() ?? 'none';

            return Limit::perMinute(900)->by($tenantId.'|'.$request->ip());
        });

        /*
         * Public booking. Tighter than the menu it sits behind, because this
         * one WRITES: it creates customers and appointments for an
         * unauthenticated caller. Keyed by tenant and address together, so
         * hammering one center cannot exhaust another's allowance
         * (docs/08-AUDIT-SECURITY.md §13).
         */
        RateLimiter::for('public-booking', function (Request $request) {
            $tenantId = app(TenantContext::class)->id() ?? 'none';

            return [
                Limit::perMinute(10)->by($tenantId.'|'.$request->ip()),
                Limit::perHour(40)->by($tenantId.'|'.$request->ip()),
            ];
        });

        /*
         * The public queue display.
         *
         * A television polls this every three seconds and never stops, so the
         * ceiling has to accommodate a legitimate screen left on all day —
         * 20 a minute is one poll every three seconds with room for a reload —
         * while still bounding somebody scraping a center's calls.
         *
         * Keyed by DISPLAY as well as tenant and address: a center with four
         * screens behind one office NAT must not have them throttle each other
         * (docs/17-QUEUE.md §26).
         */
        RateLimiter::for('public-display', function (Request $request) {
            $tenantId = app(TenantContext::class)->id() ?? 'none';
            $display = (string) $request->route('display');

            return Limit::perMinute(30)->by($tenantId.'|'.$display.'|'.$request->ip());
        });

        /*
         * A customer's digital invoice.
         *
         * Opened a handful of times by a person, never polled — so a low
         * ceiling per address costs a real customer nothing, and makes
         * guessing 256-bit tokens even more pointless than the entropy already
         * does (docs/18-SALES.md §28).
         */
        RateLimiter::for('public-invoice', function (Request $request) {
            $tenantId = app(TenantContext::class)->id() ?? 'none';

            return Limit::perMinute(20)->by($tenantId.'|'.$request->ip());
        });

        /*
         * A customer starting an online payment from their invoice link. Each
         * attempt reaches a provider, so the ceiling is low: a person pays a
         * bill a couple of times at most (docs/19-PAYMENTS.md §29).
         */
        RateLimiter::for('public-payment', function (Request $request) {
            $tenantId = app(TenantContext::class)->id() ?? 'none';

            return Limit::perMinute(6)->by($tenantId.'|'.$request->ip());
        });

        /*
         * The customer's review page, and the one submission it allows.
         *
         * A person opens this once, from a link or a QR code, and presses the
         * button once. Six a minute is generous for somebody re-reading the
         * form and still leaves brute force against 256 random bits exactly as
         * pointless as the entropy already makes it — while bounding somebody
         * who found a center's public key and started guessing
         * (docs/22-REVIEWS.md §5).
         */
        RateLimiter::for('public-review', function (Request $request) {
            $tenantId = app(TenantContext::class)->id() ?? 'none';

            return [
                Limit::perMinute(6)->by($tenantId.'|'.$request->ip()),
                Limit::perHour(30)->by($tenantId.'|'.$request->ip()),
            ];
        });

        /*
         * Provider callbacks, per gateway account. Generous — a provider may
         * retry in bursts — and keyed by account so one noisy integration
         * cannot starve another center's. For unsigned providers every
         * callback costs a status query, which is exactly why it is bounded
         * (docs/19-PAYMENTS.md §21).
         */
        RateLimiter::for('payment-webhook', function (Request $request) {
            $tenantId = app(TenantContext::class)->id() ?? 'none';

            return Limit::perMinute(120)->by($tenantId.'|'.(string) $request->route('account'));
        });

        /*
         * Customer sign-in and registration.
         *
         * A COARSE OUTER GUARD ONLY. The limit that actually protects an
         * account lives in `AuthenticateCustomer` via `LoginThrottle`, keyed by
         * the identifier being attacked as well as the address — a route
         * limiter cannot do that, and does not run for the Livewire sign-in at
         * all (Phase 6 §1).
         */
        RateLimiter::for('customer-login', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
            Limit::perMinute(30)->by('center:'.(string) $request->input('center_key')),
        ]);

        /*
         * Meta's webhook, per WhatsApp ACCOUNT.
         *
         * Keyed by account rather than by address for the same reason the
         * payment callback is: a provider sends these, and an attacker can
         * rotate addresses. Generous, because Meta batches and retries and a
         * center running a promotion genuinely receives a burst -- this exists
         * to stop one misconfigured integration from starving the queue, not to
         * shape normal traffic (docs/25-WHATSAPP.md §15).
         *
         * It is the OUTER guard only. The limits that actually bound abuse are
         * per SENDER and per CONVERSATION, inside the Action, where a flood is
         * dropped quietly rather than answered non-2xx -- which Meta would
         * simply retry (config/limits.php).
         */
        RateLimiter::for('whatsapp-webhook', function (Request $request) {
            $tenantId = app(TenantContext::class)->id() ?? 'none';

            return Limit::perMinute(600)->by($tenantId.'|'.(string) $request->route('account'));
        });

        RateLimiter::for('tenant-api', function (Request $request) {
            $tenantId = app(TenantContext::class)->id() ?? 'none';
            $userId = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(120)->by($tenantId.'|'.$userId);
        });

        RateLimiter::for('report-analysis', function (Request $request) {
            $tenantId = app(TenantContext::class)->id() ?? 'none';
            $userId = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(10)->by($tenantId.'|'.$userId);
        });
    }
}
