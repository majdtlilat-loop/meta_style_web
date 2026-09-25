<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Media\MediaOwner;
use App\Kernel\Media\Models\MediaItem;
use App\Modules\CenterSite\Application\Actions\ManageBrandAssets;
use App\Modules\CenterSite\Application\Actions\UpdateCenterBrand;
use App\Modules\CenterSite\Application\BrandSettings;
use App\Modules\CenterSite\Contracts\CenterBrandReader;
use App\Modules\CenterSite\Domain\CenterBrand;
use App\Modules\CenterSite\Domain\CenterTheme;
use App\Modules\CenterSite\Domain\InvalidSiteContent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| The center's own brand
|--------------------------------------------------------------------------
|
| Structured values only: #rrggbb colours, gradients from validated stops and
| a fixed angle list, radius and style keys, media uuids. The chosen colours
| are stored as chosen — contrast is reported, never corrected. Separate from
| Meta Style's platform branding.
|
*/

function brandPng(int $width, int $height): UploadedFile
{
    return UploadedFile::fake()->image('asset.png', $width, $height);
}

function brandIco(): UploadedFile
{
    $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAEklEQVR42mP8z8BQDwAEgAF/QualIQAAAABJRU5ErkJggg==');

    return UploadedFile::fake()->createWithContent('favicon.ico', pack('vvv', 0, 1, 1).pack('CCCCvvVV', 16, 16, 0, 0, 1, 32, strlen($png), 22).$png);
}

it('refuses anything that is not a colour, an offered angle or a listed option', function (array $input): void {
    expect(fn () => CenterBrand::normalize($input))->toThrow(InvalidSiteContent::class);
})->with([
    'named colour' => [['light' => ['primary' => 'red']]],
    'css injection' => [['light' => ['primary' => '#fff;}body{display:none']]],
    'short hex' => [['dark' => ['text' => '#fff']]],
    'free angle' => [['gradients' => ['brand' => ['from' => '#000000', 'to' => '#ffffff', 'angle' => 17]]]],
    'url in a stop' => [['gradients' => ['hero' => ['from' => 'url(x)', 'to' => '#ffffff', 'angle' => 90]]]],
    'unknown radius' => [['radius' => 'blob']],
]);

it('emits only validated tokens and reports low contrast without changing a colour', function (): void {
    $brand = CenterBrand::normalize(['light' => ['text' => '#dddddd', 'background' => '#ffffff', 'primary' => '#123456'], 'scheme' => 'auto']);

    $css = CenterTheme::css($brand);
    expect($css)->toContain('--center-primary:#123456;')
        ->toContain('--center-text:#dddddd;')
        ->toContain('@media (prefers-color-scheme: dark)');
    foreach (['<', '>', '"', "'", '&', 'url('] as $needle) {
        expect(str_contains($css, $needle))->toBeFalse("The theme CSS must not contain {$needle}");
    }

    $pairs = array_column(CenterTheme::contrastWarnings($brand), 'pair');
    expect($pairs)->toContain('text_background')
        ->and($brand['light']['text'])->toBe('#dddddd');
});

it('saves and resets the brand, audited, keeping logos out of the colour form', function (): void {
    $center = $this->registerCenter('Brand Center', 'owner@brand.test');

    $this->asCenter($center['tenant'], function (): void {
        $owner = User::query()->where('is_owner', true)->firstOrFail();
        $settings = app(BrandSettings::class);
        $update = app(UpdateCenterBrand::class);

        $update(['light' => ['primary' => '#0F766E'], 'radius' => 'pill', 'logo_light' => '11111111-1111-4111-8111-111111111111'], $owner);

        $saved = $settings->get();
        expect($saved['light']['primary'])->toBe('#0f766e')
            ->and($saved['radius'])->toBe('pill')
            // A logo is never set from the form.
            ->and($saved['logo_light'])->toBe('');

        $audit = DB::connection('tenant')->table('audit_logs')->where('action', 'center.brand.updated')->first();
        expect($audit)->not->toBeNull()
            ->and((string) $audit->after)->toContain('light.primary')
            ->and((string) $audit->after)->not->toContain('logo');

        $update->reset($owner);
        expect($settings->get()['light']['primary'])->toBe(CenterBrand::DEFAULT_COLORS['light']['primary'])
            ->and(DB::connection('tenant')->table('audit_logs')->where('action', 'center.brand.reset')->count())->toBe(1);

        $viewer = $this->staffWith([Permission::AppearanceView], 'viewer@brand.test');
        expect(fn () => $update(['radius' => 'square'], $viewer))->toThrow(AuthorizationException::class);
    });
});

it('uploads, replaces and removes the logo and favicon, validated on their bytes', function (): void {
    $center = $this->registerCenter('Logo Center', 'owner@logo.test');

    $this->asCenter($center['tenant'], function (): void {
        $owner = User::query()->where('is_owner', true)->firstOrFail();
        $assets = app(ManageBrandAssets::class);
        $reader = app(CenterBrandReader::class);

        $brand = $assets->upload('logo_light', brandPng(400, 120), $owner);
        $first = $brand['logo_light'];
        expect(MediaItem::query()->for(MediaOwner::Brand, 1)->where('uuid', $first)->exists())->toBeTrue()
            ->and($reader->forPublic()['logo_light_url'])->toContain('branding/');

        // Replacing deletes the old brand file with its row.
        $brand = $assets->upload('logo_light', brandPng(300, 100), $owner);
        expect($brand['logo_light'])->not->toBe($first)
            ->and(MediaItem::query()->where('uuid', $first)->exists())->toBeFalse();

        $brand = $assets->upload('favicon', brandPng(64, 64), $owner);
        expect($brand['favicon'])->not->toBe('');
        $brand = $assets->upload('favicon', brandIco(), $owner);
        expect(MediaItem::query()->where('uuid', $brand['favicon'])->value('mime_type'))->toBe('image/x-icon');

        // Not square, not an image, an SVG: refused.
        expect(fn () => $assets->upload('favicon', brandPng(64, 32), $owner))->toThrow(ValidationException::class)
            ->and(fn () => $assets->upload('logo_dark', UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), $owner))->toThrow(ValidationException::class)
            ->and(fn () => $assets->upload('logo_dark', UploadedFile::fake()->createWithContent('logo.png', '<?php echo 1;'), $owner))->toThrow(ValidationException::class)
            ->and(fn () => $assets->upload('banner', brandPng(400, 120), $owner))->toThrow(InvalidSiteContent::class);

        $assets->remove('logo_light', $owner);
        expect(app(BrandSettings::class)->get()['logo_light'])->toBe('')
            ->and($reader->forPublic()['logo_light_url'])->toBeNull()
            ->and(DB::connection('tenant')->table('audit_logs')->where('action', 'center.brand.asset_removed')->count())->toBe(1);

        // Only this center's files: other suites may be using the storage root.
        Storage::disk('public')->deleteDirectory('branding');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
