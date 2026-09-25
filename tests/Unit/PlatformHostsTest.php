<?php

declare(strict_types=1);

use App\Kernel\Tenancy\PlatformHosts;
use Illuminate\Config\Repository;

function platformHostsFor(string $appUrl): PlatformHosts
{
    return new PlatformHosts(new Repository([
        'app' => ['url' => $appUrl],
        'metastyle' => ['domains' => [
            'reserved' => ['superadmin', 'www', 'api', 'admin', 'mail', 'assets'],
        ]],
    ]));
}

it('derives every local origin from APP_URL and preserves its port', function (): void {
    $hosts = platformHostsFor('http://localhost:8000');

    expect($hosts->scheme())->toBe('http')
        ->and($hosts->baseDomain())->toBe('localhost')
        ->and($hosts->port())->toBe(8000)
        ->and($hosts->corporateUrl())->toBe('http://localhost:8000')
        ->and($hosts->superAdminUrl())->toBe('http://superadmin.localhost:8000')
        ->and($hosts->centerUrl('prestige'))->toBe('http://prestige.localhost:8000')
        ->and($hosts->centerUrl('prestige', '/login'))->toBe('http://prestige.localhost:8000/login')
        ->and($hosts->centerUrl('prestige', '/list'))->toBe('http://prestige.localhost:8000/list')
        ->and($hosts->centerUrl('prestige', '/booking'))->toBe('http://prestige.localhost:8000/booking');
});

it('derives the locked production subdomain layout from APP_URL', function (): void {
    $hosts = platformHostsFor('https://meta-style.net');

    expect($hosts->corporateUrl())->toBe('https://meta-style.net')
        ->and($hosts->superAdminUrl())->toBe('https://superadmin.meta-style.net')
        ->and($hosts->centerUrl('prestige'))->toBe('https://prestige.meta-style.net')
        ->and($hosts->centerUrl('prestige', '/login'))->toBe('https://prestige.meta-style.net/login')
        ->and($hosts->centerUrl('prestige', '/list'))->toBe('https://prestige.meta-style.net/list')
        ->and($hosts->centerUrl('prestige', '/booking'))->toBe('https://prestige.meta-style.net/booking');
});

it('rejects reserved infrastructure labels as center slugs', function (string $slug): void {
    expect(platformHostsFor('http://localhost:8000')->isValidCenterSlug($slug))->toBeFalse();
})->with(['superadmin', 'www', 'api', 'admin', 'mail', 'assets']);

it('extracts only one valid center label below the configured base host', function (): void {
    $hosts = platformHostsFor('http://localhost:8000');

    expect($hosts->centerSlugFromHost('prestige.localhost:8000'))->toBe('prestige')
        ->and($hosts->centerSlugFromHost('superadmin.localhost'))->toBeNull()
        ->and($hosts->centerSlugFromHost('nested.prestige.localhost'))->toBeNull()
        ->and($hosts->centerSlugFromHost('prestige.example.com'))->toBeNull();
});

it('refuses an APP_URL that is not a bare HTTP origin', function (string $url): void {
    platformHostsFor($url)->baseDomain();
})->with([
    '',
    'localhost:8000',
    'ftp://localhost',
    'http://localhost:8000/path',
    'http://user:secret@localhost:8000',
])->throws(RuntimeException::class);
