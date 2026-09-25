<?php

declare(strict_types=1);

use App\Kernel\Audit\Actor;
use App\Kernel\Contact\PhoneCountries;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Identity\Models\User;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Livewire\Auth\RegisterCenter;
use App\Livewire\Sadmin\Centers\Create as CreateCenter;
use App\Modules\Employees\Application\Actions\CreateEmployee;
use App\Modules\Employees\Domain\Data\NewEmployee;
use App\Modules\Onboarding\Application\CreateCenterForPlatform;
use App\Modules\Onboarding\Application\RegistrationService;
use App\Modules\Onboarding\Domain\Exceptions\OwnerPhoneRequired;
use Database\Seeders\LocalDevelopmentPlatformUserSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Every center user account has a phone number
|--------------------------------------------------------------------------
|
| Owners, managers and staff: no center is registered or created without the
| owner's phone, and no staff login without one — enforced in the actions,
| not only in forms. Stored in E.164; Iraq (+964) is the default country, not
| a requirement. Legacy accounts without a phone are shown as such, never
| given an invented number.
|
*/

beforeEach(function (): void {
    $this->withoutVite();
    Mail::fake();
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});

it('normalises every spelling of a number to one E.164 value, per country', function (): void {
    foreach (['0750 123 4567', '7501234567', '+964 750 123 4567', '00964 750 123 4567', '964-750-123-4567'] as $typed) {
        expect(PhoneNumber::fromParts('IQ', $typed)?->e164)->toBe('+9647501234567', $typed);
    }
    expect(PhoneNumber::fromParts('TR', '0532 123 45 67')?->e164)->toBe('+905321234567')
        ->and(PhoneNumber::fromParts('IT', '06 1234 5678')?->e164)->toBe('+390612345678')
        ->and(PhoneNumber::fromParts('US', '(212) 555-0199')?->e164)->toBe('+12125550199')
        ->and(PhoneNumber::fromParts('IQ', '123'))->toBeNull()
        ->and(PhoneNumber::fromParts('IQ', '75012345678901'))->toBeNull()
        ->and(PhoneNumber::fromParts('ZZ', '7501234567'))->toBeNull();

    $phone = PhoneNumber::fromParts('IQ', '0750 123 4567');
    expect($phone?->country())->toBe('IQ')->and($phone?->national())->toBe('7501234567')
        ->and($phone?->international())->toBe('+964 7501234567')
        ->and(PhoneNumber::fromParts('US', '(212) 555-0199')?->international())->toBe('+1 2125550199');
    expect(PhoneCountries::DEFAULT)->toBe('IQ')->and(PhoneCountries::callingCode('IQ'))->toBe('964');
});

it('refuses a self-registration without the owner phone and carries the phone to the owner account', function (): void {
    expect(fn () => app(RegistrationService::class)->register([
        'center_name' => 'No Phone Salon', 'owner_name' => 'Owner', 'owner_email' => 'owner@nophone.test', 'owner_phone' => '',
        'password' => 'correct-horse-battery-staple', 'locale' => 'en', 'country' => 'IQ',
    ], 'test:no-phone'))->toThrow(OwnerPhoneRequired::class);

    $this->postJson('/api/v1/public/registrations', [
        'center_name' => 'Api Salon', 'center_slug' => 'api-salon', 'owner_name' => 'Owner', 'owner_email' => 'owner@api.test',
        'password' => 'correct-horse-battery-staple',
    ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['owner_phone']]]]);

    $center = $this->registerCenter('Phone Salon', 'owner@phone.test');
    expect($center['registration']->owner_phone)->toBe('+9647701234567');
    $this->asCenter($center['tenant'], function (): void {
        expect(User::query()->where('is_owner', true)->firstOrFail()->phone)->toBe('+9647701234567');
    });
});

it('asks for the owner phone on the registration form, Iraq by default', function (): void {
    Livewire::test(RegisterCenter::class)
        ->assertSet('phoneCountry', 'IQ')
        ->set('centerName', 'Form Salon')->set('centerSlug', 'form-salon')->set('ownerName', 'Owner')
        ->set('email', 'owner@form.test')->set('password', 'correct-horse-battery-staple')
        ->call('submit')
        ->assertHasErrors(['phone' => 'required']);

    Livewire::test(RegisterCenter::class)
        ->set('centerName', 'Form Salon')->set('centerSlug', 'form-salon')->set('ownerName', 'Owner')
        ->set('email', 'owner@form.test')->set('password', 'correct-horse-battery-staple')
        ->set('phone', '12')
        ->call('submit')
        ->assertHasErrors('phone');
});

it('requires the owner phone when a Super Admin creates a center', function (): void {
    app()->call([app(LocalDevelopmentPlatformUserSeeder::class), 'run']);
    $admin = PlatformUser::query()->where('email', 'admin@meta-style.local')->firstOrFail();
    $this->actingAs($admin, 'platform');

    Livewire::test(CreateCenter::class)
        ->assertSet('ownerPhoneCountry', 'IQ')
        ->set('ownerPhone', '')
        ->call('create')
        ->assertHasErrors(['ownerPhone' => 'required']);

    expect(fn () => app(CreateCenterForPlatform::class)([
        'center_name' => 'Platform Salon', 'slug' => 'platform-salon', 'owner_name' => 'Owner', 'owner_email' => 'owner@platform-salon.test',
        'owner_phone' => 'not a phone', 'primary_locale' => 'en', 'locales' => ['en'], 'currency' => 'IQD', 'timezone' => 'Asia/Baghdad',
        'plan_id' => 1, 'cycle' => 'monthly', 'trial' => true, 'trial_days' => null, 'starts_at' => null,
    ], Actor::platform($admin)))->toThrow(OwnerPhoneRequired::class);
});

it('refuses a staff login without a phone, but still allows staff with no login', function (): void {
    $center = $this->registerCenter('Staff Salon', 'owner@staff.test');

    $this->asCenter($center['tenant'], function (): void {
        $owner = User::query()->where('is_owner', true)->firstOrFail();

        expect(fn () => app(CreateEmployee::class)(new NewEmployee(name: ['en' => 'No Phone'], email: 'nophone@staff.test'), $owner))
            ->toThrow(ValidationException::class);
        expect(User::query()->where('email', 'nophone@staff.test')->exists())->toBeFalse();

        $listed = app(CreateEmployee::class)(new NewEmployee(name: ['en' => 'Listed only']), $owner);
        expect($listed['user'])->toBeNull();

        $withLogin = app(CreateEmployee::class)(new NewEmployee(name: ['en' => 'Sara'], email: 'sara@staff.test', phone: '+9647701239999'), $owner);
        expect($withLogin['user']?->phone)->toBe('+9647701239999');

        // The same number cannot be given to a second account in the center.
        expect(fn () => app(CreateEmployee::class)(new NewEmployee(name: ['en' => 'Twin'], phone: '+9647701239999'), $owner))
            ->toThrow(ValidationException::class);
    });
});
