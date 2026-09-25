<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\PlatformAuditLog;
use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Tenancy\Actions\UpdateOwnCenterProfile;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Livewire\Center\Settings;
use App\Livewire\Center\Settings\BookingRules;
use App\Livewire\Center\Settings\General;
use App\Livewire\Center\Settings\Notifications;
use App\Livewire\Center\Settings\Policies;
use App\Modules\Booking\Application\Actions\UpdateBookingSettings;
use App\Modules\Booking\Domain\BookingSettings;
use App\Modules\Menu\Application\PublicPageAppearance;
use App\Modules\Notifications\Application\NotificationPreferences;
use App\Modules\Notifications\Domain\Data\Recipient;
use App\Modules\Notifications\Domain\Enums\RecipientKind;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Manager → Settings
|--------------------------------------------------------------------------
|
| Only genuinely center-level settings, each behind `settings.view` to read and
| `settings.manage` to change — checked in the Action. The center's profile is
| a control-plane row written from tenant context: only the bound center's own
| row, contact details stored canonical and audited as fingerprints, the
| currency locked once money has moved, the address out of reach.
|
*/

it('renders every section without raw keys in every interface language', function (string $locale, string $title): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug, $locale, $title): void {
        $this->actingAs($owner);

        foreach (Settings::TABS as $tab) {
            $html = (string) $this->get("http://{$slug}.localhost:8000/manager/settings?tab={$tab}&locale={$locale}")
                ->assertOk()
                ->assertSee('<h1>'.$title.'</h1>', false)
                ->getContent();

            expect(preg_match('/\b(manager_settings|manager_appearance|notifications_inbox)\.[a-z_]+/', strip_tags($html)))->toBe(0, $tab)
                ->and(str_contains($html, '>CKB<'))->toBeFalse();
        }
    });
})->with([
    'English' => ['en', 'Settings'],
    'Arabic' => ['ar', 'الإعدادات'],
    'Kurdish Sorani' => ['ckb', 'ڕێکخستنەکان'],
]);

it('lets the center edit its own profile, stores the phone canonical and audits contact details as fingerprints', function (): void {
    $center = $this->registerCenter('Profile Center', 'owner@profile.test');
    $owner = $this->ownerOf($center['tenant']);
    $slugBefore = TenantModel::query()->findOrFail($center['tenant']->id)->slug;

    $this->asCenter($center['tenant'], function () use ($owner): void {
        Livewire::actingAs($owner)->test(General::class)
            ->set('name', 'Profile Center Erbil')
            ->set('contactName', 'Dana Karim')
            ->set('contactEmail', 'Dana@Profile.test')
            ->set('phoneCountry', 'IQ')
            ->set('phoneNumber', '0750 111 2233')
            ->set('timezone', 'Asia/Baghdad')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('noticeTone', 'success');
    });

    $tenant = TenantModel::query()->findOrFail($center['tenant']->id);

    expect($tenant->name)->toBe('Profile Center Erbil')
        ->and($tenant->contact_email)->toBe('dana@profile.test')
        ->and($tenant->contact_phone)->toBe('+9647501112233')
        ->and($tenant->timezone)->toBe('Asia/Baghdad')
        // The address is the platform's to change.
        ->and($tenant->slug)->toBe($slugBefore);

    $tenantAudit = $this->asCenter($center['tenant'], fn () => TenantAuditLog::query()->where('action', 'center.profile.updated')->firstOrFail());
    $platformAudit = PlatformAuditLog::query()->where('action', 'center.profile.updated')->where('tenant_id', $center['tenant']->id)->firstOrFail();

    foreach ([$tenantAudit, $platformAudit] as $row) {
        $serialised = json_encode([$row->before, $row->after]);

        expect($serialised)->not->toContain('dana@profile.test')
            ->and($serialised)->not->toContain('9647501112233')
            ->and($row->after['contact_phone'])->toBeString()
            ->and($row->after['name'])->toBe('Profile Center Erbil');
    }
});

