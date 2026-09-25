<?php

declare(strict_types=1);

namespace App\Livewire\Center\Queue;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Livewire\Center\Queue\Concerns\RunsDeskActions;
use App\Modules\Queue\Application\Actions\SaveDisplay;
use App\Modules\Queue\Application\DisplayLanguages;
use App\Modules\Queue\Application\QueueSetupQuery;
use App\Modules\Queue\Domain\Models\QueueDisplay;
use App\Modules\ServiceJourney\Application\VisitOptions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The waiting-room screens. docs/17-QUEUE.md §§9, 16.
 *
 * Three scopes and only three — branch-wide, one department, one desk — and a
 * language, a recent-call count, sound and voice. Never markup: a display is
 * a guest page (ADR-038).
 *
 * The screen's address carries its opaque public key. Open it on the
 * television; if the link leaks, ROTATE it and the old address answers 404
 * immediately. Voice is the `queue_voice` add-on and the screen itself is
 * `queue_display`; without them the controls say so instead of pretending.
 *
 * A screen may also rotate its labels through the CENTER's languages (only
 * those — `SaveDisplay` refuses any other) and play promotional media, which
 * is its own panel (`DisplayMedia`). "Preview" opens the real screen page for
 * a signed-in manager, in any of its languages (docs/17-QUEUE.md §9).
 */
final class Displays extends Component
{
    use RunsDeskActions;

    /** '' (closed), 'new', or the uuid being edited. */
    public string $editing = '';

    public string $branch = '';

    public string $name = '';

    /** branch | department | service_point */
    public string $scope = 'branch';

    public string $department = '';

    public string $point = '';

    public string $locale = '';

    public int $recent = 5;

    public bool $sound = true;

    public bool $voice = true;

    /** @var list<string> */
    public array $voiceLocales = [];

    public bool $active = true;

    /**
     * Cycle the screen's labels through several of the center's languages.
     * Not `$rotate`: that is the action renewing the screen's link, and in the
     * browser a property shadows a method of the same name on `$wire`.
     */
    public bool $languageRotation = false;

    /** @var list<string> */
    public array $rotationLocales = [];

    /** Null while the field is cleared; required only while rotation is on. */
    public ?int $rotationSeconds = 10;

    /** The screen whose promotional media panel is open ('' none). */
    public string $mediaFor = '';

    public function mount(): void
    {
        // The same permission SaveDisplay requires; the list is theirs.
        abort_unless($this->viewer()->hasPermission(Permission::QueueDisplayManage), 403);
    }

    public function create(): void
    {
        $this->resetForm();
        $this->editing = 'new';
        $this->branch = app(VisitOptions::class)->branches($this->viewer())[0]['uuid'] ?? '';
        $centerLocales = app(TenantLocales::class);
        // The manager's own language when the center publishes in it;
        // otherwise the center's language decides.
        $this->locale = $centerLocales->isEnabled(app()->getLocale()) ? app()->getLocale() : '';
        $this->voiceLocales = [$this->locale === '' ? $centerLocales->default() : $this->locale];
        $this->rotationLocales = $centerLocales->enabled();
    }

    public function edit(string $uuid, QueueSetupQuery $setup): void
    {
        $this->resetForm();

        $this->attempt(function () use ($uuid, $setup): void {
            $display = $setup->display($this->viewer(), $uuid);
            $display->loadMissing(['branch', 'department', 'servicePoint']);

            $this->editing = $display->uuid;
            $this->branch = (string) $display->branch?->uuid;
            $this->name = $display->name;
            $this->scope = $display->service_point_id !== null ? 'service_point' : ($display->department_id !== null ? 'department' : 'branch');
            $this->department = (string) $display->department?->uuid;
            $this->point = (string) $display->servicePoint?->uuid;
            $this->locale = (string) $display->locale;
            $this->recent = $display->recentLimit();
            $this->sound = $display->sound_enabled;
            $this->voice = $display->voice_enabled;
            $this->voiceLocales = $display->voiceLocales();
            $this->active = $display->is_active;
            $this->languageRotation = $display->rotation_enabled;
            $this->rotationLocales = $display->rotationLocales() === [] ? app(TenantLocales::class)->enabled() : $display->rotationLocales();
            $this->rotationSeconds = $display->rotationSeconds();
        });
    }

    public function closePanel(): void
    {
        $this->resetForm();
    }

