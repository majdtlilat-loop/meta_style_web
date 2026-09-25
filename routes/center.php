<?php

declare(strict_types=1);

use App\Http\Controllers\AdvancedReportExportController;
use App\Http\Controllers\CenterCommercePageController;
use App\Http\Controllers\CenterMediaController;
use App\Http\Controllers\CenterPublicLandingController;
use App\Http\Controllers\CenterSitePreviewController;
use App\Http\Controllers\InvoicePrintController;
use App\Http\Controllers\ManagerAppearancePreviewController;
use App\Http\Controllers\ManagerSupportAttachmentController;
use App\Http\Controllers\PublicBookingPageController;
use App\Http\Controllers\PublicInvoicePageController;
use App\Http\Controllers\PublicMenuPageController;
use App\Http\Controllers\PublicReviewPageController;
use App\Http\Controllers\QueueDisplayPageController;
use App\Http\Controllers\QueueDisplayPreviewController;
use App\Http\Controllers\QueueTicketPrintController;
use App\Http\Controllers\ReportExportController;
use App\Kernel\Tenancy\Infrastructure\StanclTenantResolver;
use App\Kernel\Tenancy\PlatformHosts;
use App\Livewire\Auth\ActivateAccount;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\Center\AdvancedReports;
use App\Livewire\Center\Appearance\BookingAppearance;
use App\Livewire\Center\Appearance\Brand;
use App\Livewire\Center\Appearance\CartAppearance;
use App\Livewire\Center\Appearance\PrintAppearance;
use App\Livewire\Center\Appearance\Site;
use App\Livewire\Center\Branches;
use App\Livewire\Center\Calendar;
use App\Livewire\Center\CashierShifts;
use App\Livewire\Center\Catalog;
use App\Livewire\Center\Conversations;
use App\Livewire\Center\Customers;
use App\Livewire\Center\Customers\Profile;
use App\Livewire\Center\Dashboard;
use App\Livewire\Center\Expenses;
use App\Livewire\Center\FinanceOverview;
use App\Livewire\Center\Integrations\WhatsApp;
use App\Livewire\Center\JourneyBoard;
use App\Livewire\Center\Loyalty;
use App\Livewire\Center\MembershipPlans;
use App\Livewire\Center\MenuDesigner;
use App\Livewire\Center\NotificationInbox;
use App\Livewire\Center\PackageDefinitions;
use App\Livewire\Center\PaymentGateways;
use App\Livewire\Center\Plan;
use App\Livewire\Center\PointOfSale;
use App\Livewire\Center\PosSettings;
use App\Livewire\Center\QueueBoard;
use App\Livewire\Center\Receipts;
use App\Livewire\Center\Reports;
use App\Livewire\Center\Resources;
use App\Livewire\Center\Reviews;
use App\Livewire\Center\Roles;
use App\Livewire\Center\Sales;
use App\Livewire\Center\Settings;
use App\Livewire\Center\Staff;
use App\Livewire\Center\Usage;
use App\Livewire\Manager\Support\Index as PlatformSupport;
use App\Livewire\Manager\Support\Ticket;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

$centerDomain = '{center}.'.app(PlatformHosts::class)->baseDomain();

