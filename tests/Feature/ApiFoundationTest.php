<?php

declare(strict_types=1);

use App\Kernel\Http\ApiErrorCode;
use App\Kernel\Observability\Middleware\AssignRequestId;

/*
|--------------------------------------------------------------------------
| API response and error conventions
|--------------------------------------------------------------------------
|
| docs/10-API-FOUNDATION.md §3–4. These shapes are a contract with every
| current and future client, including mobile builds that will never update,
| so they are locked by tests from the first phase.
|
*/

it('wraps successful responses in the data envelope', function (): void {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['status', 'application', 'environment'],
            'meta' => ['request_id'],
        ])
        ->assertJsonPath('data.status', 'ok');
});

it('echoes a client-supplied correlation id', function (): void {
    $response = $this->withHeader(AssignRequestId::HEADER, 'client-abc-123')
        ->getJson('/api/v1/health');

    $response->assertOk()
        ->assertJsonPath('meta.request_id', 'client-abc-123')
        ->assertHeader(AssignRequestId::HEADER, 'client-abc-123');
});

it('generates a correlation id when the client supplies none', function (): void {
    $response = $this->getJson('/api/v1/health');

    $requestId = $response->json('meta.request_id');

    expect($requestId)->toBeString()->not->toBeEmpty()
        ->and($response->headers->get(AssignRequestId::HEADER))->toBe($requestId);
});

it('rejects an unsafe correlation id instead of echoing it', function (): void {
    // The id is reflected in responses and logs, so it is untrusted input.
    $response = $this->withHeader(AssignRequestId::HEADER, "evil\r\nX-Injected: 1")
        ->getJson('/api/v1/health');

    expect($response->json('meta.request_id'))->not->toContain('X-Injected')
        ->and($response->headers->has('X-Injected'))->toBeFalse();
});

it('returns the error envelope with a stable machine-readable code', function (): void {
    $this->getJson('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertJsonStructure([
            'error' => ['code', 'message'],
            'meta' => ['request_id'],
        ])
        ->assertJsonPath('error.code', ApiErrorCode::NotFound->value);
});

it('returns a method-not-allowed code rather than a generic failure', function (): void {
    $this->postJson('/api/v1/health')
        ->assertStatus(405)
        ->assertJsonPath('error.code', ApiErrorCode::MethodNotAllowed->value);
});

it('leaves web routes rendering HTML, not the API envelope', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('Meta Style');
});
