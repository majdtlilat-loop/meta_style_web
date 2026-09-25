<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Branding;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\SaaS\Models\PlatformSetting;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * THE source of Meta Style's own branding: the platform logo (a light and an
 * optional dark variant), the favicon and the platform theme.
 *
 * Platform-owned surfaces read it — the corporate site, the Super Admin and
 * its sign-in pages, platform documents. A center's own pages never do:
 * center branding is the center's, and nothing here overrides it.
 *
 * Files are raster images only (PNG/JPEG, favicon PNG/ICO), checked by their
 * CONTENT rather than their name, stored on the public disk under
 * `platform/branding/`. No SVG upload: an uploaded SVG is a script container.
 */
final class PlatformBranding
{
    public const IDENTITY = 'branding.identity';

    public const THEME = 'branding.theme';

    public const DIRECTORY = 'platform/branding';

    public const LOGO_VARIANTS = ['light', 'dark'];

    public const LOGO_MAX_KB = 1024;

    public const FAVICON_MAX_KB = 200;

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(private readonly Audit $audit) {}

    /** @return array{logo_light: string|null, logo_dark: string|null, favicon: string|null, show_name: bool} */
    public function identity(): array
    {
        if (! array_key_exists(self::IDENTITY, $this->memo)) {
            $stored = PlatformSetting::get(self::IDENTITY);
            $stored = is_array($stored) ? $stored : [];
            $this->memo[self::IDENTITY] = [
                'logo_light' => $this->validPath($stored['logo_light'] ?? null),
                'logo_dark' => $this->validPath($stored['logo_dark'] ?? null),
                'favicon' => $this->validPath($stored['favicon'] ?? null),
                'show_name' => (bool) ($stored['show_name'] ?? true),
            ];
        }

        /** @var array{logo_light: string|null, logo_dark: string|null, favicon: string|null, show_name: bool} */
        return $this->memo[self::IDENTITY];
    }

    /**
     * The logo for one theme. The dark variant falls back to the uploaded
     * light one, then to the bundled Meta Style mark.
     *
     * @return array{url: string, path: string, custom: bool, type: string}
     */
    public function logo(string $variant = 'light'): array
    {
        $identity = $this->identity();
        $path = $variant === 'dark' ? ($identity['logo_dark'] ?? $identity['logo_light']) : $identity['logo_light'];
        if ($path !== null && Storage::disk('public')->exists($path)) {
            return ['url' => Storage::disk('public')->url($path), 'path' => Storage::disk('public')->path($path), 'custom' => true, 'type' => $this->typeOf($path)];
        }
        $bundled = 'brand/meta-style-mark-'.($variant === 'dark' ? 'dark' : 'light').'.svg';

        return ['url' => asset($bundled), 'path' => public_path($bundled), 'custom' => false, 'type' => 'image/svg+xml'];
    }

    /** @return array{url: string, type: string, custom: bool} */
    public function favicon(): array
    {
        $path = $this->identity()['favicon'];
        if ($path !== null && Storage::disk('public')->exists($path)) {
            return ['url' => Storage::disk('public')->url($path), 'type' => $this->typeOf($path), 'custom' => true];
        }

        return ['url' => asset('brand/meta-style-mark-light.svg'), 'type' => 'image/svg+xml', 'custom' => false];
    }

    public function showsName(): bool
    {
        return $this->identity()['show_name'];
    }

    /** @return array{light: array<string, string>, dark: array<string, string>, gradients: array<string, array{enabled: bool, from: string, via: string, to: string, angle: int}>} */
    public function theme(): array
    {
        if (! array_key_exists(self::THEME, $this->memo)) {
            $stored = PlatformSetting::get(self::THEME);
            $this->memo[self::THEME] = is_array($stored) ? PlatformTheme::hydrate($stored) : PlatformTheme::defaults();
        }

        /** @var array{light: array<string, string>, dark: array<string, string>, gradients: array<string, array{enabled: bool, from: string, via: string, to: string, angle: int}>} */
        return $this->memo[self::THEME];
    }

    /** The override stylesheet for platform pages; empty for the default theme. */
    public function themeCss(): string
    {
        return PlatformTheme::css($this->theme());
    }

    public function uploadLogo(string $variant, UploadedFile $file, Actor $actor): void
    {
        if (! in_array($variant, self::LOGO_VARIANTS, true)) {
            throw new DomainException(__('platform_branding.errors.variant'));
        }
        $this->assertImage($file, [IMAGETYPE_PNG, IMAGETYPE_JPEG], self::LOGO_MAX_KB, 32, 4000, false);
        $this->replaceFile('logo_'.$variant, $file, 'logo-'.$variant, $actor, 'platform.branding.logo.updated');
    }

    public function removeLogo(string $variant, Actor $actor): void
    {
        if (! in_array($variant, self::LOGO_VARIANTS, true)) {
            throw new DomainException(__('platform_branding.errors.variant'));
        }
        $this->clearFile('logo_'.$variant, $actor, 'platform.branding.logo.removed');
    }

    public function uploadFavicon(UploadedFile $file, Actor $actor): void
    {
        $this->assertImage($file, [IMAGETYPE_PNG, IMAGETYPE_ICO], self::FAVICON_MAX_KB, 16, 512, true);
        $this->replaceFile('favicon', $file, 'favicon', $actor, 'platform.branding.favicon.updated');
    }

    public function removeFavicon(Actor $actor): void
    {
        $this->clearFile('favicon', $actor, 'platform.branding.favicon.removed');
    }

