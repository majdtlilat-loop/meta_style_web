<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AvailabilityBlockController;
use App\Http\Controllers\Api\BookingVerificationController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CashierShiftController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\CustomerAuthController;
use App\Http\Controllers\Api\CustomerBenefitsController;
use App\Http\Controllers\Api\CustomerBookingController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerNotificationController;
use App\Http\Controllers\Api\CustomerReviewController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\GatewayAccountController;
use App\Http\Controllers\Api\JourneyController;
use App\Http\Controllers\Api\LoyaltyController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MembershipController;
use App\Http\Controllers\Api\MenuAdminController;
use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PublicBookingController;
use App\Http\Controllers\Api\PublicInvoiceController;
use App\Http\Controllers\Api\PublicMenuController;
use App\Http\Controllers\Api\PublicPaymentController;
use App\Http\Controllers\Api\PublicQueueDisplayController;
use App\Http\Controllers\Api\QueueController;
use App\Http\Controllers\Api\RayanSettingsController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ResourceController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SalesController;
use App\Http\Controllers\Api\StaffNotificationController;
use App\Http\Controllers\Api\TokenController;
use App\Http\Controllers\Api\UsageController;
use App\Http\Controllers\Api\WhatsAppAccountController;
use App\Http\Controllers\Api\WhatsAppWebhookController;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Http\Middleware\EnsureIdempotency;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes  (prefix: /api/v1)
|--------------------------------------------------------------------------
|
| Two surfaces so far (docs/10-API-FOUNDATION.md §1):
|
|   /public   no tenant bound. Registration and token issue, where the caller
|             has no session yet and must say which center it means.
|   /tenant   tenant resolved and staff authenticated. Everything else.
|
| The `/platform` surface belongs to SADMIN and does not exist yet. Adding
| empty route files for later modules is scaffolding, not architecture.
|
*/

Route::get('/health', fn () => ApiResponse::data([
    'status' => 'ok',
    'application' => config('app.name'),
    'environment' => app()->environment(),
]))->name('api.health');

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
|
| Unauthenticated and unresolved. These are the most abusable endpoints in the
| product — they create tenants and accept credentials — so both are throttled
| by IP (docs/08-AUDIT-SECURITY.md §13).
|
*/

Route::prefix('public')->name('api.public.')->group(function (): void {
    Route::post('registrations', [RegistrationController::class, 'store'])
        ->middleware('throttle:registration')
        ->name('registrations.store');

    // Polled while provisioning runs, so its limit is looser than the submit.
    // Still requires the access token: the uuid alone reads nothing (ADR-035).
    Route::get('registrations/{uuid}', [RegistrationController::class, 'show'])
        ->middleware('throttle:registration-status')
        ->name('registrations.show');

    // Requires the access token issued at submission. Throttled as a submit,
    // not as a poll: this one enqueues provisioning work.
    Route::post('registrations/{uuid}/retry', [RegistrationController::class, 'retry'])
        ->middleware('throttle:registration')
        ->name('registrations.retry');

    Route::post('auth/token', [TokenController::class, 'store'])
        ->middleware('throttle:login')
        ->name('auth.token');

    /*
     * Customer accounts. Entirely separate from staff authentication: a
     * different action, a different guard, a different token owner, and no
     * roles or permissions anywhere (Phase 5 §5).
     *
     * Gated on the `customer_accounts` entitlement inside the Actions, so the
     * WhatsApp bot and RAYAN inherit the gate when they arrive.
     */
    Route::post('customer/auth/register', [CustomerAuthController::class, 'register'])
        ->middleware('throttle:customer-login')
        ->name('customer.register');

    // A coarse outer guard. The limit that actually bounds an attack on one
    // account lives in AuthenticateCustomer, keyed by the identifier being
    // attacked as well as by the address (Phase 6 section 1).
    Route::post('customer/auth/token', [CustomerAuthController::class, 'token'])
        ->middleware('throttle:customer-login')
        ->name('customer.token');
});

/*
|--------------------------------------------------------------------------
| Customer
|--------------------------------------------------------------------------
|
| A signed-in customer, reading their own record. `auth:customer-api` resolves
| against the `customers` provider, so a STAFF token cannot reach these routes
| and a customer token cannot reach the staff ones — Sanctum compares a token's
| owner against its guard's provider model.
|
*/

