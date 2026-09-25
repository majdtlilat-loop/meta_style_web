<?php

declare(strict_types=1);

use App\Http\Controllers\LandingPagePreviewController;
use App\Http\Controllers\SaasBillingDocumentController;
use App\Http\Controllers\SupportAttachmentController;
use App\Kernel\Tenancy\PlatformHosts;
use App\Livewire\Sadmin\Account\Index as Account;
use App\Livewire\Sadmin\Alerts\Index as Alerts;
use App\Livewire\Sadmin\Announcements\Index as Announcements;
use App\Livewire\Sadmin\Audit\Index as Audit;
use App\Livewire\Sadmin\Auth\ForgotPassword;
use App\Livewire\Sadmin\Auth\Login;
use App\Livewire\Sadmin\Auth\MfaChallenge;
use App\Livewire\Sadmin\Auth\MfaSetup;
use App\Livewire\Sadmin\Auth\ResetPassword;
use App\Livewire\Sadmin\Billing\Index as Billing;
use App\Livewire\Sadmin\Centers\Create as CreateCenter;
use App\Livewire\Sadmin\Centers\Index as Centers;
use App\Livewire\Sadmin\Centers\Show as CenterDetail;
use App\Livewire\Sadmin\CenterUsers\Index as CenterUsers;
use App\Livewire\Sadmin\Cms\Index as Cms;
use App\Livewire\Sadmin\Currencies\Index as Currencies;
use App\Livewire\Sadmin\Dashboard\Index as Dashboard;
use App\Livewire\Sadmin\Entitlements\Index as Entitlements;
use App\Livewire\Sadmin\Operations\Index as Operations;
use App\Livewire\Sadmin\Plans\Index as Plans;
use App\Livewire\Sadmin\Roles\Index as Roles;
use App\Livewire\Sadmin\Settings\Ai as SettingsAi;
use App\Livewire\Sadmin\Settings\Branding as SettingsBranding;
use App\Livewire\Sadmin\Settings\Index as Settings;
use App\Livewire\Sadmin\Settings\InvoiceTemplate as SettingsInvoices;
use App\Livewire\Sadmin\Subscriptions\Index as Subscriptions;
use App\Livewire\Sadmin\Support\Index as Support;
use App\Livewire\Sadmin\Support\Show as SupportTicket;
use App\Livewire\Sadmin\Usage\Index as Usage;
use App\Livewire\Sadmin\Users\Index as Users;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

$platformHosts = app(PlatformHosts::class);

Route::domain($platformHosts->superAdminHost())->name('superadmin.')->group(function (): void {
    Route::middleware(['guest:platform', 'locale'])->group(function (): void {
        Route::get('/login', Login::class)->name('login');
        Route::get('/password/forgot', ForgotPassword::class)->middleware('throttle:login')->name('password.request');
        Route::get('/password/reset/{token}', ResetPassword::class)->middleware('throttle:login')->name('password.reset');
    });

    /*
     * NOT guest-only. A "remember me" cookie signs a Super Admin back in to a
     * fresh session that has never passed MFA, and EnsurePlatformMfa sends
     * them here; under `guest` they bounced back to the dashboard forever.
     * The components admit only the pending user or that remembered,
     * not-yet-verified user, and both still need a valid code.
     */
    Route::middleware(['locale'])->group(function (): void {
        Route::get('/mfa/challenge', MfaChallenge::class)->name('mfa.challenge');
        Route::get('/mfa/setup', MfaSetup::class)->name('mfa.setup');
    });

    Route::post('/logout', function () {
        Auth::guard('platform')->logout();
        session()->invalidate();
        session()->regenerateToken();

        return redirect()->route('superadmin.login');
    })->middleware('auth:platform')->name('logout');

    Route::middleware(['auth:platform', 'platform.mfa', 'locale'])->group(function (): void {
        Route::get('/', Dashboard::class)->middleware('platform.permission:platform.dashboard.view')->name('dashboard');
        Route::get('/centers', Centers::class)->middleware('platform.permission:platform.center.view')->name('centers.index');
        Route::get('/centers/new', CreateCenter::class)->middleware('platform.permission:platform.center.manage')->name('centers.create');
        Route::get('/center-users', CenterUsers::class)->middleware('platform.permission:platform.center_user.view')->name('center-users.index');
        Route::get('/centers/{tenant}', CenterDetail::class)->middleware('platform.permission:platform.center.view')->name('centers.show');
        Route::get('/plans', Plans::class)->middleware('platform.permission:platform.plan.manage')->name('plans.index');
        Route::get('/subscriptions', Subscriptions::class)->middleware('platform.permission:platform.subscription.manage')->name('subscriptions.index');
        Route::get('/billing', Billing::class)->middleware('platform.permission:platform.billing.manage')->name('billing.index');
        Route::middleware('platform.permission:platform.billing.manage')->group(function (): void {
            Route::get('/billing/invoices/{invoice}/print', [SaasBillingDocumentController::class, 'invoice'])->name('billing.invoice.print');
            Route::get('/billing/invoices/{invoice}/pdf', [SaasBillingDocumentController::class, 'invoicePdf'])->name('billing.invoice.pdf');
            Route::get('/centers/{tenant}/statement/print', [SaasBillingDocumentController::class, 'statement'])->name('centers.statement.print');
            Route::get('/centers/{tenant}/statement/pdf', [SaasBillingDocumentController::class, 'statementPdf'])->name('centers.statement.pdf');
        });
        Route::get('/usage', Usage::class)->middleware('platform.permission:platform.usage.manage')->name('usage.index');
        Route::get('/support', Support::class)->middleware('platform.permission:platform.support.view')->name('support.index');
        Route::get('/support/{ticket}', SupportTicket::class)->middleware('platform.permission:platform.support.view')->name('support.show');
        Route::get('/support/{ticket}/attachments/{attachment}', SupportAttachmentController::class)->middleware('platform.permission:platform.support.view')->name('support.attachment');
        Route::get('/operations', Operations::class)->middleware('platform.permission:platform.operations.view')->name('operations.index');
        Route::get('/cms', Cms::class)->middleware('platform.permission:platform.cms.manage')->name('cms.index');
        Route::get('/cms/preview', LandingPagePreviewController::class)->middleware('platform.permission:platform.cms.manage')->name('cms.preview');
        Route::get('/audit', Audit::class)->middleware('platform.permission:platform.audit.view')->name('audit.index');
        Route::get('/alerts', Alerts::class)->name('alerts.index');
        Route::get('/settings', Settings::class)->middleware('platform.permission:platform.settings.manage')->name('settings.index');
        Route::get('/settings/branding', SettingsBranding::class)->middleware('platform.permission:platform.branding.manage')->name('settings.branding');
        Route::get('/settings/ai', SettingsAi::class)->middleware('platform.permission:platform.ai.manage')->name('settings.ai');
        Route::get('/settings/invoices', SettingsInvoices::class)->middleware('platform.permission:platform.billing.manage')->name('settings.invoices');
        Route::get('/currencies', Currencies::class)->middleware('platform.permission:platform.settings.manage')->name('currencies.index');
        Route::get('/entitlements', Entitlements::class)->middleware('platform.permission:platform.entitlement.manage')->name('entitlements.index');
        Route::get('/announcements', Announcements::class)->middleware('platform.permission:platform.announcement.send')->name('announcements.index');
        Route::get('/users', Users::class)->middleware('platform.permission:platform.user.manage')->name('users.index');
        Route::get('/roles', Roles::class)->middleware('platform.permission:platform.user.manage')->name('roles.index');
        Route::get('/account', Account::class)->name('account');
    });
});