Route::domain($centerDomain)->middleware(['public.tenant', 'locale'])->group(function (): void {
    Route::get('/', CenterPublicLandingController::class)->middleware('throttle:public-menu')->name('center.public');
    Route::get('/list', PublicMenuPageController::class)->middleware('throttle:public-menu')->name('menu.public');
    Route::get('/booking', [PublicBookingPageController::class, 'show'])->middleware('throttle:public-menu')->name('menu.book');
    Route::post('/booking', [PublicBookingPageController::class, 'store'])->middleware('throttle:public-booking')->name('menu.book.store');
    Route::get('/cart', [CenterCommercePageController::class, 'cart'])->middleware('throttle:public-menu')->name('center.cart');
    Route::get('/checkout', [CenterCommercePageController::class, 'checkout'])->middleware('throttle:public-menu')->name('center.checkout');
    Route::get('/q/{display}', QueueDisplayPageController::class)->middleware('throttle:public-display')->name('queue.display');
    Route::get('/i/{token}', PublicInvoicePageController::class)->middleware('throttle:public-invoice')->name('invoice.public');
    Route::post('/i/{token}/pay', [PublicInvoicePageController::class, 'pay'])->middleware('throttle:public-payment')->name('invoice.public.pay');
    Route::get('/r/{token}', PublicReviewPageController::class)->middleware('throttle:public-review')->name('review.public');
    Route::get('/media/{path}', CenterMediaController::class)->where('path', CenterMediaController::PATTERN)->middleware('throttle:public-media')->name('center.media');
    Route::post('/r/{token}', [PublicReviewPageController::class, 'submit'])->middleware('throttle:public-review')->name('review.public.submit');
});

