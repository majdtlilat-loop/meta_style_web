<?php

declare(strict_types=1);

namespace App\Livewire\Center\Appearance;

use App\Kernel\Identity\Models\User;
use App\Kernel\Media\MediaOwner;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\CenterSite\Application\Actions\ManageBrandAssets;
use App\Modules\CenterSite\Application\Actions\UpdateCenterBrand;
use App\Modules\CenterSite\Application\BrandSettings;
use App\Modules\CenterSite\Application\SiteAccess;
use App\Modules\CenterSite\Application\SiteMedia;
use App\Modules\CenterSite\Domain\CenterBrand;
use App\Modules\CenterSite\Domain\CenterTheme;
use App\Modules\CenterSite\Domain\InvalidSiteContent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Manager → Appearance → Brand: the center's OWN logo, favicon, colours,
 * gradients and shape, previewed live before saving.
 *
 * Separate from Meta Style's platform branding. Nothing here accepts CSS: the
 * form edits structured values, UpdateCenterBrand validates and audits them,
 * and low contrast is reported, never corrected behind the owner's back.
 */
#[Layout('components.layouts.app')]
final class Brand extends Component
{
    use WithFileUploads;

    /** @var array<string, mixed> the design values being edited (no asset slots) */
    public array $brand = [];

    /** Which palette the colour form edits. */
    public string $mode = 'light';

    /** @var TemporaryUploadedFile|null */
    public $logoLight;

    /** @var TemporaryUploadedFile|null */
    public $logoDark;

    /** @var TemporaryUploadedFile|null */
    public $favicon;

    /** `reset`, or `remove:<slot>`. */
    public ?string $confirm = null;

    public function mount(BrandSettings $settings): void
    {
        abort_unless(SiteAccess::canView($this->actor()), 403);
        $this->brand = CenterBrand::design($settings->get());
    }

    public function setMode(string $mode): void
    {
        $this->mode = $mode === 'dark' ? 'dark' : 'light';
    }

    /** Restores one colour of the edited palette to its default (not saved yet). */
    public function defaultColor(string $key): void
    {
        if (in_array($key, CenterBrand::COLORS, true)) {
            $this->brand[$this->mode][$key] = CenterBrand::DEFAULT_COLORS[$this->mode][$key];
        }
    }

    public function save(UpdateCenterBrand $update): void
    {
        try {
            $this->brand = CenterBrand::design($update($this->input(), $this->actor()));
        } catch (InvalidSiteContent $e) {
            $this->addError('brand.'.$e->path, $e->getMessage());

            return;
        } catch (AuthorizationException $e) {
            session()->flash('notice-error', $e->getMessage());

            return;
        }
        session()->flash('notice', __('manager_site.brand.saved'));
    }

    public function updatedLogoLight(ManageBrandAssets $assets): void
    {
        $this->upload('logoLight', 'logo_light', $assets);
    }

    public function updatedLogoDark(ManageBrandAssets $assets): void
    {
        $this->upload('logoDark', 'logo_dark', $assets);
    }

    public function updatedFavicon(ManageBrandAssets $assets): void
    {
        $this->upload('favicon', 'favicon', $assets);
    }

    public function askRemove(string $slot): void
    {
        $this->confirm = in_array($slot, CenterBrand::ASSETS, true) ? 'remove:'.$slot : null;
    }

    public function askReset(): void
    {
        $this->confirm = 'reset';
    }

    public function cancel(): void
    {
        $this->confirm = null;
    }

    public function confirmAction(UpdateCenterBrand $update, ManageBrandAssets $assets): void
    {
        $action = (string) $this->confirm;
        $this->confirm = null;

        try {
            if ($action === 'reset') {
                $this->brand = CenterBrand::design($update->reset($this->actor()));
                session()->flash('notice', __('manager_site.brand.reset_done'));
            } elseif (str_starts_with($action, 'remove:')) {
                $assets->remove(mb_substr($action, 7), $this->actor());
                session()->flash('notice', __('manager_site.brand.removed'));
            }
        } catch (AuthorizationException|InvalidSiteContent $e) {
            session()->flash('notice-error', $e->getMessage());
        }
    }

