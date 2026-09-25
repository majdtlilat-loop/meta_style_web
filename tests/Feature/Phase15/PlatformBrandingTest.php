<?php

declare(strict_types=1);

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Models\PlatformAuditLog;
use App\Kernel\Platform\Branding\Color;
use App\Kernel\Platform\Branding\PlatformBranding;
use App\Kernel\Platform\Branding\PlatformTheme;
use App\Kernel\Platform\Identity\Actions\ManagePlatformRoles;
use App\Kernel\Platform\Identity\Actions\ManagePlatformUsers;
use App\Kernel\Platform\Identity\Http\Middleware\EnsurePlatformMfa;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Kernel\SaaS\Models\PlatformSetting;
use App\Kernel\Tenancy\PlatformHosts;
use App\Livewire\Sadmin\Settings\Branding;
use Database\Seeders\LocalDevelopmentPlatformUserSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Platform branding: logo, favicon and the platform theme
|--------------------------------------------------------------------------
|
| One authoritative source for Meta Style's own branding. Uploads are raster
| images checked by content; the theme is structured colours turned into the
| semantic tokens, never CSS. The default theme emits nothing, so Rose Gold
| Luxe stays exactly as designed, and a center's pages never pick any of it up.
|
*/

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('public');
    app()->call([app(LocalDevelopmentPlatformUserSeeder::class), 'run']);
    $this->admin = PlatformUser::query()->where('email', 'admin@meta-style.local')->firstOrFail();
    $this->admin->forceFill(['mfa_confirmed_at' => now()])->save();
    $this->actingAs($this->admin, 'platform')->withSession([EnsurePlatformMfa::SESSION_KEY => now()->timestamp]);
    $this->actor = Actor::platform($this->admin);
});

afterEach(function (): void {
    // platform_settings is reference data (not truncated): leave the defaults.
    PlatformSetting::query()->whereIn('key', [PlatformBranding::IDENTITY, PlatformBranding::THEME])->delete();
    $roles = DB::connection('control')->table('platform_roles')->where('is_system', false)->pluck('id');
    DB::connection('control')->table('platform_role_permissions')->whereIn('role_id', $roles)->delete();
    DB::connection('control')->table('platform_roles')->whereIn('id', $roles)->delete();
});

function pngUpload(string $name, int $width, int $height): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 183, 110, 121));
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();

    return UploadedFile::fake()->createWithContent($name, $bytes);
}

it('stores, replaces and resets the platform logo and favicon, audited', function (): void {
    $branding = app(PlatformBranding::class);
    expect($branding->logo()['custom'])->toBeFalse()
        ->and($branding->favicon()['custom'])->toBeFalse();

    $branding->uploadLogo('light', pngUpload('logo.png', 400, 120), $this->actor);
    $first = app(PlatformBranding::class)->identity()['logo_light'];
    expect($first)->toStartWith('platform/branding/logo-light-')
        ->and(Storage::disk('public')->exists((string) $first))->toBeTrue()
        ->and(app(PlatformBranding::class)->logo('dark')['url'])->toBe(app(PlatformBranding::class)->logo('light')['url']);

    app(PlatformBranding::class)->uploadLogo('light', pngUpload('logo2.png', 300, 90), $this->actor);
    $second = app(PlatformBranding::class)->identity()['logo_light'];
    expect($second)->not->toBe($first)
        ->and(Storage::disk('public')->exists((string) $first))->toBeFalse('a replaced file is removed');

    app(PlatformBranding::class)->uploadFavicon(pngUpload('icon.png', 64, 64), $this->actor);
    expect(app(PlatformBranding::class)->favicon())->toMatchArray(['custom' => true, 'type' => 'image/png']);

    app(PlatformBranding::class)->removeLogo('light', $this->actor);
    app(PlatformBranding::class)->removeFavicon($this->actor);
    expect(app(PlatformBranding::class)->logo()['custom'])->toBeFalse()
        ->and(app(PlatformBranding::class)->favicon()['custom'])->toBeFalse();

    foreach (['platform.branding.logo.updated', 'platform.branding.favicon.updated', 'platform.branding.logo.removed', 'platform.branding.favicon.removed'] as $action) {
        expect(PlatformAuditLog::query()->where('action', $action)->exists())->toBeTrue($action);
    }
});

