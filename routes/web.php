<?php

declare(strict_types=1);

use App\Http\Controllers\InvoicePrintController;
use App\Http\Controllers\PublicBookingPageController;
use App\Http\Controllers\PublicInvoicePageController;
use App\Http\Controllers\PublicMenuPageController;
use App\Http\Controllers\QueueDisplayPageController;
use App\Http\Controllers\QueueTicketPrintController;
use App\Kernel\Tenancy\Infrastructure\StanclTenantResolver;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\RegisterCenter;
use App\Livewire\Auth\RegistrationStatus;
use App\Livewire\Center\Branches;
use App\Livewire\Center\Calendar;
use App\Livewire\Center\Catalog;
use App\Livewire\Center\Customers;
use App\Livewire\Center\Dashboard;
use App\Livewire\Center\JourneyBoard;
use App\Livewire\Center\MenuDesigner;
use App\Livewire\Center\PointOfSale;
use App\Livewire\Center\QueueBoard;
use App\Livewire\Center\Resources;
use App\Livewire\Center\Roles;
use App\Livewire\Center\Sales;
use App\Livewire\Center\Staff;
use App\Livewire\Customer\Account as CustomerAccountPage;
use App\Livewire\Customer\SignIn as CustomerSignIn;
use App\Livewire\SystemStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Minimal on purpose. Enough to exercise the Phase 3 flows end to end —
| register, wait, sign in, manage staff and roles — and no further. The Meta
| Style design system and the real dashboard are later frontend work
| (docs/13-ROADMAP.md Phase 3 Part N).
|
*/

Route::get('/', SystemStatus::class)->name('home');

/*
 * The public electronic menu.
 *
 * Guest-accessible, resolved from the center's public key in the path (or from
 * the center's own host, if it has one). This group must NEVER gain an auth
 * middleware — a public key in a URL must not become a way to act as a center
 * (ADR-036, enforced by tests/Feature/Menu/PublicRouteBoundaryTest.php).
 */
Route::get('/m/{center}', PublicMenuPageController::class)
    ->middleware(['public.tenant', 'locale', 'throttle:public-menu'])
    ->name('menu.public');

/*
 * Guest booking.
 *
 * Plain server-rendered forms rather than Livewire: the menu is deliberately a
 * no-JavaScript page opened from a QR code on a slow connection, and the
 * booking flow keeps that promise. It also keeps the path-resolving public
 * middleware away from Livewire's update endpoint entirely, which is one fewer
 * place ADR-036 has to hold.
 *
 * NO AUTH MIDDLEWARE, ever — enforced for every `public.tenant` route by
 * tests/Feature/Menu/PublicRouteBoundaryTest.php.
 */
Route::get('/m/{center}/book', [PublicBookingPageController::class, 'show'])
    ->middleware(['public.tenant', 'locale', 'throttle:public-menu'])
    ->name('menu.book');

Route::post('/m/{center}/book', [PublicBookingPageController::class, 'store'])
    ->middleware(['public.tenant', 'locale', 'throttle:public-booking'])
    ->name('menu.book.store');

// Central: no tenant bound. Signing up does not belong to a center yet, and
// signing in is where the caller says which center it means.
Route::middleware('throttle:registration')->group(function (): void {
    Route::get('/register', RegisterCenter::class)->name('register');
});

Route::get('/register/{uuid}/status', RegistrationStatus::class)->name('registration.status');
Route::get('/login', Login::class)->middleware('guest')->name('login');

/*
 * Customer accounts.
 *
 * Central, not under /m/{center}, on purpose. `ResolvePublicTenant` accepts a
 * public key from the URL path and must never sit on a route that authenticates
 * anybody (ADR-036) — so customer login names its center in the FORM and records
 * it in the session, exactly as staff login does (ADR-030). The `?center=`
 * query only pre-fills the field.
 */
