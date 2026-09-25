<?php

declare(strict_types=1);

use App\Kernel\Audit\Actor;
use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Modules\SaasAdmin\Application\Actions\ManageCenterAccount;
use App\Modules\SaasAdmin\Application\CenterPeople;

/*
|--------------------------------------------------------------------------
| Super Admin actions on a center's people
|--------------------------------------------------------------------------
|
| The Super Admin reads and blocks center staff from the control plane,
| through the tenant context of the center it NAMED. A person's uuid from
| another center must resolve to nothing there, and nothing done for one
| center may touch another center's database.
|
*/

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});

it('lists and blocks people only inside the center it names', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');
    $alphaModel = TenantModel::query()->findOrFail($alpha['tenant']->id);
    $actor = Actor::system('isolation-test');

    $alphaStylist = $this->asCenter($alpha['tenant'], fn (): User => User::query()->create(['name' => 'Alpha Stylist', 'email' => 'stylist@alpha.test', 'is_active' => true]));
    $betaStylist = $this->asCenter($beta['tenant'], fn (): User => User::query()->create(['name' => 'Beta Stylist', 'email' => 'stylist@beta.test', 'is_active' => true]));

    $emails = array_column(app(CenterPeople::class)->list($alphaModel), 'email');
    expect($emails)->toContain('owner@alpha.test')
        ->and($emails)->toContain('stylist@alpha.test')
        ->and(in_array('owner@beta.test', $emails, true))->toBeFalse('Beta\'s owner is listed under Alpha')
        ->and(in_array('stylist@beta.test', $emails, true))->toBeFalse('Beta\'s staff are listed under Alpha');

    // Beta's person, named through Alpha, is simply not there.
    expect(fn () => app(ManageCenterAccount::class)->setActive($alphaModel, $betaStylist->uuid, false, $actor, 'Cross-center attempt'))
        ->toThrow(DomainException::class, __('sadmin_centers.errors.person_missing'));
    expect($this->asCenter($beta['tenant'], fn (): bool => (bool) User::query()->whereKey($betaStylist->id)->value('is_active')))->toBeTrue();

    // Alpha's own person is blocked in Alpha only; the owner never is.
    app(ManageCenterAccount::class)->setActive($alphaModel, $alphaStylist->uuid, false, $actor, 'Left the center');
    expect($this->asCenter($alpha['tenant'], fn (): bool => (bool) User::query()->whereKey($alphaStylist->id)->value('is_active')))->toBeFalse()
        ->and($this->asCenter($beta['tenant'], fn (): int => User::query()->where('is_active', true)->count()))->toBe(2);

    $alphaOwner = $this->ownerOf($alpha['tenant']);
    expect(fn () => app(ManageCenterAccount::class)->setActive($alphaModel, $alphaOwner->uuid, false, $actor, 'Not allowed'))
        ->toThrow(DomainException::class, __('sadmin_centers.errors.owner_block'));

    // The control plane is left without a bound tenant afterwards.
    expect(app(TenantContext::class)->isBound())->toBeFalse();
});
