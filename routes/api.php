<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AvailabilityBlockController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CashierShiftController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CustomerAuthController;
use App\Http\Controllers\Api\CustomerBookingController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\JourneyController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MenuAdminController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PublicBookingController;
use App\Http\Controllers\Api\PublicInvoiceController;
use App\Http\Controllers\Api\PublicMenuController;
use App\Http\Controllers\Api\PublicQueueDisplayController;
use App\Http\Controllers\Api\QueueController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\ResourceController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SalesController;
use App\Http\Controllers\Api\TokenController;
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
| Guest-accessible. The center is resolved from its public key in the path by
| `public.tenant` — the one place a tenant identifier legitimately comes from
| the URL, because a customer scanning a QR code has no session, no token and
| usually no dedicated host (ADR-036).
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
    });

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
        | THERE IS NO PAYMENT ENDPOINT. Settlement is Phase 10.
        |
        */
        Route::get('sales', [SalesController::class, 'index'])->name('sales.index');
        Route::post('sales', [SalesController::class, 'store'])->name('sales.store');
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

        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::put('roles/{uuid}/permissions', [RoleController::class, 'updatePermissions'])
            ->name('roles.permissions');
        Route::put('users/{uuid}/roles', [RoleController::class, 'assignToUser'])->name('users.roles');
    });
