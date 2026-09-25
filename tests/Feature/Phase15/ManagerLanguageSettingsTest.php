<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Authorization\Permission;
use App\Kernel\Localization\Actions\UpdateContentLanguages;
use App\Kernel\Localization\TenantLocales;
use App\Livewire\Center\Settings\Languages;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Settings → Languages. The section is its own component (Settings has five);
 * the rules live in UpdateContentLanguages, so they hold for any caller.
 */

it('lets a center enable all content languages and choose Arabic as primary', function (): void {
    $center = $this->registerCenter('Language Center', 'owner@languages.test');
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        Livewire::actingAs($owner)
            ->test(Languages::class)
            ->assertSee('KU')
            ->assertDontSee('>CKB<', false)
            ->set('enabledLocales', ['en', 'ar', 'ckb'])
            ->set('primaryLocale', 'ar')
            ->call('saveLanguages')
            ->assertHasNoErrors();

        app(TenantLocales::class)->forget();

        expect(app(TenantLocales::class)->enabled())->toBe(['en', 'ar', 'ckb'])
            ->and(app(TenantLocales::class)->default())->toBe('ar')
            ->and(TenantAuditLog::query()->where('action', 'settings.languages.updated')->exists())->toBeTrue();
    });
});

it('requires one enabled language and requires the primary language to be enabled', function (): void {
    $center = $this->registerCenter('Language Rules', 'owner@language-rules.test');
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        Livewire::actingAs($owner)
            ->test(Languages::class)
            ->set('enabledLocales', [])
            ->call('saveLanguages')
            ->assertHasErrors('enabledLocales');

        Livewire::actingAs($owner)
            ->test(Languages::class)
            ->set('enabledLocales', ['en'])
            ->set('primaryLocale', 'ckb')
            ->call('saveLanguages')
            ->assertHasErrors('primaryLocale');
    });
});

it('never switches off the current primary without a new one, and never deletes a translation', function (): void {
    $center = $this->registerCenter('Language Keep', 'owner@language-keep.test');
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $update = app(UpdateContentLanguages::class);
        $update($owner, ['en', 'ar'], 'en');

        // Dropping English while it is still the primary is refused.
        expect(fn () => $update($owner, ['ar'], 'en'))->toThrow(ValidationException::class);

        $service = $this->seedCatalog()['service'];

        // Arabic off, then on again: its text is still there.
        $update($owner, ['en'], 'en');
        $update($owner, ['en', 'ar'], 'en');

        expect($service->fresh()->name->get('ar'))->toBe('قص شعر');

        $viewer = $this->staffWith([Permission::SettingsView], 'settings-viewer@language-keep.test');
        expect(fn () => $update($viewer, ['en'], 'en'))->toThrow(AuthorizationException::class);
    });
});

it('renders the manager shell navigation once with accessible settings navigation', function (): void {
    $center = $this->registerCenter('Shell Center', 'owner@shell.test');
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug): void {
        $this->actingAs($owner);
        $html = $this->get("http://{$slug}.localhost:8000/manager/settings")
            ->assertOk()
            ->getContent();

        expect(substr_count($html, 'aria-label="Center navigation"'))->toBe(1)
            ->and($html)->toContain('data-sidebar-collapse')
            ->toContain('aria-current="page"')
            // The live bell (polling, persisted across wire:navigate, mutable
            // chime) and the upgrade prompt belong to every Manager page.
            ->toContain('wire:poll.20s.visible="poll"')
            ->toContain('x-persist="manager-bell"')
            ->toContain('data-sound-toggle');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
