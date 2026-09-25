<?php

declare(strict_types=1);

use App\Kernel\Platform\Identity\Http\Middleware\EnsurePlatformMfa;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Kernel\Tenancy\Infrastructure\DomainModel;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use Database\Seeders\LocalDevelopmentPlatformUserSeeder;

beforeEach(function (): void {
    $this->withoutVite();
    app()->call([app(LocalDevelopmentPlatformUserSeeder::class), 'run']);
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});

it('uses only authoritative registered domains and safely renders incomplete legacy centers', function (): void {
    $result = $this->registerCenter('Prestige Center', 'owner@prestige.test');
    $tenant = TenantModel::query()->with('domains')->findOrFail($result['tenant']->id);
    $user = PlatformUser::query()->where('email', 'admin@meta-style.local')->firstOrFail();
    $user->forceFill(['mfa_confirmed_at' => now()])->save();

    $this->actingAs($user, 'platform')
        ->withSession([EnsurePlatformMfa::SESSION_KEY => now()->timestamp])
        ->get('http://superadmin.localhost:8000/centers/'.$tenant->id)
        ->assertOk()
        ->assertSee('http://prestige-center.localhost:8000')
        ->assertSee('http://prestige-center.localhost:8000/login')
        ->assertSee('http://prestige-center.localhost:8000/list')
        ->assertSee('http://prestige-center.localhost:8000/booking');

    $tenant->domains()->delete();
    $tenant->forceFill(['slug' => null])->save();

    $this->get('http://superadmin.localhost:8000/centers/'.$tenant->id)
        ->assertOk()
        ->assertSee('Public URLs are not configured')
        ->assertSee('No domain is registered for this center.')
        ->assertDontSee($tenant->id.'.localhost');

    DomainModel::query()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'superadmin.localhost',
        'is_primary' => true,
    ]);

    $this->get('http://superadmin.localhost:8000/centers/'.$tenant->id)
        ->assertOk()
        ->assertSee('Public URLs are not configured')
        ->assertSee('The registered domain is invalid, outside the configured base host, or uses a reserved platform name.')
        ->assertSee('superadmin.localhost')
        ->assertDontSee('http://superadmin.localhost:8000/login');
});