Route::prefix('customer')->name('api.customer.')
    ->middleware(['tenant', 'auth:customer-api', 'locale', 'throttle:tenant-api'])
    ->group(function (): void {
        Route::get('me', [CustomerAuthController::class, 'me'])->name('me');
        Route::delete('auth/token', [CustomerAuthController::class, 'logout'])->name('logout');

        /*
         * A customer's own bookings. Identity comes from the guard on every one
         * of these; a `customer` field in a body is never read (Phase 6 §27).
         */
        Route::get('availability', [CustomerBookingController::class, 'availability'])
            ->name('availability');

        Route::get('appointments', [CustomerBookingController::class, 'index'])->name('appointments.index');
        Route::get('appointments/{uuid}', [CustomerBookingController::class, 'show'])->name('appointments.show');

        Route::post('appointments', [CustomerBookingController::class, 'store'])
            ->middleware(EnsureIdempotency::class)
            ->name('appointments.store');

        Route::post('appointments/{uuid}/reschedule', [CustomerBookingController::class, 'reschedule'])
            ->middleware(EnsureIdempotency::class)
            ->name('appointments.reschedule');

        Route::post('appointments/{uuid}/cancel', [CustomerBookingController::class, 'cancel'])
            ->name('appointments.cancel');

        // Their own points, memberships and packages — an allow-list, read-only
        // (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §19).
        Route::get('benefits', [CustomerBenefitsController::class, 'index'])->name('benefits');

        /*
         * Phase 12. Their own inbox, and their own review invitations.
         *
         * A signed-in customer needs no capability secret: their invitation is
         * resolved by uuid against their own customer record, which is why a
         * review invitation in an inbox never carries one
         * (docs/22-REVIEWS.md §40).
         */
        Route::get('notifications', [CustomerNotificationController::class, 'index'])->name('notifications.index');
        Route::get('notifications/unread', [CustomerNotificationController::class, 'unreadCount'])->name('notifications.unread');
        Route::post('notifications/read-all', [CustomerNotificationController::class, 'markAllRead'])->name('notifications.read_all');
        Route::post('notifications/{uuid}/read', [CustomerNotificationController::class, 'markRead'])->name('notifications.read');
        Route::get('notifications/preferences', [CustomerNotificationController::class, 'preferences'])->name('notifications.preferences');
        Route::put('notifications/preferences', [CustomerNotificationController::class, 'updatePreference'])->name('notifications.preferences.update');

        /*
         * Phase 13. A new verification code for a booking that is theirs.
         *
         * The OLD code stops working immediately, which is the point: a
         * customer asks for a new one when they think somebody has seen the
         * previous one. The raw value is in this response and nowhere else
         * (docs/24-BOOKING-VERIFICATION.md §§8).
         */
        Route::post('appointments/{uuid}/verification-code', [BookingVerificationController::class, 'customer'])
            ->name('appointments.verification_code');

        Route::get('reviews', [CustomerReviewController::class, 'index'])->name('reviews.index');
        Route::post('reviews/{uuid}', [CustomerReviewController::class, 'submit'])->name('reviews.submit');
    });

/*
|--------------------------------------------------------------------------
| Tenant
|--------------------------------------------------------------------------
|
| `tenant` resolves the center from the host or the token's public key and
| rejects a conflict between the two; `auth:sanctum` then authenticates against
| THAT tenant's database (docs/DECISIONS.md ADR-027).
|
*/

/*
|--------------------------------------------------------------------------
| Public menu
|--------------------------------------------------------------------------
|
| Guest-accessible. The center is resolved from its registered subdomain by
| `public.tenant`; the matching slug in the path names the public resource but
| is never trusted as a tenant-resolution source (ADR-076).
|
| THIS GROUP MUST NEVER GAIN AN AUTH MIDDLEWARE. A public key in a URL must not
| become a way to act as a center. Enforced by
| tests/Architecture/PublicSurfaceTest.php.
|
*/

Route::prefix('menu/{center}')->name('api.menu.')
    ->middleware(['public.tenant', 'locale', 'throttle:public-menu'])
    ->group(function (): void {
        Route::get('/', PublicMenuController::class)->name('show');

        Route::get('availability', [PublicBookingController::class, 'availability'])->name('availability');
    });

/*
|--------------------------------------------------------------------------
| Public booking
|--------------------------------------------------------------------------
|
| The one WRITE on the path-resolved public surface. Split from the menu group
| solely to carry a tighter throttle and the idempotency requirement: it creates
| customers and appointments for an unauthenticated caller, and a duplicate
| submit must not produce two bookings (ADR-043, §29).
|
| It carries NO auth middleware and must never gain one — the architecture test
| that guards `public.tenant` covers this route too (ADR-036).
|
*/

Route::prefix('menu/{center}')->name('api.menu.')
    ->middleware(['public.tenant', 'locale', 'throttle:public-booking', EnsureIdempotency::class])
    ->group(function (): void {
        Route::post('bookings', [PublicBookingController::class, 'store'])->name('bookings.store');
    });

/*
|--------------------------------------------------------------------------
| The public queue display feed
|--------------------------------------------------------------------------
|
| docs/17-QUEUE.md §§9, 14, 26.
|
| A television in a waiting room, polling. Resolved from the center's public key
| and a per-display key, both opaque and both rotatable — never an internal id
| and never a staff URL on a screen nobody is watching (ADR-027, ADR-036).
|
| READ ONLY, and no auth middleware, ever. The architecture test that guards
| every `public.tenant` route covers this one too.
|
| It emits a number and a destination. Nothing that could identify the customer
| holding it (§48).
|
*/

Route::prefix('queue/{center}')->name('api.queue.')
    ->middleware(['public.tenant', 'locale', 'throttle:public-display'])
    ->group(function (): void {
        Route::get('displays/{display}', PublicQueueDisplayController::class)->name('display');
    });

