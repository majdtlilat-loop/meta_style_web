<?php

declare(strict_types=1);

use App\Kernel\Localization\Http\Middleware\SetLocale;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Tenancy\Infrastructure\StanclTenantResolver;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Submit the real Livewire HTTP request emitted by the center login page.
 *
 * Livewire::test() invokes the component in-process and cannot catch a tenant
 * context that disappears between persistent middleware and the component
 * action. These tests intentionally cross `/livewire/update` instead.
 *
 * @return array{response: TestResponse, redirect: string}
 */
function submitCenterLoginHttp(
    TestCase $test,
    string $slug,
    string $email,
    string $password,
    ?TestResponse $loginPage = null,
): array {
    $loginPage ??= $test->get("http://{$slug}.localhost:8000/login");
    $loginPage->assertOk();

    preg_match('/wire:snapshot="([^"]+)"/', $loginPage->getContent(), $matches);

    expect($matches)->toHaveKey(1);

    $snapshot = html_entity_decode($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $response = $test
        ->withHeaders(['X-Livewire' => 'true'])
        ->postJson("http://{$slug}.localhost:8000/livewire/update", [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [
                    'identifier' => $email,
                    'password' => $password,
                ],
                'calls' => [[
                    'path' => '',
                    'method' => 'submit',
                    'params' => [],
                ]],
            ]],
        ]);

    $response->assertOk();

    $redirect = $response->json('components.0.effects.redirect');

    expect($redirect)->toBeString();

    return ['response' => $response, 'redirect' => $redirect];
}

it('completes the real center login HTTP flow on the authoritative center host', function (): void {
    $password = 'correct-horse-battery-staple';
    $center = $this->registerCenter('Dr Bany Test', 'owner@drbany-runtime.test', $password);
    $slug = $center['registration']->requested_slug;
    $tenantId = $center['tenant']->id;

    $login = $this->get("http://{$slug}.localhost:8000/login");
    $login->assertOk();

    $result = submitCenterLoginHttp($this, $slug, 'owner@drbany-runtime.test', $password, $login);
    $expected = "http://{$slug}.localhost:8000/manager";

    expect($result['redirect'])->toBe($expected)
        ->and(parse_url($result['redirect'], PHP_URL_HOST))->toBe("{$slug}.localhost")
        ->and($result['redirect'])->not->toContain($tenantId)
        ->and($result['redirect'])->not->toContain('superadmin.')
        ->and(session(StanclTenantResolver::SESSION_KEY))->toBe($this->publicKeyOf($center['tenant']));

    $this->get($result['redirect'])
        ->assertOk()
        ->assertSee('Dr Bany Test');

    // A new request and another Manager route both keep the authenticated
    // session on the same center host.
    $this->get($expected)->assertOk();
    $this->get("http://{$slug}.localhost:8000/manager/staff")->assertOk();

    $this->post("http://{$slug}.localhost:8000/logout")
        ->assertRedirect("http://{$slug}.localhost:8000/login");

    $this->get($expected)
        ->assertRedirect("http://{$slug}.localhost:8000/login");

    // Logout destroys the old session; a fresh login still traverses the same
    // real endpoint and returns to the same named Manager route.
    $again = submitCenterLoginHttp($this, $slug, 'owner@drbany-runtime.test', $password);
    expect($again['redirect'])->toBe($expected);
    $this->get($again['redirect'])->assertOk();
});

it('keeps unauthenticated manager redirects on the requested center host', function (): void {
    $center = $this->registerCenter('Unauthenticated Center', 'owner@unauth-runtime.test');
    $slug = $center['registration']->requested_slug;

    $this->get("http://{$slug}.localhost:8000/manager")
        ->assertRedirect("http://{$slug}.localhost:8000/login");
});

it('refuses a signed-in center session on another center host', function (): void {
    $password = 'correct-horse-battery-staple';
    $alpha = $this->registerCenter('Alpha Runtime', 'owner@alpha-runtime.test', $password);
    $beta = $this->registerCenter('Beta Runtime', 'owner@beta-runtime.test', $password);
    $alphaSlug = $alpha['registration']->requested_slug;
    $betaSlug = $beta['registration']->requested_slug;

    submitCenterLoginHttp($this, $alphaSlug, 'owner@alpha-runtime.test', $password);

    $this->get("http://{$betaSlug}.localhost:8000/manager")
        ->assertForbidden();
});

it('preserves the selected center locale through the real login redirect', function (string $locale, string $direction): void {
    $password = 'correct-horse-battery-staple';
    $center = $this->registerCenter("{$locale} Runtime", "owner-{$locale}@runtime.test", $password);
    $slug = $center['registration']->requested_slug;

    $this->asCenter(
        $center['tenant'],
        function (): void {
            app(TenantLocales::class)->setEnabled(['en', 'ar', 'ckb'], 'en');
        },
    );

    $login = $this->get("http://{$slug}.localhost:8000/login?locale={$locale}");
    $login->assertOk();

    $result = submitCenterLoginHttp($this, $slug, "owner-{$locale}@runtime.test", $password, $login);
    $manager = $this->get($result['redirect']);

    $manager->assertOk()
        ->assertSee("lang=\"{$locale}\"", false)
        ->assertSee("dir=\"{$direction}\"", false);

    expect(session(SetLocale::SESSION_KEY))->toBe($locale);
})->with([
    'English' => ['en', 'ltr'],
    'Arabic' => ['ar', 'rtl'],
    'Kurdish Sorani' => ['ckb', 'rtl'],
]);

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