Route::get('/customer/sign-in', CustomerSignIn::class)
    ->middleware('throttle:login')
    ->name('customer.signin');

// Tenant-bound, exactly like the staff center area: by the time a customer
// reaches this, sign-in has recorded their center in the session.
Route::get('/customer/account', CustomerAccountPage::class)
    ->middleware(['tenant', 'auth:customer', 'locale'])
    ->name('customer.account');

Route::post('/logout', function () {
    Auth::guard('web')->logout();

    session()->forget(StanclTenantResolver::SESSION_KEY);
    session()->invalidate();
    session()->regenerateToken();

    return redirect()->route('login');
})->name('logout');

/*
 * The center area. `tenant` resolves the center from the session recorded at
 * login (or from the host, when a center has its own domain) BEFORE
 * authentication runs — the staff account lives in that center's database.
 */
Route::middleware(['tenant', 'auth:web', 'locale'])->prefix('center')->name('center.')->group(function (): void {
    Route::get('/', Dashboard::class)->name('dashboard');
    Route::get('/staff', Staff::class)->name('staff');
    Route::get('/roles', Roles::class)->name('roles');
    Route::get('/branches', Branches::class)->name('branches');
    Route::get('/catalog', Catalog::class)->name('catalog');
    Route::get('/menu', MenuDesigner::class)->name('menu');
    Route::get('/customers', Customers::class)->name('customers');
    Route::get('/calendar', Calendar::class)->name('calendar');

    // Phase 7. `resources` manages the physical side of a center; `board` is
    // the operational floor.
    Route::get('/resources', Resources::class)->name('resources');
    Route::get('/board', JourneyBoard::class)->name('board');

    /*
     * Phase 8. The QUEUE is the waiting and calling around the floor: walk-ins,
     * numbers, calls, destinations. The visit board above remains what actually
     * happened; the two are different screens for different jobs
     * (docs/17-QUEUE.md §21).
     */
    Route::get('/queue', QueueBoard::class)->name('queue');

    // A printable 80mm ticket. Its own page rather than a modal, because the
    // browser's print dialog needs a document (§17).
    Route::get('/queue/tickets/{uuid}/print', QueueTicketPrintController::class)
        ->name('queue.ticket');

    /*
     * Phase 9. The TILL is where carts are built, invoices published and shifts
     * opened; SALES is the day's history, with void. A visit's checkout arrives
     * at the till as `?journey={uuid}` — the visit board links here and
     * imports nothing from Sales (docs/18-SALES.md §§43–45).
     */
    Route::get('/pos', PointOfSale::class)->name('pos');
    Route::get('/sales', Sales::class)->name('sales');

    // Printable invoice pages, for the browser's print dialog (§24).
    Route::get('/sales/invoices/{uuid}/print/{format}', InvoicePrintController::class)
        ->where('format', '80mm|a4')
        ->name('sales.invoice.print');
});

/*
 * The public queue display.
 *
 * A television in a waiting room. Resolved from the center's public key and a
 * per-display key, both opaque and both rotatable — NO authentication
 * middleware, ever, which the architecture test covering `public.tenant` routes
 * enforces (ADR-036, docs/17-QUEUE.md §26).
 *
 * Read-only. The page itself holds no queue data; it polls the public feed, so
 * a screen that loses its network recovers on the next successful poll (§49).
 */
Route::get('/q/{center}/{display}', QueueDisplayPageController::class)
    ->middleware(['public.tenant', 'locale', 'throttle:public-display'])
    ->name('queue.display');

/*
 * The customer's digital invoice.
 *
 * Resolved from the center's public key and a 256-bit share token — NO
 * authentication middleware, ever (ADR-036), and nothing a URL could enumerate.
 * Read-only and printable (docs/18-SALES.md §46).
 */
Route::get('/i/{center}/{token}', PublicInvoicePageController::class)
    ->middleware(['public.tenant', 'locale', 'throttle:public-invoice'])
    ->name('invoice.public');