/*
|--------------------------------------------------------------------------
| The customer's digital invoice
|--------------------------------------------------------------------------
|
| docs/18-SALES.md §§20, 28.
|
| Resolved from the center's public key and the invoice's 256-bit share token —
| never an invoice number, never an id, so the URL space cannot be walked.
| READ ONLY, and no auth middleware, ever: the architecture test guarding every
| `public.tenant` route covers this one too.
|
*/

Route::prefix('invoices/{center}')->name('api.invoices.')
    ->middleware(['public.tenant', 'locale', 'throttle:public-invoice'])
    ->group(function (): void {
        Route::get('{token}', PublicInvoiceController::class)->name('public');

        // What is paid and left, and which online options the branch offers.
        // Allow-listed (docs/19-PAYMENTS.md §§29, 58).
        Route::get('{token}/payment', [PublicPaymentController::class, 'options'])->name('payment');
    });

/*
|--------------------------------------------------------------------------
| Paying an invoice online, from its link
|--------------------------------------------------------------------------
|
| The share secret is the authority; the server decides the amount. Its own,
| lower throttle: every attempt reaches a payment provider.
|
*/

Route::post('invoices/{center}/{token}/payments', [PublicPaymentController::class, 'store'])
    ->middleware(['public.tenant', 'locale', 'throttle:public-payment'])
    ->name('api.invoices.payments.store');

/*
|--------------------------------------------------------------------------
| Payment provider callbacks
|--------------------------------------------------------------------------
|
| docs/19-PAYMENTS.md §§21–24.
|
| The center by its public key, the gateway account by its public uuid —
| neither a secret, and neither enough to settle anything: a callback is
| believed only after the provider's signature is verified or its status is
| read back with the center's own credentials. No auth middleware, ever. No
| entitlement either — money already moving cannot depend on today's plan.
|
*/

Route::post('payments/{center}/gateways/{account}/webhook', PaymentWebhookController::class)
    ->middleware(['public.tenant', 'locale', 'throttle:payment-webhook'])
    ->name('api.payments.webhook');

/*
|--------------------------------------------------------------------------
| WhatsApp provider callbacks
|--------------------------------------------------------------------------
|
| docs/25-WHATSAPP.md §§8.
|
| The center by its public key, the WhatsApp account by its public uuid --
| neither a secret, and neither enough to do anything. An inbound notification
| is believed only after its signature verifies against the center's own app
| secret, and nothing before that point touches a customer, a conversation or
| the assistant.
|
| No auth middleware, ever. No entitlement either: a center that has stopped
| paying still receives delivery callbacks for messages already in flight, and
| refusing them would strand outbound rows as `pending` forever.
|
| The GET is Meta's registration handshake and echoes a challenge; the POST is
| every notification. Both are throttled per ACCOUNT rather than per address,
| because a provider sends them and an attacker can rotate addresses.
|
*/

Route::get('whatsapp/{center}/accounts/{account}/webhook', [WhatsAppWebhookController::class, 'verify'])
    ->middleware(['public.tenant', 'locale', 'throttle:whatsapp-webhook'])
    ->name('api.whatsapp.verify');

Route::post('whatsapp/{center}/accounts/{account}/webhook', WhatsAppWebhookController::class)
    ->middleware(['public.tenant', 'locale', 'throttle:whatsapp-webhook'])
    ->name('api.whatsapp.webhook');