Route::domain($centerDomain)->middleware(['tenant', 'locale'])->group(function (): void {
    Route::get('/login', Login::class)->middleware('guest:web')->name('login');
    Route::get('/forgot-password', ForgotPassword::class)->middleware(['guest:web', 'throttle:login'])->name('password.request');
    Route::get('/reset-password/{token}', ResetPassword::class)->middleware(['guest:web', 'throttle:login'])->name('password.reset');

    // [area:staff:guest] — staff account activation (guest).
    Route::get('/activate/{token}', ActivateAccount::class)
        ->middleware(['guest:web', 'throttle:login'])
        ->where('token', '[A-Za-z0-9]{20,128}')
        ->name('activate');
    // [/area:staff:guest]

    Route::post('/logout', function () {
        Auth::guard('web')->logout();
        session()->forget(StanclTenantResolver::SESSION_KEY);
        session()->invalidate();
        session()->regenerateToken();

        return redirect()->route('login', ['center' => request()->route('center')]);
    })->middleware('auth:web')->name('logout');

    Route::middleware('auth:web')->prefix('manager')->name('center.')->group(function (): void {
        Route::get('/', Dashboard::class)->name('dashboard');
        Route::get('/staff', Staff::class)->name('staff');
        Route::get('/roles', Roles::class)->name('roles');
        Route::get('/branches', Branches::class)->name('branches');
        Route::get('/catalog', Catalog::class)->name('catalog');
        Route::get('/menu', MenuDesigner::class)->name('menu');
        Route::get('/customers', Customers::class)->name('customers');
        Route::get('/calendar', Calendar::class)->name('calendar');
        Route::get('/resources', Resources::class)->name('resources');
        Route::get('/board', JourneyBoard::class)->name('board');
        Route::get('/queue', QueueBoard::class)->name('queue');
        Route::get('/queue/tickets/{uuid}/print', QueueTicketPrintController::class)->name('queue.ticket');
        Route::get('/pos', PointOfSale::class)->name('pos');
        Route::get('/sales', Sales::class)->name('sales');
        Route::get('/sales/invoices/{uuid}/print/{format}', InvoicePrintController::class)->where('format', '80mm|a4')->name('sales.invoice.print');
        Route::get('/payments/gateways', PaymentGateways::class)->name('payment_gateways');
        Route::get('/finance', FinanceOverview::class)->name('finance');
        Route::get('/finance/expenses', Expenses::class)->name('expenses');
        Route::get('/loyalty', Loyalty::class)->name('loyalty');
        Route::get('/memberships', MembershipPlans::class)->name('memberships');
        Route::get('/packages', PackageDefinitions::class)->name('packages');
        Route::get('/reviews', Reviews::class)->name('reviews');
        Route::get('/notifications', NotificationInbox::class)->name('notifications');
        Route::get('/conversations', Conversations::class)->name('conversations');
        Route::get('/usage', Usage::class)->name('usage');
        Route::get('/support', PlatformSupport::class)->name('support');
        Route::get('/settings', Settings::class)->name('settings');
        Route::get('/reports/{report?}', Reports::class)->name('reports');
        Route::get('/reports/{report}/export.csv', ReportExportController::class)->name('reports.export');
        Route::get('/advanced-reports/{report?}', AdvancedReports::class)->name('advanced-reports');
        Route::get('/advanced-reports/{report}/export.csv', AdvancedReportExportController::class)->name('advanced-reports.export');

        // Each block belongs to one Manager area. Add routes for that area
        // inside its own block only.
        // [area:shell] — plan & subscription (center.plan), one support ticket.
        Route::get('/plan', Plan::class)->name('plan');
        Route::get('/support/{ticket}', Ticket::class)->whereUuid('ticket')->name('support.show');
        Route::get('/support/{ticket}/attachments/{attachment}', ManagerSupportAttachmentController::class)->whereUuid(['ticket', 'attachment'])->name('support.attachment');
        // [/area:shell]
        // [area:dashboard-reports]
        // [/area:dashboard-reports]
        // [area:booking]
        // [/area:booking]
        // [area:queue-journey]
        // The screen preview renders the real television page and feed for a
        // signed-in manager only — never under public.tenant (ADR-036).
        Route::get('/queue/displays/{uuid}/preview', [QueueDisplayPreviewController::class, 'page'])->whereUuid('uuid')->name('queue.displays.preview');
        Route::get('/queue/displays/{uuid}/preview/feed', [QueueDisplayPreviewController::class, 'feed'])->whereUuid('uuid')->name('queue.displays.preview.feed');
        // [/area:queue-journey]
        // [area:customers]
        Route::get('/customers/{uuid}', Profile::class)->whereUuid('uuid')->name('customers.show');
        // [/area:customers]
        // [area:staff]
        // [/area:staff]
        // [area:pos-finance]
        Route::get('/pos/settings', PosSettings::class)->name('pos.settings');
        // Named `center.payments`: the shell's navigation lists the payments
        // page under that name. The path avoids money words on purpose — no
        // new URL may look like a money endpoint (SalesSurfaceTest).
        Route::get('/finance/receipts', Receipts::class)->name('payments');
        Route::get('/finance/shifts', CashierShifts::class)->name('shifts');
        // [/area:pos-finance]
        // [area:catalog]
        // [/area:catalog]
        // [area:site-builder] — center.appearance.brand, center.appearance.site, center.appearance.site.preview.
        Route::get('/appearance/brand', Brand::class)->name('appearance.brand');
        Route::get('/appearance/site', Site::class)->name('appearance.site');
        // The draft preview stays behind staff sign-in, never on the public
        // resolver (ADR-036); the controller also requires appearance.view.
        Route::get('/appearance/site/preview', CenterSitePreviewController::class)->name('appearance.site.preview');
        // [/area:site-builder]
        // [area:appearance-settings] — center.appearance.booking, center.appearance.cart, center.appearance.print.
        // Previews render the real guest templates for a signed-in owner only
        // (never under public.tenant — ADR-036); `center.menu` stays /manager/menu.
        Route::get('/appearance/menu/preview', [ManagerAppearancePreviewController::class, 'menu'])->name('appearance.menu.preview');
        Route::get('/appearance/booking', BookingAppearance::class)->name('appearance.booking');
        Route::get('/appearance/booking/preview', [ManagerAppearancePreviewController::class, 'booking'])->name('appearance.booking.preview');
        Route::get('/appearance/cart', CartAppearance::class)->name('appearance.cart');
        Route::get('/appearance/cart/preview', [ManagerAppearancePreviewController::class, 'cart'])->name('appearance.cart.preview');
        Route::get('/appearance/print', PrintAppearance::class)->name('appearance.print');
        // [/area:appearance-settings]
        // [area:integrations] — center.integrations.whatsapp (/manager/settings/whatsapp).
        Route::get('/settings/whatsapp', WhatsApp::class)->name('integrations.whatsapp');
        // [/area:integrations]
    });
});
