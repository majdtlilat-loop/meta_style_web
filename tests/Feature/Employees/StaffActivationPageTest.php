<?php

declare(strict_types=1);

use App\Kernel\Identity\Models\StaffActivationToken;
use App\Kernel\Identity\Models\User;
use App\Livewire\Auth\ActivateAccount;
use App\Modules\Employees\Application\Actions\CreateEmployee;
use App\Modules\Employees\Domain\Data\NewEmployee;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Staff activation — the page a new team member opens
|--------------------------------------------------------------------------
|
| Before this page existed a staff login could never be activated in
| production: `ManageStaffActivation::redeem()` had no caller outside tests.
| The link is the credential; it works once, and it is never echoed back.
|
*/

it('activates a new staff login once from the guest page and never signs anyone in', function (): void {
    $center = $this->registerCenter();
    $slug = $center['registration']->requested_slug;
    URL::defaults(['center' => $slug]);

    $token = $this->asCenter($center['tenant'], function (): string {
        $owner = User::query()->where('is_owner', true)->firstOrFail();

        return (string) app(CreateEmployee::class)(
            new NewEmployee(name: ['en' => 'Sara Karim'], email: 'sara@alpha.test', phone: '+9647701230201'),
            $owner,
        )['activation_token'];
    });

    // The guest page renders in the center's host with a greeting, a
    // password form, and nothing that repeats the token.
    $html = $this->get("http://{$slug}.localhost:8000/activate/{$token}")
        ->assertOk()
        ->assertSee(__('manager_staff.activation.welcome', ['name' => 'Sara']))
        ->assertSee(__('manager_staff.activation.submit'))
        ->getContent();

    // The link is the credential: it is never printed back as page text.
    expect(str_contains(strip_tags((string) $html), $token))->toBeFalse();

    $this->asCenter($center['tenant'], function () use ($token): void {
        Livewire::test(ActivateAccount::class, ['token' => $token])
            ->set('password', 'short')
            ->set('passwordConfirmation', 'short')
            ->call('submit')
            ->assertHasErrors('password');

        expect(User::query()->where('email', 'sara@alpha.test')->firstOrFail()->password)->toBeNull();

        Livewire::test(ActivateAccount::class, ['token' => $token])
            ->set('password', 'a-brand-new-password-42')
            ->set('passwordConfirmation', 'a-brand-new-password-42')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('login'));

        $user = User::query()->where('email', 'sara@alpha.test')->firstOrFail();

        expect(Hash::check('a-brand-new-password-42', (string) $user->password))->toBeTrue()
            ->and($user->canAuthenticate())->toBeTrue()
            ->and(auth('web')->check())->toBeFalse()
            ->and(StaffActivationToken::query()->usable()->count())->toBe(0)
            // Never flashed or stored in the session.
            ->and(collect(session()->all())->flatten()->contains($token))->toBeFalse();

        // Single use: the same link now answers "no longer valid".
        Livewire::test(ActivateAccount::class, ['token' => $token])
            ->assertSee(__('manager_staff.activation.invalid'))
            ->set('password', 'another-password-77')
            ->set('passwordConfirmation', 'another-password-77')
            ->call('submit')
            ->assertHasErrors('password');

        expect(Hash::check('a-brand-new-password-42', (string) $user->refresh()->password))->toBeTrue();
    });

    // An unknown token is the same generic answer, not a 404 that confirms
    // anything about the link space.
    $this->get("http://{$slug}.localhost:8000/activate/".str_repeat('x', 48))
        ->assertOk()
        ->assertSee(__('manager_staff.activation.invalid'));
});

it('guards the activation route: guest only, throttled, a link-shaped token only', function (): void {
    $route = Route::getRoutes()->getByName('activate');

    expect($route)->not->toBeNull()
        ->and($route?->gatherMiddleware())->toContain('guest:web')
        ->and($route?->gatherMiddleware())->toContain('throttle:login')
        ->and($route?->gatherMiddleware())->toContain('tenant')
        ->and($route?->wheres['token'] ?? null)->toBe('[A-Za-z0-9]{20,128}');
});

it('refuses an activation link for an account that was deactivated meanwhile', function (): void {
    $center = $this->registerCenter();
    $slug = $center['registration']->requested_slug;
    URL::defaults(['center' => $slug]);

    $this->asCenter($center['tenant'], function (): void {
        $owner = User::query()->where('is_owner', true)->firstOrFail();
        $created = app(CreateEmployee::class)(new NewEmployee(name: ['en' => 'Omar'], phone: '+9647701230202'), $owner);
        $user = $created['user'] ?? throw new RuntimeException;

        // Disabled without going through the Action (which would also revoke
        // the link): the redemption itself must still refuse.
        $user->forceFill(['is_active' => false])->save();

        Livewire::test(ActivateAccount::class, ['token' => (string) $created['activation_token']])
            ->assertSee(__('manager_staff.activation.invalid'))
            ->set('password', 'a-brand-new-password-42')
            ->set('passwordConfirmation', 'a-brand-new-password-42')
            ->call('submit')
            ->assertHasErrors('password');

        expect($user->refresh()->password)->toBeNull();
    });

});

afterEach(function (): void {
    URL::defaults([]);
    $this->tearDownRegisteredCenters();
});
