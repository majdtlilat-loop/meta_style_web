<?php

declare(strict_types=1);

use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\Reviews\Application\ReviewInvitations;
use App\Modules\Sales\Application\InvoiceLinks;

/*
|--------------------------------------------------------------------------
| Links minted without a request still open on the center's own host
|--------------------------------------------------------------------------
|
| Since Phase 15 a center answers only on {slug}.<base domain>. A link minted
| by an API client on another host, or by a queued job, used to fall back to
| the center's public key — an address that no longer opens. The bound
| center's registered slug now names its host.
|
*/

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});

it('publishes invoice and review links on the center host when there is no request host', function (): void {
    $center = $this->registerCenter('Link Host Center', 'owner@link-host.test');
    $slug = $center['registration']->requested_slug;
    $secret = str_repeat('a', 64);

    $this->asCenter($center['tenant'], function () use ($slug, $secret): void {
        expect(app(TenantContext::class)->tenant()?->slug)->toBe($slug);

        expect(app(InvoiceLinks::class)->url($secret))->toStartWith("http://{$slug}.localhost:8000/i/")
            ->and(app(ReviewInvitations::class)->url($secret))->toStartWith("http://{$slug}.localhost:8000/r/");
    });
});