    public function save(SaveDisplay $save, QueueSetupQuery $setup, LanguageRegistry $languages, TenantLocales $centerLocales): void
    {
        $content = $centerLocales->enabled();
        // The rotation controls exist only where the center has something to
        // rotate between; when they are not shown, the screen's stored
        // rotation is left exactly as it is.
        $rotationShown = count($content) > 1;
        // The checkboxes offer only the center's languages.
        $this->rotationLocales = array_values(array_intersect($this->rotationLocales, $content));
        // The one value saved as "rotate": what the manager asked for, where it
        // could be asked. Which languages actually cycle is
        // DisplayLanguages::cycle()'s to decide on every read.
        $languageRotationEnabled = $rotationShown && $this->languageRotation;

        $this->validate([
            'name' => ['required', 'string', 'max:190'],
            'scope' => ['required', 'in:branch,department,service_point'],
            'department' => [$this->scope === 'department' ? 'required' : 'nullable', 'string'],
            'point' => [$this->scope === 'service_point' ? 'required' : 'nullable', 'string'],
            'locale' => ['nullable', 'in:'.implode(',', $languages->supported())],
            'recent' => ['integer', 'min:1', 'max:'.QueueDisplay::MAX_RECENT],
            'voiceLocales.*' => ['in:'.implode(',', $languages->supported())],
            'rotationLocales' => [$languageRotationEnabled ? 'min:2' : 'nullable', 'array'],
            'rotationLocales.*' => ['in:'.implode(',', $content)],
            // Only checked while it is shown and used; otherwise the stored
            // interval is kept, so a hidden field can never block a save.
            'rotationSeconds' => $languageRotationEnabled
                ? ['required', 'integer', 'min:'.QueueDisplay::MIN_ROTATION_SECONDS, 'max:'.QueueDisplay::MAX_ROTATION_SECONDS]
                : ['nullable'],
        ], [
            'rotationLocales.min' => __('manager_queue.errors.rotation_languages'),
        ], [
            'name' => __('manager_queue.setup.display_name'),
            'department' => __('manager_queue.setup.department'),
            'point' => __('manager_queue.setup.desk'),
            'recent' => __('manager_queue.setup.recent'),
            'rotationLocales' => __('manager_queue.setup.rotation_languages'),
            'rotationSeconds' => __('manager_queue.setup.rotation_seconds'),
        ]);

        $this->attempt(function () use ($save, $setup, $content, $rotationShown, $languageRotationEnabled): void {
            $display = $this->editing === 'new' ? null : $setup->display($this->viewer(), $this->editing);

            $rotation = [];
            if ($rotationShown) {
                $rotation = [
                    'rotation_enabled' => $languageRotationEnabled,
                    // A language the center switched off stays on the screen's
                    // own list (disabling never deletes); the checkboxes held
                    // only the ones switched on.
                    'rotation_locales' => array_values(array_unique([
                        ...$this->rotationLocales,
                        ...array_diff($display?->rotationLocales() ?? [], $content),
                    ])),
                ];
                if ($languageRotationEnabled) {
                    $rotation['rotation_seconds'] = $this->rotationSeconds;
                }
            }

            $save([
                'branch' => $this->branch,
                'name' => $this->name,
                'department' => $this->scope === 'department' ? $this->department : null,
                'service_point' => $this->scope === 'service_point' ? $this->point : null,
                'locale' => $this->locale === '' ? null : $this->locale,
                'recent_calls_limit' => $this->recent,
                'sound_enabled' => $this->sound,
                'voice_enabled' => $this->voice,
                'voice_locales' => $this->voiceLocales,
                'is_active' => $this->active,
                ...$rotation,
            ], $this->viewer(), $display);

            $this->resetForm();
            $this->succeeded(__('manager_queue.setup.display_saved'));
        });
    }

    public function openMedia(string $uuid, QueueSetupQuery $setup): void
    {
        $this->resetForm();

        $this->attempt(function () use ($uuid, $setup): void {
            $this->mediaFor = $setup->display($this->viewer(), $uuid)->uuid;
        });
    }

    #[On('display-media-closed')]
    public function closeMedia(): void
    {
        $this->mediaFor = '';
    }

    /** The media panel changed a count or a switch this list shows. */
    #[On('display-media-changed')]
    public function mediaChanged(): void {}

