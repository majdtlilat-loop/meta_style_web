<?php

declare(strict_types=1);

use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\PlatformHosts;
use Illuminate\Support\Facades\Route;

it('binds every web surface to the APP_URL-derived host and port', function (): void {
    $hosts = app(PlatformHosts::class);

    expect(config('app.url'))->toBe('http://localhost:8000')
        ->and(Route::getRoutes()->getByName('home')?->getDomain())->toBe('localhost')
        ->and(Route::getRoutes()->getByName('superadmin.login')?->getDomain())->toBe('superadmin.localhost')
        ->and(Route::getRoutes()->getByName('login')?->getDomain())->toBe('{center}.localhost')
        ->and(route('home'))->toBe('http://localhost:8000')
        ->and(route('superadmin.login'))->toBe('http://superadmin.localhost:8000/login')
        ->and(route('login', ['center' => 'prestige']))->toBe('http://prestige.localhost:8000/login')
        ->and($hosts->centerUrl('prestige', '/list'))->toBe('http://prestige.localhost:8000/list')
        ->and($hosts->centerUrl('prestige', '/booking'))->toBe('http://prestige.localhost:8000/booking')
        ->and(config('tenancy.central_domains'))->toBe(['localhost', 'superadmin.localhost']);
});

it('keeps corporate and Super Admin requests in the control plane', function (): void {
    $this->withoutVite();
    $context = app(TenantContext::class);

    $this->get('http://localhost:8000/')->assertOk();
    expect($context->isBound())->toBeFalse();

    $this->get('http://superadmin.localhost:8000/login')->assertOk();
    expect($context->isBound())->toBeFalse();
});

it('fails closed for an unregistered center subdomain', function (): void {
    $this->get('http://unregistered.localhost:8000/list')->assertNotFound();

    expect(app(TenantContext::class)->isBound())->toBeFalse();
});