Route::prefix('tenant')->name('api.tenant.')
    ->middleware(['tenant', 'auth:sanctum', 'locale', 'throttle:tenant-api'])
    ->group(function (): void {
        Route::get('me', MeController::class)->name('me');
        Route::delete('auth/token', [TokenController::class, 'destroy'])->name('auth.token.destroy');

        Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::post('employees', [EmployeeController::class, 'store'])->name('employees.store');
        Route::patch('employees/{uuid}/status', [EmployeeController::class, 'updateStatus'])
            ->name('employees.status');

        /*
         * Branches
         */
        Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
        Route::post('branches', [BranchController::class, 'store'])->name('branches.store');
        Route::put('branches/{uuid}', [BranchController::class, 'update'])->name('branches.update');
        Route::put('branches/{uuid}/schedule', [BranchController::class, 'schedule'])->name('branches.schedule');
        Route::delete('branches/{uuid}', [BranchController::class, 'archive'])->name('branches.archive');

        /*
         * Catalog — departments, categories, services
         */
        Route::get('catalog', [CatalogController::class, 'index'])->name('catalog.index');
        Route::post('catalog/departments', [CatalogController::class, 'storeDepartment'])
            ->name('catalog.departments.store');
        Route::delete('catalog/departments/{uuid}', [CatalogController::class, 'archiveDepartment'])
            ->name('catalog.departments.archive');
        Route::post('catalog/categories', [CatalogController::class, 'storeCategory'])
            ->name('catalog.categories.store');
        Route::post('catalog/services', [CatalogController::class, 'storeService'])
            ->name('catalog.services.store');
        Route::put('catalog/services/{uuid}', [CatalogController::class, 'updateService'])
            ->name('catalog.services.update');
        Route::delete('catalog/services/{uuid}', [CatalogController::class, 'archiveService'])
            ->name('catalog.services.archive');

        /*
         * Electronic menu appearance
         */
        Route::get('menu', [MenuAdminController::class, 'show'])->name('menu.show');
        Route::put('menu/draft', [MenuAdminController::class, 'saveDraft'])->name('menu.draft');
        Route::post('menu/publish', [MenuAdminController::class, 'publish'])->name('menu.publish');
        Route::post('menu/rollback/{uuid}', [MenuAdminController::class, 'rollback'])->name('menu.rollback');

        /*
         * Customer CRM. Never gated on `customer_accounts` — that entitlement
         * controls whether customers get a LOGIN, not whether the center may
         * keep customer records (Phase 5 §26).
         */
        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::get('customers/{uuid}', [CustomerController::class, 'show'])->name('customers.show');
        Route::put('customers/{uuid}', [CustomerController::class, 'update'])->name('customers.update');
        Route::delete('customers/{uuid}', [CustomerController::class, 'archive'])->name('customers.archive');
        Route::post('customers/{uuid}/restore', [CustomerController::class, 'restore'])
            ->name('customers.restore');

        Route::post('customers/{uuid}/notes', [CustomerController::class, 'storeNote'])
            ->name('customers.notes.store');
        Route::delete('customers/{uuid}/notes/{noteUuid}', [CustomerController::class, 'destroyNote'])
            ->name('customers.notes.destroy');

        /*
         * Booking. Availability and the calendar are reads; everything that
         * mutates goes through the same engine the public and customer surfaces
         * use (docs/04-MODULE-BOUNDARIES.md §4.1).
         */
        Route::get('availability', [AppointmentController::class, 'availability'])->name('availability');
        Route::get('calendar', [AppointmentController::class, 'calendar'])->name('calendar');
        Route::get('appointments/affected', [AppointmentController::class, 'affected'])
            ->name('appointments.affected');

        Route::post('appointments', [AppointmentController::class, 'store'])
            ->middleware(EnsureIdempotency::class)
            ->name('appointments.store');

        Route::get('appointments/{uuid}', [AppointmentController::class, 'show'])->name('appointments.show');

        Route::post('appointments/{uuid}/reschedule', [AppointmentController::class, 'reschedule'])
            ->middleware(EnsureIdempotency::class)
            ->name('appointments.reschedule');

        // Status changes are idempotent by construction: the lifecycle refuses
        // a second attempt, so a retry cannot double-apply one.
        Route::post('appointments/{uuid}/confirm', [AppointmentController::class, 'confirm'])
            ->name('appointments.confirm');
        Route::post('appointments/{uuid}/complete', [AppointmentController::class, 'complete'])
            ->name('appointments.complete');
        Route::post('appointments/{uuid}/no-show', [AppointmentController::class, 'noShow'])
            ->name('appointments.no_show');
        Route::post('appointments/{uuid}/cancel', [AppointmentController::class, 'cancel'])
            ->name('appointments.cancel');

        Route::post('appointments/{uuid}/notes', [AppointmentController::class, 'storeNote'])
            ->name('appointments.notes.store');
        Route::delete('appointments/{uuid}/notes/{noteUuid}', [AppointmentController::class, 'destroyNote'])
            ->name('appointments.notes.destroy');

        /*
         |--------------------------------------------------------------------
         | Phase 7 — Resources and the operational floor
         |--------------------------------------------------------------------
         |
         | Staff only. There is deliberately NO public or customer journey
         | surface: a customer does not need to know which room they are in or
         | that their stylist was swapped (docs/13-ROADMAP.md Phase 7 §46).
         |
         */

        Route::get('resource-types', [ResourceController::class, 'types'])->name('resource_types.index');
        Route::post('resource-types', [ResourceController::class, 'storeType'])->name('resource_types.store');

        Route::get('resources', [ResourceController::class, 'index'])->name('resources.index');
        Route::post('resources', [ResourceController::class, 'store'])->name('resources.store');
        Route::put('resources/{uuid}', [ResourceController::class, 'update'])->name('resources.update');
        Route::delete('resources/{uuid}', [ResourceController::class, 'archive'])->name('resources.archive');

        // Replaced wholesale: a partial API would let a caller leave a service
        // half-configured, and a service needing the room but not the machine
        // is bookable and wrong (§5).
        Route::put('services/{uuid}/resource-requirements', [ResourceController::class, 'setRequirements'])
            ->name('services.resource_requirements');

        Route::get('availability-blocks', [AvailabilityBlockController::class, 'index'])
            ->name('availability_blocks.index');
        Route::post('availability-blocks', [AvailabilityBlockController::class, 'store'])
            ->name('availability_blocks.store');
        Route::put('availability-blocks/{uuid}', [AvailabilityBlockController::class, 'update'])
            ->name('availability_blocks.update');
        Route::delete('availability-blocks/{uuid}', [AvailabilityBlockController::class, 'destroy'])
            ->name('availability_blocks.destroy');

        /*
         | Service Journey. No idempotency header on any of these: the database
         | invariants make them naturally idempotent — one journey per
         | appointment by unique key, and a stage transition the enum refuses to
         | repeat — so forcing a key onto every internal button would be
         | ceremony without a guarantee behind it (§48).
         */
        Route::get('journey/board', [JourneyController::class, 'board'])->name('journey.board');

        Route::post('appointments/{uuid}/check-in', [JourneyController::class, 'checkIn'])
            ->name('journey.check_in');

        Route::get('journeys/{uuid}', [JourneyController::class, 'show'])->name('journey.show');
        Route::post('journeys/{uuid}/complete', [JourneyController::class, 'complete'])
            ->name('journey.complete');
        Route::post('journeys/{uuid}/abort', [JourneyController::class, 'abort'])->name('journey.abort');

        Route::post('journey-stages/{uuid}/start', [JourneyController::class, 'startStage'])
            ->name('journey.stage.start');
        Route::post('journey-stages/{uuid}/complete', [JourneyController::class, 'completeStage'])
            ->name('journey.stage.complete');
        Route::post('journey-stages/{uuid}/skip', [JourneyController::class, 'skipStage'])
            ->name('journey.stage.skip');
        Route::post('journey-stages/{uuid}/reassign', [JourneyController::class, 'reassignStage'])
            ->name('journey.stage.reassign');
        Route::post('journey-stages/{uuid}/swap-resource', [JourneyController::class, 'swapResource'])
            ->name('journey.stage.swap_resource');
        Route::post('journey-stages/{uuid}/handoff', [JourneyController::class, 'handoff'])
            ->name('journey.stage.handoff');

        Route::post('journey-stages/{uuid}/notes', [JourneyController::class, 'storeNote'])
            ->name('journey.stage.notes.store');
        Route::delete('journey-stages/{uuid}/notes/{noteUuid}', [JourneyController::class, 'destroyNote'])
            ->name('journey.stage.notes.destroy');

        /*
        |--------------------------------------------------------------------
        | Queue — waiting, calling, routing
        |--------------------------------------------------------------------
        |
        | docs/17-QUEUE.md §21.
        |
        | THERE IS NO "complete ticket" AND NO "set serving" ENDPOINT. Both
        | follow the Journey fact: `start` and `complete` below call Journey's
        | Action and the synchronizer moves the ticket, so a queue button can
        | never claim a customer was served while their stage was waiting
        | (correction 3).
        |
        | Call and recall are one endpoint, because they are one gesture — the
        | Action decides which it was from the ticket's state.
        |
        */
        Route::get('queue/board', [QueueController::class, 'board'])->name('queue.board');
        Route::get('queue/tickets/{uuid}', [QueueController::class, 'show'])->name('queue.show');

        Route::post('queue/tickets', [QueueController::class, 'issue'])->name('queue.issue');
        Route::post('queue/walk-ins', [QueueController::class, 'walkIn'])->name('queue.walk_in');

        Route::post('queue/tickets/{uuid}/call', [QueueController::class, 'call'])->name('queue.call');
        Route::post('queue/tickets/{uuid}/hold', [QueueController::class, 'hold'])->name('queue.hold');
        Route::post('queue/tickets/{uuid}/resume', [QueueController::class, 'resume'])->name('queue.resume');
        Route::post('queue/tickets/{uuid}/transfer', [QueueController::class, 'transfer'])->name('queue.transfer');
        Route::post('queue/tickets/{uuid}/priority', [QueueController::class, 'priority'])->name('queue.priority');
        Route::post('queue/tickets/{uuid}/cancel', [QueueController::class, 'cancel'])->name('queue.cancel');

        Route::post('queue/tickets/{uuid}/start', [QueueController::class, 'start'])->name('queue.start');
        Route::post('queue/tickets/{uuid}/complete', [QueueController::class, 'complete'])->name('queue.complete');

        Route::post('queue/visits/{uuid}/abandon', [QueueController::class, 'abandon'])->name('queue.abandon');
        Route::get('queue/tickets/{uuid}/print', [QueueController::class, 'print'])->name('queue.print');

        Route::get('queue/service-points', [QueueController::class, 'servicePoints'])
            ->name('queue.service_points.index');
        Route::post('queue/service-points', [QueueController::class, 'storeServicePoint'])
            ->name('queue.service_points.store');
        Route::put('queue/service-points/{uuid}', [QueueController::class, 'updateServicePoint'])
            ->name('queue.service_points.update');
        Route::delete('queue/service-points/{uuid}', [QueueController::class, 'archiveServicePoint'])
            ->name('queue.service_points.archive');

        Route::post('queue/displays', [QueueController::class, 'storeDisplay'])->name('queue.displays.store');

        /*
        |--------------------------------------------------------------------
        | Sales — the commercial transaction, its invoice, the till session
        |--------------------------------------------------------------------
        |
        | docs/18-SALES.md §42.
        |
        | A draft sale IS the cart, so cart edits are sale edits. Every total is
        | computed server-side; the only request that carries a price at all is
        | a custom line or an override, and both need `sale.adjust` plus a
        | reason.
        |
        | Sales has no payment endpoint of its own: collecting money against an
        | invoice is the Payments module, below (Phase 10).
        |
        */
        Route::get('sales', [SalesController::class, 'index'])->name('sales.index');
        Route::post('sales', [SalesController::class, 'store'])->name('sales.store');
        // Before `sales/{uuid}`, which would otherwise swallow it.
        Route::get('sales/offerings', [SalesController::class, 'offerings'])->name('sales.offerings');
        Route::get('sales/{uuid}', [SalesController::class, 'show'])->name('sales.show');
        Route::delete('sales/{uuid}', [SalesController::class, 'destroy'])->name('sales.discard');

        Route::post('sales/{uuid}/items', [SalesController::class, 'addLine'])->name('sales.items.store');
        Route::patch('sales/{uuid}/items/{lineUuid}', [SalesController::class, 'updateLine'])->name('sales.items.update');
        Route::delete('sales/{uuid}/items/{lineUuid}', [SalesController::class, 'removeLine'])->name('sales.items.destroy');
        Route::post('sales/{uuid}/items/{lineUuid}/price-override', [SalesController::class, 'overridePrice'])
            ->name('sales.items.price_override');
        Route::delete('sales/{uuid}/items/{lineUuid}/price-override', [SalesController::class, 'clearPriceOverride'])
            ->name('sales.items.price_override.clear');

        Route::put('sales/{uuid}/customer', [SalesController::class, 'customer'])->name('sales.customer');
        Route::post('sales/{uuid}/adjustments', [SalesController::class, 'addAdjustment'])->name('sales.adjustments.store');
        Route::delete('sales/{uuid}/adjustments/{adjustmentUuid}', [SalesController::class, 'removeAdjustment'])
            ->name('sales.adjustments.destroy');

        Route::post('sales/{uuid}/finalize', [SalesController::class, 'finalize'])->name('sales.finalize');
        Route::post('sales/{uuid}/void', [SalesController::class, 'void'])->name('sales.void');

        Route::post('journeys/{journeyUuid}/checkout', [SalesController::class, 'checkout'])->name('sales.checkout');

        Route::get('invoices/{invoiceUuid}', [SalesController::class, 'invoice'])->name('invoices.show');
        Route::get('invoices/{invoiceUuid}/printable', [SalesController::class, 'printable'])->name('invoices.printable');
        Route::post('invoices/{invoiceUuid}/share-link', [SalesController::class, 'rotateLink'])->name('invoices.share_link');

        Route::put('branches/{branchUuid}/invoice-prefix', [SalesController::class, 'invoicePrefix'])
            ->name('branches.invoice_prefix');

        Route::post('cashier-shifts', [CashierShiftController::class, 'open'])->name('cashier_shifts.open');
        Route::get('cashier-shifts/current', [CashierShiftController::class, 'current'])->name('cashier_shifts.current');
        Route::post('cashier-shifts/{uuid}/close', [CashierShiftController::class, 'close'])->name('cashier_shifts.close');

        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::post('products', [ProductController::class, 'store'])->name('products.store');
        Route::put('products/{uuid}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('products/{uuid}', [ProductController::class, 'archive'])->name('products.archive');

        /*
        |--------------------------------------------------------------------
        | Payments — money collected against invoices, and returned
        |--------------------------------------------------------------------
        |
        | docs/19-PAYMENTS.md §57.
        |
        | Cash and manual transfers need `pos`; online payments and gateway
        | accounts need `payments`; reading needs neither. The Actions check —
        | these routes only validate and present.
        |
        */
        Route::get('invoices/{invoiceUuid}/payments', [PaymentController::class, 'settlement'])->name('payments.settlement');
        Route::post('invoices/{invoiceUuid}/payments', [PaymentController::class, 'collect'])->name('payments.collect');
        Route::get('payments/{uuid}', [PaymentController::class, 'show'])->name('payments.show');
        Route::post('payments/{uuid}/refresh', [PaymentController::class, 'refresh'])->name('payments.refresh');
        Route::post('payments/{uuid}/cancel', [PaymentController::class, 'cancel'])->name('payments.cancel');
        Route::post('payments/{uuid}/refunds', [PaymentController::class, 'refund'])->name('payments.refunds.store');
        Route::get('refunds/{uuid}', [PaymentController::class, 'showRefund'])->name('refunds.show');

        Route::get('branches/{branchUuid}/payment-gateways', [GatewayAccountController::class, 'index'])->name('payment_gateways.index');
        Route::put('branches/{branchUuid}/payment-gateways/{provider}', [GatewayAccountController::class, 'configure'])
            ->name('payment_gateways.configure');
        Route::post('payment-gateways/{uuid}/enable', [GatewayAccountController::class, 'enable'])->name('payment_gateways.enable');
        Route::post('payment-gateways/{uuid}/disable', [GatewayAccountController::class, 'disable'])->name('payment_gateways.disable');

        /*
        |--------------------------------------------------------------------
        | Finance — the center's ledger, expenses and drawer counts
        |--------------------------------------------------------------------
        |
        | docs/20-FINANCE.md §57. Center finance, never Meta Style's own
        | billing: nothing here reads or writes the control plane.
        |
        */
        Route::get('finance/dashboard', [FinanceController::class, 'dashboard'])->name('finance.dashboard');
        Route::get('finance/ledger', [FinanceController::class, 'ledger'])->name('finance.ledger');

        Route::get('finance/expense-categories', [FinanceController::class, 'categories'])->name('finance.categories.index');
        Route::post('finance/expense-categories', [FinanceController::class, 'storeCategory'])->name('finance.categories.store');
        Route::put('finance/expense-categories/{uuid}', [FinanceController::class, 'updateCategory'])->name('finance.categories.update');
        Route::delete('finance/expense-categories/{uuid}', [FinanceController::class, 'archiveCategory'])->name('finance.categories.archive');

        Route::get('finance/expenses', [FinanceController::class, 'expenses'])->name('finance.expenses.index');
        Route::post('finance/expenses', [FinanceController::class, 'storeExpense'])->name('finance.expenses.store');
        Route::post('finance/expenses/{uuid}/void', [FinanceController::class, 'voidExpense'])->name('finance.expenses.void');

        Route::get('cashier-shifts/{uuid}/expected-cash', [FinanceController::class, 'expectedCash'])->name('cashier_shifts.expected_cash');
        Route::post('cashier-shifts/{uuid}/close-with-count', [FinanceController::class, 'closeShift'])->name('cashier_shifts.close_with_count');

        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::put('roles/{uuid}/permissions', [RoleController::class, 'updatePermissions'])
            ->name('roles.permissions');
        Route::put('users/{uuid}/roles', [RoleController::class, 'assignToUser'])->name('users.roles');

        /*
        |--------------------------------------------------------------------
        | Loyalty, memberships and service packages
        |--------------------------------------------------------------------
        |
        | docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §27.
        |
        | Memberships and packages are SOLD through `sales/{uuid}/items` like
        | any line; nothing here takes money. Using a benefit is a change to a
        | draft sale, so it lives under the sale and its line. The Actions check
        | the entitlement and the permission — a downgrade stops selling and
        | earning, never reading or using what a customer already paid for.
        |
        */
        Route::get('loyalty/program', [LoyaltyController::class, 'program'])->name('loyalty.program');
        Route::put('loyalty/program', [LoyaltyController::class, 'configure'])->name('loyalty.configure');
        Route::post('loyalty/tiers', [LoyaltyController::class, 'storeTier'])->name('loyalty.tiers.store');
        Route::put('loyalty/tiers/{uuid}', [LoyaltyController::class, 'updateTier'])->name('loyalty.tiers.update');
        Route::delete('loyalty/tiers/{uuid}', [LoyaltyController::class, 'archiveTier'])->name('loyalty.tiers.archive');
        Route::get('customers/{uuid}/loyalty', [LoyaltyController::class, 'customer'])->name('customers.loyalty');
        Route::post('customers/{uuid}/loyalty/adjustments', [LoyaltyController::class, 'adjust'])->name('customers.loyalty.adjust');
        Route::post('sales/{uuid}/loyalty-redemption', [LoyaltyController::class, 'redeem'])->name('sales.loyalty.redeem');
        Route::delete('sales/{uuid}/loyalty-redemption', [LoyaltyController::class, 'withdraw'])->name('sales.loyalty.withdraw');

        Route::get('membership-plans', [MembershipController::class, 'plans'])->name('membership_plans.index');
        Route::post('membership-plans', [MembershipController::class, 'store'])->name('membership_plans.store');
        Route::put('membership-plans/{uuid}', [MembershipController::class, 'update'])->name('membership_plans.update');
        Route::delete('membership-plans/{uuid}', [MembershipController::class, 'archive'])->name('membership_plans.archive');
        Route::get('customers/{uuid}/memberships', [MembershipController::class, 'customer'])->name('customers.memberships');
        Route::post('customer-memberships/{uuid}/cancel', [MembershipController::class, 'cancel'])->name('customer_memberships.cancel');
        Route::post('sales/{uuid}/items/{lineUuid}/membership-benefit', [MembershipController::class, 'apply'])->name('sales.items.membership.apply');
        Route::delete('sales/{uuid}/items/{lineUuid}/membership-benefit', [MembershipController::class, 'withdraw'])->name('sales.items.membership.withdraw');

        Route::get('package-definitions', [PackageController::class, 'definitions'])->name('package_definitions.index');
        Route::post('package-definitions', [PackageController::class, 'store'])->name('package_definitions.store');
        Route::put('package-definitions/{uuid}', [PackageController::class, 'update'])->name('package_definitions.update');
        Route::delete('package-definitions/{uuid}', [PackageController::class, 'archive'])->name('package_definitions.archive');
        Route::get('customers/{uuid}/packages', [PackageController::class, 'customer'])->name('customers.packages');
        Route::post('customer-packages/{uuid}/cancel', [PackageController::class, 'cancel'])->name('customer_packages.cancel');
        Route::post('sales/{uuid}/items/{lineUuid}/package', [PackageController::class, 'apply'])->name('sales.items.package.apply');
        Route::delete('sales/{uuid}/items/{lineUuid}/package', [PackageController::class, 'withdraw'])->name('sales.items.package.withdraw');

        /*
         * Phase 12. Reviews: what customers said, the rating summary, and the
         * two moderation actions. Branch scope is applied by the query, never
         * by the filters a caller sends (docs/22-REVIEWS.md §§23, 50).
         *
         * `reviews/invitations/{journey}` is the only place a plaintext review
         * secret leaves the system, and it returns it ONCE — there is no read
         * that could show it again (§5).
         */
        Route::get('reviews', [ReviewController::class, 'index'])->name('reviews.index');
        Route::get('reviews/summary', [ReviewController::class, 'summary'])->name('reviews.summary');
        Route::get('reviews/{uuid}', [ReviewController::class, 'show'])->name('reviews.show');
        Route::post('reviews/{uuid}/hide', [ReviewController::class, 'hide'])->name('reviews.hide');
        Route::post('reviews/{uuid}/unhide', [ReviewController::class, 'unhide'])->name('reviews.unhide');
        Route::post('reviews/{uuid}/flag', [ReviewController::class, 'flag'])->name('reviews.flag');
        Route::post('reviews/invitations/{journeyUuid}', [ReviewController::class, 'reissue'])->name('reviews.invitations.reissue');

        /* Phase 14 reports: Standard always reads Primary; Advanced always reads Reporting. */
        Route::get('reports/{report}', [ReportController::class, 'standard'])->name('reports.show');
        Route::get('advanced-reports/{report}', [ReportController::class, 'advanced'])->name('advanced_reports.show');
        Route::post('advanced-reports/{report}/analyze', [ReportController::class, 'analyze'])
            ->middleware('throttle:report-analysis')
            ->name('advanced_reports.analyze');

        /*
         * The signed-in staff member's OWN inbox. No permission and no
         * entitlement: everybody has one, and nobody reads anybody else's
         * (docs/23-NOTIFICATIONS.md §16).
         */
        Route::get('notifications', [StaffNotificationController::class, 'index'])->name('notifications.index');
        Route::get('notifications/unread', [StaffNotificationController::class, 'unreadCount'])->name('notifications.unread');
        Route::post('notifications/read-all', [StaffNotificationController::class, 'markAllRead'])->name('notifications.read_all');
        Route::post('notifications/{uuid}/read', [StaffNotificationController::class, 'markRead'])->name('notifications.read');
        Route::get('notifications/preferences', [StaffNotificationController::class, 'preferences'])->name('notifications.preferences');
        Route::put('notifications/preferences', [StaffNotificationController::class, 'updatePreference'])->name('notifications.preferences.update');

        /*
        |------------------------------------------------------------------
        | Phase 13 -- conversations, the connection, the assistant, usage
        |------------------------------------------------------------------
        |
        | docs/25-WHATSAPP.md §§19, docs/27-RAYAN.md §§5.
        |
        | Every one of these authorises inside its Action or controller by
        | PERMI§§ION and BRANCH; the group's middleware only establishes which
        | center and which user (ADR-029).
        |
        */

        Route::get('conversations', [ConversationController::class, 'index'])->name('conversations.index');
        Route::get('conversations/{uuid}', [ConversationController::class, 'show'])->name('conversations.show');
        Route::post('conversations/{uuid}/reply', [ConversationController::class, 'reply'])->name('conversations.reply');
        Route::post('conversations/{uuid}/takeover', [ConversationController::class, 'takeOver'])->name('conversations.takeover');
        Route::post('conversations/{uuid}/return-to-assistant', [ConversationController::class, 'returnToAssistant'])->name('conversations.return');
        Route::post('conversations/{uuid}/close', [ConversationController::class, 'close'])->name('conversations.close');

        Route::get('whatsapp/account', [WhatsAppAccountController::class, 'show'])->name('whatsapp.account.show');
        Route::put('whatsapp/account', [WhatsAppAccountController::class, 'update'])->name('whatsapp.account.update');

        Route::get('rayan/settings', [RayanSettingsController::class, 'show'])->name('rayan.settings.show');
        Route::put('rayan/settings', [RayanSettingsController::class, 'update'])->name('rayan.settings.update');

        // The manager's usage dashboard, behind `settings.view` (§§61).
        Route::get('usage', UsageController::class)->name('usage');

        /*
         * Staff issuing or re-issuing a booking's verification code. Audited,
         * branch-scoped, and the raw code is returned exactly once.
         */
        Route::post('appointments/{uuid}/verification-code', [BookingVerificationController::class, 'staff'])
            ->name('appointments.verification_code');

    });
