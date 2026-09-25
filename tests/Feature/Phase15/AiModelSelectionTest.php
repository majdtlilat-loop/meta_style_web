<?php

declare(strict_types=1);

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Models\PlatformAuditLog;
use App\Kernel\Platform\Credentials\AiModelCatalog;
use App\Kernel\Platform\Credentials\AiProviderSettings;
use App\Kernel\Platform\Identity\Http\Middleware\EnsurePlatformMfa;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Kernel\Platform\Settings\PlatformPreferences;
use App\Kernel\SaaS\Models\PlatformSetting;
use App\Kernel\Tenancy\PlatformHosts;
use App\Livewire\Sadmin\Settings\Ai;
use App\Modules\Rayan\Application\RayanSettings;
use App\Modules\Rayan\Domain\Data\AiCapabilities;
use Database\Seeders\LocalDevelopmentPlatformUserSeeder;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| AI models: the Super Admin's choice, not a hard-coded list
|--------------------------------------------------------------------------
|
| Any valid identifier the provider accepts can be configured — an older or
| cheaper model included — picked from the provider's own catalog or typed by
| hand when the catalog is unavailable. Nothing invents availability or price,
| and the key never reaches a screen.
|
*/

beforeEach(function (): void {
    $this->withoutVite();
    app()->call([app(LocalDevelopmentPlatformUserSeeder::class), 'run']);
    $this->admin = PlatformUser::query()->where('email', 'admin@meta-style.local')->firstOrFail();
    $this->admin->forceFill(['mfa_confirmed_at' => now()])->save();
    $this->actingAs($this->admin, 'platform')->withSession([EnsurePlatformMfa::SESSION_KEY => now()->timestamp]);
    $this->actor = Actor::platform($this->admin);
    config()->set('rayan.providers.openai.base_url', 'https://ai.test/v1');
});

afterEach(function (): void {
    PlatformSetting::query()->whereIn('key', [PlatformPreferences::AI_MODEL, PlatformPreferences::AI_REPORT_MODEL, PlatformPreferences::AI_ENABLED, AiModelCatalog::KEY])->delete();
});

it('accepts any valid model identifier, not only the shipped list', function (): void {
    $approved = config('rayan.providers.openai.approved_models');
    $older = 'gpt-4o-mini';
    expect(in_array($older, $approved, true))->toBeFalse('the test must pick a model outside the shipped list');

    app(PlatformPreferences::class)->saveAi(true, $older, 'gpt-3.5-turbo-0125', $this->actor);
    $preferences = app(PlatformPreferences::class);

    expect($preferences->ai())->toBe(['enabled' => true, 'model' => $older, 'report_model' => 'gpt-3.5-turbo-0125'])
        ->and($preferences->approvedModels())->toContain($older)->toContain('gpt-3.5-turbo-0125')
        ->and(app(AiProviderSettings::class)->defaultModel())->toBe($older)
        ->and(PlatformAuditLog::query()->where('action', 'platform.settings.ai.updated')->exists())->toBeTrue();

    // Fine-tuned identifiers with colons are valid; shell-ish input is not.
    app(PlatformPreferences::class)->saveAi(true, 'ft:gpt-4o-mini:acme:salon:abc123', null, $this->actor);
    expect(app(PlatformPreferences::class)->ai()['model'])->toBe('ft:gpt-4o-mini:acme:salon:abc123');
    expect(fn () => app(PlatformPreferences::class)->saveAi(true, 'gpt 4; rm -rf', null, $this->actor))->toThrow(DomainException::class);
});

it('uses the report model for report analysis and the assistant model elsewhere', function (): void {
    $capabilities = new AiCapabilities(true, true, ['gpt-4o-mini', 'gpt-4.1'], 'gpt-4o-mini', 'gpt-4.1');
    $none = new AiCapabilities(true, true, ['gpt-4o-mini'], 'gpt-4o-mini');
    $settings = app(RayanSettings::class);

    expect($settings->reportModel($capabilities))->toBe('gpt-4.1')
        ->and($settings->reportModel($none))->toBe('gpt-4o-mini');
});

it('loads the provider catalog with the key, keeps only identifiers, and never claims a price', function (): void {
    app(AiProviderSettings::class)->setKey('sk-test-'.str_repeat('a', 40), $this->actor);
    Http::fake([
        'ai.test/v1/models' => Http::response(['object' => 'list', 'data' => [
            ['id' => 'gpt-4o-mini', 'object' => 'model', 'created' => 1721172741, 'owned_by' => 'system'],
            ['id' => 'gpt-3.5-turbo', 'object' => 'model', 'created' => 1677610602, 'owned_by' => 'openai'],
            ['id' => 'bad id <script>', 'object' => 'model'],
        ]]),
    ]);

    $result = app(AiModelCatalog::class)->refresh($this->actor);
    $cached = app(AiModelCatalog::class)->cached();

    expect($result)->toBe(['ok' => true, 'error' => null, 'count' => 2])
        ->and(array_column($cached['models'], 'id'))->toBe(['gpt-3.5-turbo', 'gpt-4o-mini'])
        ->and(app(AiModelCatalog::class)->contains('gpt-4o-mini'))->toBeTrue()
        ->and(app(AiModelCatalog::class)->contains('gpt-9-imaginary'))->toBeFalse();
    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization') && str_ends_with($request->url(), '/models'));

    $page = $this->get(rtrim(app(PlatformHosts::class)->superAdminUrl(), '/').'/settings/ai')->assertOk()->assertSee('gpt-3.5-turbo');
    expect(str_contains((string) $page->getContent(), str_repeat('a', 40)))->toBeFalse('the key is never rendered')
        ->and(str_contains(mb_strtolower((string) $page->getContent()), 'price per'))->toBeFalse();
});

it('falls back to a typed identifier when the catalog cannot be loaded', function (): void {
    app(AiProviderSettings::class)->setKey('sk-test-'.str_repeat('b', 40), $this->actor);
    Http::fake(['ai.test/v1/models' => Http::response(['error' => 'nope'], 401)]);

    expect(app(AiModelCatalog::class)->refresh($this->actor))->toBe(['ok' => false, 'error' => 'rejected', 'count' => 0])
        ->and(app(AiModelCatalog::class)->contains('anything'))->toBeNull();

    Livewire::test(Ai::class)
        ->set('model', 'gpt-4o-mini-2024-07-18')
        ->call('save')
        ->assertHasNoErrors();
    expect(app(PlatformPreferences::class)->ai()['model'])->toBe('gpt-4o-mini-2024-07-18');

    Livewire::test(Ai::class)->set('model', '<b>x</b>')->call('save')->assertHasErrors('model');
});

it('never puts the key in component state', function (): void {
    app(AiProviderSettings::class)->setKey('sk-test-'.str_repeat('c', 40), $this->actor);
    $component = Livewire::test(Ai::class);
    expect(json_encode($component->instance()->all()))->not->toContain(str_repeat('c', 40));

    $component->call('openPanel', 'key')->set('apiKey', 'sk-new-'.str_repeat('d', 40))->call('saveApiKey')->assertHasNoErrors();
    expect($component->get('apiKey'))->toBe('')
        ->and(app(AiProviderSettings::class)->status()['hint'])->toBe('dddd');
});
