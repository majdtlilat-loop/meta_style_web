<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Settings;

use App\Kernel\Audit\Actor;
use App\Kernel\Platform\Branding\PlatformBranding;
use App\Kernel\Platform\Branding\PlatformTheme;
use App\Livewire\Sadmin\Concerns\AuthorizesPlatform;
use DomainException;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Settings → Branding: the platform logo (light and dark), the favicon and the
 * platform colour theme. One authoritative source (PlatformBranding) that the
 * corporate site, the Super Admin, its sign-in pages and SaaS documents read.
 * Center pages never do.
 *
 * Colours are structured values edited in a form and previewed live before
 * saving; nothing here accepts CSS.
 */
#[Layout('layouts.superadmin.app')]
final class Branding extends Component
{
    use AuthorizesPlatform;
    use WithFileUploads;

    /** @var array{light: array<string, string>, dark: array<string, string>, gradients: array<string, array{enabled: bool, from: string, via: string, to: string, angle: int}>} */
    public array $theme;

    public bool $showName = true;

    /** Which theme the colour form edits. */
    public string $mode = 'light';

    /** @var TemporaryUploadedFile|null */
    public $logoLight;

    /** @var TemporaryUploadedFile|null */
    public $logoDark;

    /** @var TemporaryUploadedFile|null */
    public $favicon;

    /** `reset`, or `remove:<slot>`. */
    public ?string $confirm = null;

    public function mount(PlatformBranding $branding): void
    {
        $this->requirePlatformPermission('platform.branding.manage');
        $this->theme = $branding->theme();
        $this->showName = $branding->showsName();
    }

    public function setMode(string $mode): void
    {
        $this->mode = $mode === 'dark' ? 'dark' : 'light';
    }

    public function updatedLogoLight(PlatformBranding $branding): void
    {
        $this->upload('logoLight', fn (UploadedFile $file, Actor $actor) => $branding->uploadLogo('light', $file, $actor), 'image|mimes:png,jpg,jpeg|max:'.PlatformBranding::LOGO_MAX_KB);
    }

    public function updatedLogoDark(PlatformBranding $branding): void
    {
        $this->upload('logoDark', fn (UploadedFile $file, Actor $actor) => $branding->uploadLogo('dark', $file, $actor), 'image|mimes:png,jpg,jpeg|max:'.PlatformBranding::LOGO_MAX_KB);
    }

    public function updatedFavicon(PlatformBranding $branding): void
    {
        $this->upload('favicon', fn (UploadedFile $file, Actor $actor) => $branding->uploadFavicon($file, $actor), 'file|mimes:png,ico|max:'.PlatformBranding::FAVICON_MAX_KB);
    }

    public function askRemove(string $slot): void
    {
        $this->requirePlatformPermission('platform.branding.manage');
        $this->confirm = in_array($slot, ['logo_light', 'logo_dark', 'favicon'], true) ? 'remove:'.$slot : null;
    }

    public function askReset(): void
    {
        $this->requirePlatformPermission('platform.branding.manage');
        $this->confirm = 'reset';
    }

    public function cancel(): void
    {
        $this->confirm = null;
    }

    public function confirmAction(PlatformBranding $branding): void
    {
        $user = $this->requirePlatformPermission('platform.branding.manage');
        $actor = Actor::platform($user);
        $action = (string) $this->confirm;
        $this->confirm = null;

        if ($action === 'reset') {
            $branding->resetTheme($actor);
            $this->theme = $branding->theme();
            session()->flash('notice', __('platform_branding.reset_done'));

            return;
        }
        $slot = str_starts_with($action, 'remove:') ? mb_substr($action, 7) : '';
        match ($slot) {
            'logo_light' => $branding->removeLogo('light', $actor),
            'logo_dark' => $branding->removeLogo('dark', $actor),
            'favicon' => $branding->removeFavicon($actor),
            default => null,
        };
        if ($slot !== '') {
            session()->flash('notice', __('platform_branding.removed'));
        }
    }

    public function saveIdentity(PlatformBranding $branding): void
    {
        $user = $this->requirePlatformPermission('platform.branding.manage');
        $branding->setShowName($this->showName, Actor::platform($user));
        session()->flash('notice', __('platform_settings.saved'));
    }

    /** Restores one colour of the edited theme to its Rose Gold Luxe value (not saved yet). */
    public function defaultColor(string $key): void
    {
        if (in_array($key, PlatformTheme::COLORS, true)) {
            $this->theme[$this->mode][$key] = PlatformTheme::DEFAULT_COLORS[$this->mode][$key];
        }
    }

    public function saveTheme(PlatformBranding $branding): void
    {
        $user = $this->requirePlatformPermission('platform.branding.manage');
        try {
            $branding->saveTheme($this->normalizedInput(), Actor::platform($user));
        } catch (DomainException $exception) {
            $this->addError('theme', $exception->getMessage());

            return;
        }
        $this->theme = $branding->theme();
        session()->flash('notice', __('platform_branding.theme_saved'));
    }

    public function render(PlatformBranding $branding): mixed
    {
        $this->requirePlatformPermission('platform.branding.manage');
        $identity = $branding->identity();

        // The preview uses what is in the form right now; an invalid value
        // shows the saved theme instead of breaking the preview.
        try {
            $draft = PlatformTheme::normalize($this->normalizedInput());
            $valid = true;
        } catch (DomainException) {
            $draft = $branding->theme();
            $valid = false;
        }

        return view('livewire.sadmin.settings.branding', [
            'logos' => ['light' => $branding->logo('light'), 'dark' => $branding->logo('dark')],
            'custom' => ['logo_light' => $identity['logo_light'] !== null, 'logo_dark' => $identity['logo_dark'] !== null, 'favicon' => $identity['favicon'] !== null],
            'faviconUrl' => $branding->favicon()['url'],
            'preview' => ['light' => PlatformTheme::inlineStyle($draft, 'light'), 'dark' => PlatformTheme::inlineStyle($draft, 'dark')],
            'warnings' => $valid ? PlatformTheme::contrastWarnings($draft) : [],
            'valid' => $valid,
            'dirty' => $valid && $draft !== $branding->theme(),
            'isDefault' => $branding->theme() === PlatformTheme::defaults(),
        ])->title(__('platform_settings.tabs.branding'));
    }

    /**
     * Colour inputs arrive as typed; lower-case them so `#B76E79` is accepted.
     *
     * @return array<string, mixed>
     */
    private function normalizedInput(): array
    {
        $input = $this->theme;
        foreach (PlatformTheme::MODES as $mode) {
            foreach (PlatformTheme::COLORS as $key) {
                $input[$mode][$key] = mb_strtolower(trim((string) ($input[$mode][$key] ?? '')));
            }
        }
        foreach (PlatformTheme::GRADIENTS as $key) {
            foreach (['from', 'via', 'to'] as $stop) {
                $input['gradients'][$key][$stop] = mb_strtolower(trim((string) ($input['gradients'][$key][$stop] ?? '')));
            }
        }

        return $input;
    }

    /**
     * @param  callable(UploadedFile, Actor): void  $store
     */
    private function upload(string $property, callable $store, string $rule): void
    {
        $user = $this->requirePlatformPermission('platform.branding.manage');
        $this->validate([$property => $rule], [], [$property => __('platform_branding.fields.'.$property)]);
        $file = $this->{$property};
        try {
            if ($file instanceof UploadedFile) {
                $store($file, Actor::platform($user));
                session()->flash('notice', __('platform_branding.uploaded'));
            }
        } catch (DomainException $exception) {
            $this->addError($property, $exception->getMessage());
        } finally {
            $this->reset($property);
        }
    }
}