it('refuses a profile change without settings.manage, an invalid phone, and a currency change once money has moved', function (): void {
    $center = $this->registerCenter('Currency Center', 'owner@currency.test');

    $this->asCenter($center['tenant'], function () use ($center): void {
        $update = app(UpdateOwnCenterProfile::class);
        $owner = $this->ownerWithCatalogAccess();
        $viewer = $this->staffWith([Permission::SettingsView], 'viewer@currency.test');

        expect(fn () => $update($viewer, ['name' => 'Other']))->toThrow(AuthorizationException::class)
            ->and(fn () => $update($owner, ['contact_phone' => '0750 111 2233']))->toThrow(ValidationException::class)
            ->and(fn () => $update($owner, ['name' => '']))->toThrow(ValidationException::class)
            ->and(fn () => $update($owner, ['currency' => 'XXX']))->toThrow(ValidationException::class);

        // Before any money has moved the currency may change — and change back.
        expect($update->currencyLocked())->toBeFalse();
        expect($update($owner, ['currency' => 'USD'])->currency)->toBe('USD')
            ->and($update($owner, ['currency' => 'IQD'])->currency)->toBe('IQD');

        // A sale exists: the currency is locked, exactly as for the Super Admin.
        $seed = $this->seedBookableCenter();
        $this->openShift($seed['branch'], $owner);
        $sale = $this->draftSale($seed['branch'], $owner);
        app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);

        expect($update->currencyLocked())->toBeTrue()
            ->and(fn () => $update($owner, ['currency' => 'USD']))->toThrow(ValidationException::class, __('manager_settings.profile.errors.currency_locked'))
            ->and(TenantModel::query()->findOrFail($center['tenant']->id)->currency)->toBe('IQD');
    });
});

it('changes the booking rules within the engine bands, audited, and only with booking', function (): void {
    $center = $this->registerCenter('Rules Center', 'owner@rules.test');
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        Livewire::actingAs($owner)->test(BookingRules::class)
            ->set('rules.slot_interval_minutes', '30')
            ->set('rules.max_advance_days', '90')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('noticeTone', 'success');

        app(BookingSettings::class)->forget();
        expect(app(BookingSettings::class)->slotIntervalMinutes())->toBe(30)
            ->and(app(BookingSettings::class)->maxAdvanceDays())->toBe(90);

        $audit = TenantAuditLog::query()->where('action', 'settings.booking.updated')->firstOrFail();
        expect($audit->before)->toBe(['slot_interval_minutes' => 15, 'max_advance_days' => 60])
            ->and($audit->after)->toBe(['slot_interval_minutes' => 30, 'max_advance_days' => 90]);

        // Outside the band the engine would clamp to: refused, with the limits.
        Livewire::actingAs($owner)->test(BookingRules::class)
            ->set('rules.max_advance_days', '500')
            ->call('save')
            ->assertHasErrors('rules.max_advance_days');

        $update = app(UpdateBookingSettings::class);
        expect(fn () => $update($owner, ['slot_interval_minutes' => 2]))->toThrow(ValidationException::class)
            ->and(fn () => $update($owner, ['buffer_minutes' => 10]))->toThrow(ValidationException::class)
            ->and(fn () => $update($this->staffWith([Permission::SettingsView], 'rules-viewer@rules.test'), ['max_advance_days' => 10]))
            ->toThrow(AuthorizationException::class);

        $this->revokeEntitlement('booking');

        // A fresh Action, as the next request would get: the one above keeps
        // its own per-instance entitlement memo from before the revoke.
        $update = app(UpdateBookingSettings::class);
        expect(fn () => $update($this->ownerWithCatalogAccess(), ['max_advance_days' => 10]))->toThrow(EntitlementRequired::class);
    });
});

it('saves localized policies for the booking page and the person\'s own notification preferences', function (): void {
    $center = $this->registerCenter('Policy Center', 'owner@policy.test');
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        Livewire::actingAs($owner)->test(Policies::class)
            ->set('texts.cancellation.en', "Free cancellation up to 2 hours before.\nLate cancellations may be charged.")
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('noticeTone', 'success');

        expect(app(PublicPageAppearance::class)->get('policies')->text('cancellation', 'en'))->toContain('Late cancellations');

        Livewire::actingAs($owner)->test(Policies::class)
            ->set('texts.terms.en', '<a href="https://evil.test">click</a>')
            ->call('save')
            ->assertHasErrors('texts.terms.en');

        expect(TenantAuditLog::query()->where('action', 'settings.policies.updated')->count())->toBe(1);

        $me = new Recipient(RecipientKind::Staff, (int) $owner->getKey());
        expect(app(NotificationPreferences::class)->all($me)['appointment_reminders'] ?? true)->toBeTrue();

        Livewire::actingAs($owner)->test(Notifications::class)->call('toggle', 'appointment_reminders');
        expect(app(NotificationPreferences::class)->all($me)['appointment_reminders'])->toBeFalse();

        // Only the three switches that may be turned off exist.
        Livewire::actingAs($owner)->test(Notifications::class)->call('toggle', 'invoice_issued');
        expect(DB::connection('tenant')->table('notification_preferences')->count())->toBe(1);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
