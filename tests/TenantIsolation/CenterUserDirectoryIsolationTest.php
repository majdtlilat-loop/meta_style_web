<?php

declare(strict_types=1);

use App\Kernel\Audit\Actor;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Identity\Models\User;
use App\Kernel\Platform\Directory\CenterUserDirectory;
use App\Kernel\Platform\Directory\CenterUserEntry;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Modules\SaasAdmin\Application\Actions\UpdateCenterUserIdentity;
use Database\Seeders\LocalDevelopmentPlatformUserSeeder;
use Illuminate\Support\Facades\Mail;

/*
| The Center Users directory reads each center inside that center's own
| context. Projecting one center never writes another center's rows, and a
| platform correction to one center's account never touches the account of
| another center that happens to share the same email.
*/

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});

it('projects and corrects each center only in its own context', function (): void {
    Mail::fake();
    app()->call([app(LocalDevelopmentPlatformUserSeeder::class), 'run']);
    $actor = Actor::platform(PlatformUser::query()->where('email', 'admin@meta-style.local')->firstOrFail());

    $a = $this->registerCenter('Isolation A', 'same-owner@example.test');
    $b = $this->registerCenter('Isolation B', 'same-owner@example.test', idempotencyKey: 'test:isolation-b');
    $tenantA = TenantModel::query()->findOrFail($a['tenant']->id);
    $tenantB = TenantModel::query()->findOrFail($b['tenant']->id);

    // Provisioning projected both centers already; start from an empty copy.
    CenterUserEntry::query()->delete();
    app(CenterUserDirectory::class)->refreshTenant($tenantA);
    expect(CenterUserEntry::query()->pluck('tenant_id')->unique()->all())->toBe([$tenantA->id]);

    app(CenterUserDirectory::class)->refreshTenant($tenantB);
    $bRowBefore = CenterUserEntry::query()->where('tenant_id', $tenantB->id)->firstOrFail()->toArray();

    $ownerA = CenterUserEntry::query()->where('tenant_id', $tenantA->id)->firstOrFail();
    app(UpdateCenterUserIdentity::class)($tenantA, $ownerA->user_uuid, 'Owner A Renamed', 'same-owner@example.test', PhoneNumber::fromParts('IQ', '0770 111 2233') ?? throw new RuntimeException, $actor);

    $this->asCenter($b['tenant'], function (): void {
        $owner = User::query()->where('is_owner', true)->firstOrFail();
        expect($owner->name)->not->toBe('Owner A Renamed')
            ->and($owner->phone)->toBe('+9647701234567');
    });
    expect(CenterUserEntry::query()->where('tenant_id', $tenantB->id)->firstOrFail()->only(['name', 'phone_e164', 'user_uuid']))
        ->toEqual(array_intersect_key($bRowBefore, array_flip(['name', 'phone_e164', 'user_uuid'])))
        ->and(CenterUserEntry::query()->where('tenant_id', $tenantA->id)->value('name'))->toBe('Owner A Renamed');

    // A user id that belongs to B cannot be edited through A.
    $ownerB = CenterUserEntry::query()->where('tenant_id', $tenantB->id)->firstOrFail();
    expect(fn () => app(UpdateCenterUserIdentity::class)($tenantA, $ownerB->user_uuid, 'Crossed', null, PhoneNumber::fromParts('IQ', '0770 444 5566') ?? throw new RuntimeException, $actor))
        ->toThrow(DomainException::class);
});