it('refuses unsafe or wrong branding files by their content', function (): void {
    $branding = app(PlatformBranding::class);
    $svg = UploadedFile::fake()->createWithContent('logo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    expect(fn () => $branding->uploadLogo('light', $svg, $this->actor))->toThrow(DomainException::class);
    expect(fn () => $branding->uploadFavicon(pngUpload('wide.png', 64, 32), $this->actor))->toThrow(DomainException::class);
    expect(fn () => $branding->uploadFavicon(pngUpload('huge.png', 1024, 1024), $this->actor))->toThrow(DomainException::class);
    expect(fn () => $branding->uploadLogo('purple', pngUpload('logo.png', 200, 60), $this->actor))->toThrow(DomainException::class);
    expect($branding->identity()['logo_light'])->toBeNull();
});

it('emits nothing for the default theme and semantic tokens for a custom one', function (): void {
    expect(PlatformTheme::css(PlatformTheme::defaults()))->toBe('');

    $theme = PlatformTheme::defaults();
    $theme['light']['primary'] = '#1f4e79';
    $theme['dark']['background'] = '#000000';
    $theme['gradients']['cta'] = ['enabled' => true, 'from' => '#1f4e79', 'via' => '', 'to' => '#2e7bbf', 'angle' => 90];
    $css = PlatformTheme::css(PlatformTheme::normalize($theme));

    expect($css)->toContain(':root:not([data-theme="dark"]),[data-theme="light"]{')
        ->toContain('--color-primary:#1f4e79;')
        ->toContain('--color-primary-strong:')
        ->toContain('[data-theme="dark"]{--color-canvas:#000000;')
        ->toContain('--gradient-cta:linear-gradient(90deg, #1f4e79, #2e7bbf);')
        ->and(str_contains($css, '--color-text:'))->toBeFalse('unchanged colours keep their hand-tuned tokens');
});

it('validates colours and gradients as structured values, never CSS', function (): void {
    $theme = PlatformTheme::defaults();
    $theme['light']['primary'] = 'red;} body{display:none';
    expect(fn () => PlatformTheme::normalize($theme))->toThrow(DomainException::class);

    $theme = PlatformTheme::defaults();
    $theme['gradients']['hero'] = ['enabled' => true, 'from' => '#000000', 'via' => 'url(javascript:alert(1))', 'to' => '#ffffff', 'angle' => 90];
    expect(fn () => PlatformTheme::normalize($theme))->toThrow(DomainException::class);

    $theme = PlatformTheme::defaults();
    $theme['gradients']['hero'] = ['enabled' => true, 'from' => '#000000', 'via' => '', 'to' => '#ffffff', 'angle' => 17];
    expect(fn () => PlatformTheme::normalize($theme))->toThrow(DomainException::class);

    // Upper-case hex from a colour picker is accepted and normalised.
    $theme = PlatformTheme::defaults();
    $theme['light']['accent'] = '#E4C3AD';
    expect(PlatformTheme::normalize($theme)['light']['accent'])->toBe('#e4c3ad');
});

it('warns about unreadable colours without changing them', function (): void {
    $theme = PlatformTheme::defaults();
    expect(PlatformTheme::contrastWarnings($theme))->toBe([]);

    $theme['light']['text'] = '#f0f0f0';
    $warnings = PlatformTheme::contrastWarnings($theme);
    expect(array_column($warnings, 'pair'))->toContain('text_background')
        ->and($theme['light']['text'])->toBe('#f0f0f0');
    expect(Color::contrast('#000000', '#ffffff'))->toBe(21.0);
});

it('saves and resets the theme from the settings page, and serves it on platform pages only', function (): void {
    Livewire::test(Branding::class)
        ->set('theme.light.primary', '#1F4E79')
        ->call('saveTheme')
        ->assertHasNoErrors();
    expect(app(PlatformBranding::class)->theme()['light']['primary'])->toBe('#1f4e79')
        ->and(PlatformAuditLog::query()->where('action', 'platform.branding.theme.updated')->exists())->toBeTrue();

    app(PlatformBranding::class)->uploadFavicon(pngUpload('icon.png', 32, 32), $this->actor);
    $hosts = app(PlatformHosts::class);
    $this->get(rtrim($hosts->superAdminUrl(), '/').'/settings/branding')
        ->assertOk()
        ->assertSee('--color-primary:#1f4e79', false)
        ->assertSee('rel="icon"', false);
    $this->get(rtrim($hosts->corporateUrl(), '/').'/')
        ->assertOk()
        ->assertSee('id="platform-theme"', false);

    Livewire::test(Branding::class)->call('askReset')->assertSet('confirm', 'reset')->call('confirmAction');
    expect(app(PlatformBranding::class)->theme())->toBe(PlatformTheme::defaults())
        ->and(PlatformAuditLog::query()->where('action', 'platform.branding.theme.reset')->exists())->toBeTrue();
});

it('keeps branding out of reach of a support-only colleague', function (): void {
    $role = app(ManagePlatformRoles::class)->create(['en' => 'Tickets', 'ar' => 'تذاكر', 'ckb' => 'تیکێت'], ['en' => '', 'ar' => '', 'ckb' => ''], ['platform.support.view', 'platform.support.manage'], $this->admin);
    $support = app(ManagePlatformUsers::class)->invite('Support Only', 'support-brand@example.test', [$role->id], $this->admin);
    $support->forceFill(['mfa_confirmed_at' => now()])->save();
    $this->actingAs($support, 'platform');
    $base = rtrim(app(PlatformHosts::class)->superAdminUrl(), '/');

    foreach (['/settings/branding', '/settings/ai', '/settings/invoices', '/settings', '/center-users'] as $path) {
        $this->get($base.$path)->assertForbidden();
    }
    $this->get($base.'/support')->assertOk();
});