    public function rotate(string $uuid, SaveDisplay $save, QueueSetupQuery $setup): void
    {
        $this->attempt(function () use ($uuid, $save, $setup): void {
            $save->rotate($setup->display($this->viewer(), $uuid), $this->viewer());

            $this->succeeded(__('manager_queue.setup.rotated'));
        });
    }

    public function render(QueueSetupQuery $setup, VisitOptions $options, Entitlements $entitlements, LanguageRegistry $languages, TenantLocales $centerLocales, DisplayLanguages $displayLanguages): View
    {
        $viewer = $this->viewer();
        $screens = $entitlements->enabled('queue_display');
        $content = $centerLocales->enabled();
        $requested = app()->getLocale();

        // The preview checks a screen in EVERY language the center publishes
        // in — not only the ones it cycles — and the preview controller pins
        // only those (DisplayLanguages::pinnable()).
        $previewLanguages = array_map(static fn (string $code): array => [
            'code' => $code,
            'label' => $languages->shortLabel($code),
        ], $content);

        $displays = array_map(static function (array $display) use ($screens, $languages, $displayLanguages, $content, $previewLanguages, $requested): array {
            // The address a manager opens ON the television. Only when the
            // center owns screens: without `queue_display` it answers 404.
            $display['url'] = $screens ? route('queue.display', ['display' => $display['public_key']]) : null;
            // Only a language the center still publishes in: a switched-off one
            // is not what the screen starts in (DisplayLanguages::start()).
            $display['locale_label'] = is_string($display['locale']) && in_array($display['locale'], $content, true) ? $languages->shortLabel($display['locale']) : null;

            // What the screen actually cycles — the same answer the screen gets.
            $cycle = $displayLanguages->cycle((bool) $display['rotation'], $display['rotation_locales']);
            $display['rotation_labels'] = array_map(static fn (string $code): string => $languages->shortLabel($code), $cycle);
            $display['preview'] = $screens ? [
                'url' => route('center.queue.displays.preview', ['uuid' => $display['uuid']]),
                'name' => $display['name'],
                'languages' => $previewLanguages,
                // Unpinned, the preview rotates like the screen, or shows the
                // language the screen starts in.
                'rotates' => $cycle !== [],
                'start' => $displayLanguages->start(is_string($display['locale']) ? $display['locale'] : null, $cycle, $requested),
            ] : null;
            unset($display['public_key']);

            return $display;
        }, $setup->displays($viewer));

        $points = array_values(array_filter(
            $setup->servicePoints($viewer),
            fn (array $p): bool => $p['branch_uuid'] === $this->branch && $p['is_active'],
        ));

        return view('livewire.center.queue.displays', [
            'displays' => $displays,
            'branches' => $options->branches($viewer),
            'departments' => $options->departments(),
            'points' => $points,
            'languages' => array_map(static fn (string $code): array => [
                'code' => $code,
                'label' => $languages->shortLabel($code),
                'name' => $languages->nativeName($code),
            ], $languages->supported()),
            // The display language and the rotation offer only what the center
            // publishes in; a stored choice the center later switched off stays
            // listed, so saving never silently changes it.
            'contentLanguages' => array_map(static fn (string $code): array => [
                'code' => $code,
                'label' => $languages->shortLabel($code),
                'name' => $languages->nativeName($code),
            ], array_values(array_unique(array_merge($content, $this->locale !== '' && $languages->supports($this->locale) ? [$this->locale] : [])))),
            'rotationMin' => QueueDisplay::MIN_ROTATION_SECONDS,
            'rotationMax' => QueueDisplay::MAX_ROTATION_SECONDS,
            'rotationChoices' => array_map(static fn (string $code): array => [
                'code' => $code,
                'label' => $languages->shortLabel($code),
                'name' => $languages->nativeName($code),
            ], $content),
            'screens' => $screens,
            'voiceEntitled' => $entitlements->enabled('queue_voice'),
            'entitled' => $entitlements->enabled('queue_management'),
        ]);
    }

    private function resetForm(): void
    {
        $this->reset(['editing', 'branch', 'name', 'scope', 'department', 'point', 'locale', 'recent', 'sound', 'voice', 'voiceLocales', 'active', 'languageRotation', 'rotationLocales', 'rotationSeconds', 'mediaFor']);
        $this->resetErrorBag();
    }
}