    public function setShowName(bool $show, Actor $actor): void
    {
        $identity = $this->identity();
        if ($identity['show_name'] === $show) {
            return;
        }
        $this->storeIdentity(['show_name' => $show] + $identity);
        $this->record('platform.branding.identity.updated', ['show_name' => $identity['show_name']], ['show_name' => $show], $actor);
    }

    /** @param array<string, mixed> $input */
    public function saveTheme(array $input, Actor $actor): void
    {
        $before = $this->theme();
        $theme = PlatformTheme::normalize($input);
        if ($theme === $before) {
            return;
        }
        PlatformSetting::put(self::THEME, $theme);
        unset($this->memo[self::THEME]);
        $this->record('platform.branding.theme.updated', $this->changes($before, $theme), $this->changes($theme, $before), $actor);
    }

    public function resetTheme(Actor $actor): void
    {
        $before = $this->theme();
        PlatformSetting::put(self::THEME, PlatformTheme::defaults());
        unset($this->memo[self::THEME]);
        $this->record('platform.branding.theme.reset', $this->changes($before, PlatformTheme::defaults()), null, $actor);
    }

    /**
     * Checks what the file IS, not what it is called: the MIME type sniffed
     * from its bytes and the image header, then its size and dimensions.
     *
     * @param  list<int>  $types
     */
    private function assertImage(UploadedFile $file, array $types, int $maxKb, int $minSide, int $maxSide, bool $square): void
    {
        $path = $file->getRealPath();
        if ($path === false || ! $file->isValid() || $file->getSize() > $maxKb * 1024) {
            throw new DomainException(__('platform_branding.errors.file_size', ['max' => $maxKb]));
        }
        $info = @getimagesize($path);
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $allowedMimes = ['image/png', 'image/jpeg', 'image/vnd.microsoft.icon', 'image/x-icon'];
        if ($info === false || ! in_array($info[2], $types, true) || ! in_array($mime, $allowedMimes, true)) {
            throw new DomainException(__('platform_branding.errors.file_type'));
        }
        [$width, $height] = [(int) $info[0], (int) $info[1]];
        if ($width < $minSide || $height < $minSide || $width > $maxSide || $height > $maxSide || ($square && $width !== $height)) {
            throw new DomainException(__($square ? 'platform_branding.errors.favicon_dimensions' : 'platform_branding.errors.logo_dimensions', ['min' => $minSide, 'max' => $maxSide]));
        }
    }

    private function replaceFile(string $slot, UploadedFile $file, string $prefix, Actor $actor, string $action): void
    {
        $info = @getimagesize((string) $file->getRealPath());
        if ($info === false) {
            throw new DomainException(__('platform_branding.errors.file_type'));
        }
        $extension = match ($info[2]) {
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_ICO => 'ico',
            default => throw new DomainException(__('platform_branding.errors.file_type')),
        };
        $stored = $file->storeAs(self::DIRECTORY, $prefix.'-'.Str::lower(Str::random(12)).'.'.$extension, 'public');
        if (! is_string($stored) || $stored === '') {
            throw new DomainException(__('platform_branding.errors.store'));
        }
        $identity = $this->identity();
        $previous = $identity[$slot] ?? null;
        $this->storeIdentity([$slot => $stored] + $identity);
        if (is_string($previous)) {
            // Nothing else references a replaced branding file: documents and
            // pages always read the current one.
            Storage::disk('public')->delete($previous);
        }
        $this->record($action, ['slot' => $slot, 'custom' => $previous !== null], ['slot' => $slot, 'custom' => true, 'width' => $info[0], 'height' => $info[1]], $actor);
    }

    private function clearFile(string $slot, Actor $actor, string $action): void
    {
        $identity = $this->identity();
        $previous = $identity[$slot] ?? null;
        if (! is_string($previous)) {
            return;
        }
        $this->storeIdentity([$slot => null] + $identity);
        Storage::disk('public')->delete($previous);
        $this->record($action, ['slot' => $slot, 'custom' => true], ['slot' => $slot, 'custom' => false], $actor);
    }

    /** @param array<string, mixed> $identity */
    private function storeIdentity(array $identity): void
    {
        PlatformSetting::put(self::IDENTITY, [
            'logo_light' => $identity['logo_light'] ?? null,
            'logo_dark' => $identity['logo_dark'] ?? null,
            'favicon' => $identity['favicon'] ?? null,
            'show_name' => (bool) ($identity['show_name'] ?? true),
        ]);
        unset($this->memo[self::IDENTITY]);
    }

    /**
     * Only a path this class could have written is ever read back.
     */
    private function validPath(mixed $path): ?string
    {
        return is_string($path) && preg_match('#^platform/branding/(logo-light|logo-dark|favicon)-[a-z0-9]{12}\.(png|jpg|ico)$#', $path) === 1 ? $path : null;
    }

    private function typeOf(string $path): string
    {
        return match (pathinfo($path, PATHINFO_EXTENSION)) {
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'ico' => 'image/x-icon',
            default => 'application/octet-stream',
        };
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $other
     * @return array<string, mixed>
     */
    private function changes(array $from, array $other): array
    {
        $changes = [];
        foreach ($from as $section => $values) {
            foreach ((array) $values as $key => $value) {
                if (($other[$section][$key] ?? null) !== $value) {
                    $changes[$section][$key] = $value;
                }
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(string $action, ?array $before, ?array $after, Actor $actor): void
    {
        $this->audit->record(new AuditEvent(
            action: $action,
            category: AuditCategory::Config,
            actor: $actor,
            targetType: PlatformSetting::class,
            targetId: 'branding',
            targetLabel: 'Platform branding',
            before: $before,
            after: $after,
        ));
    }
}