    public function render(BrandSettings $settings, SiteMedia $media, TenantContext $tenants): mixed
    {
        $user = $this->actor();
        abort_unless(SiteAccess::canView($user), 403);

        $saved = $settings->get();
        $assets = $media->resolve([$saved['logo_light'], $saved['logo_dark'], $saved['favicon']], MediaOwner::Brand);

        // The preview shows what is in the form right now; an invalid value
        // shows the saved brand instead of a broken preview.
        try {
            $draft = CenterBrand::normalize($this->input() + $saved);
            $valid = true;
        } catch (InvalidSiteContent) {
            $draft = $saved;
            $valid = false;
        }

        return view('livewire.center.appearance.brand', [
            'canManage' => SiteAccess::canManage($user),
            'assets' => [
                'logo_light' => $assets[$saved['logo_light']]['url'] ?? null,
                'logo_dark' => $assets[$saved['logo_dark']]['url'] ?? null,
                'favicon' => $assets[$saved['favicon']]['url'] ?? null,
            ],
            'preview' => ['light' => CenterTheme::inlineStyle($draft, 'light'), 'dark' => CenterTheme::inlineStyle($draft, 'dark')],
            'swatches' => [
                'brand' => CenterTheme::gradientCss($draft['gradients']['brand']),
                'hero' => CenterTheme::gradientCss($draft['gradients']['hero']),
                'accent' => CenterTheme::gradientCss($draft['gradients']['accent']),
            ],
            'warnings' => $valid ? CenterTheme::contrastWarnings($draft) : [],
            'valid' => $valid,
            'dirty' => $valid && CenterBrand::design($draft) !== CenterBrand::design($saved),
            'isDefault' => CenterBrand::design($saved) === CenterBrand::design(CenterBrand::defaults()),
            'centerName' => (string) ($tenants->tenant()->name ?? ''),
            'defaults' => CenterBrand::DEFAULT_COLORS,
            'catalog' => [
                'colors' => CenterBrand::COLORS,
                'gradients' => CenterBrand::GRADIENTS,
                'angles' => CenterBrand::ANGLES,
                'schemes' => CenterBrand::SCHEMES,
                'radii' => CenterBrand::RADII,
                'buttons' => CenterBrand::BUTTON_STYLES,
                'cards' => CenterBrand::CARD_STYLES,
            ],
            'limits' => ['logo_kb' => (int) config('site.media.logo_max_kb', 1024), 'favicon_kb' => (int) config('site.media.favicon_max_kb', 256)],
        ])->title(__('manager_site.brand.title'));
    }

    /**
     * Colour inputs arrive as typed; lower-case them so `#8A4B5A` is accepted.
     *
     * @return array<string, mixed>
     */
    private function input(): array
    {
        $input = $this->brand;
        foreach (['light', 'dark'] as $mode) {
            foreach (CenterBrand::COLORS as $key) {
                $input[$mode][$key] = mb_strtolower(trim((string) ($input[$mode][$key] ?? '')));
            }
        }
        foreach (CenterBrand::GRADIENTS as $key) {
            foreach (['from', 'via', 'to'] as $stop) {
                $input['gradients'][$key][$stop] = mb_strtolower(trim((string) ($input['gradients'][$key][$stop] ?? '')));
            }
        }

        return $input;
    }

    private function upload(string $property, string $slot, ManageBrandAssets $assets): void
    {
        $file = $this->{$property};
        try {
            if ($file instanceof UploadedFile) {
                $assets->upload($slot, $file, $this->actor());
                session()->flash('notice', __('manager_site.brand.uploaded'));
            }
        } catch (ValidationException $e) {
            $this->addError($property, (string) collect($e->errors())->flatten()->first());
        } catch (AuthorizationException|InvalidSiteContent $e) {
            $this->addError($property, $e->getMessage());
        } finally {
            $this->reset($property);
        }
    }

    private function actor(): User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : abort(403);
    }
}
