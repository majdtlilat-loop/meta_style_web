<?php

declare(strict_types=1);

namespace App\Livewire\Center\Queue;

use App\Kernel\Localization\TenantLocales;
use App\Kernel\Media\MediaOwner;
use App\Livewire\Center\Queue\Concerns\RunsDeskActions;
use App\Modules\Queue\Application\Actions\ManageDisplayMedia;
use App\Modules\Queue\Application\QueueSetupQuery;
use App\Modules\Queue\Domain\Models\QueueDisplay;
use App\Modules\Queue\Domain\Models\QueueDisplayMedia;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * One screen's promotional images and videos. docs/17-QUEUE.md §9.
 *
 * Every write is `ManageDisplayMedia` — the media kernel validates the bytes
 * (JPEG, PNG, WebP; MP4, WebM; never SVG or markup) and stores the file; the
 * Action checks the plan, `queue.display.manage` and the screen's branch. The
 * screen is re-resolved through `QueueSetupQuery` on every call; a uuid from
 * the page is never trusted. Ordering sends one intent at a time.
 */
final class DisplayMedia extends Component
{
    use RunsDeskActions;
    use WithFileUploads;

    /** The screen's uuid. */
    #[Locked]
    public string $display = '';

    /** @var mixed One upload or several. */
    public $files = null;

    public bool $enabled = false;

    /** Null while the field is cleared; required only when it is saved. */
    public ?int $slideSeconds = 8;

    /** The item whose texts are open for editing ('' none). */
    public string $editing = '';

    /** @var array<string, string> */
    public array $caption = [];

    /** @var array<string, string> */
    public array $alt = [];

    public function mount(string $display, QueueSetupQuery $setup): void
    {
        $screen = $setup->display($this->viewer(), $display);

        $this->display = $screen->uuid;
        $this->enabled = $screen->promo_enabled;
        $this->slideSeconds = $screen->slideSeconds();
    }

    public function updatedFiles(ManageDisplayMedia $media, QueueSetupQuery $setup): void
    {
        $files = array_values(array_filter(
            is_array($this->files) ? $this->files : [$this->files],
            static fn (mixed $file): bool => $file instanceof UploadedFile,
        ));
        $this->files = null;
        $this->resetErrorBag();

        $room = MediaOwner::QueueDisplay->maxItems() - count($setup->media($this->viewer(), $this->display));

        if (count($files) > $room) {
            $this->addError('files', trans_choice('media_upload.item_limit', MediaOwner::QueueDisplay->maxItems(), ['count' => MediaOwner::QueueDisplay->maxItems()]));

            return;
        }

        $added = 0;
        $this->notice = '';

        foreach ($files as $file) {
            try {
                $media->add($this->screen($setup), $file, $this->viewer());
                $added++;
            } catch (ValidationException $invalid) {
                // The media kernel's own words, already in the manager's
                // language (media_upload.*): which file, and why.
                $this->addError('files', (string) collect($invalid->errors())->flatten()->first());
            } catch (Throwable $failure) {
                if (! OperationalFailure::handles($failure)) {
                    throw $failure;
                }

                $this->notice = OperationalFailure::message($failure);
                $this->noticeTone = 'danger';

                break;
            }
        }

        if ($added > 0) {
            $this->succeeded(trans_choice('manager_queue.media.added', $added, ['count' => $added]));
            $this->dispatch('display-media-changed');
        }
    }

    /*
     * The switch saves on its own, with the interval the screen already has:
     * a half-typed number in the other field must never leave the switch
     * showing a state that was not saved. Refused, it shows the stored one.
     */
    public function updatedEnabled(ManageDisplayMedia $media, QueueSetupQuery $setup): void
    {
        $saved = $this->attempt(function () use ($media, $setup): void {
            $screen = $media->configure($this->screen($setup), $this->enabled, null, $this->viewer());

            $this->enabled = $screen->promo_enabled;
            $this->dispatch('display-media-changed');
        });

        if (! $saved) {
            $this->enabled = $this->screen($setup)->promo_enabled;
        }
    }

    public function updatedSlideSeconds(ManageDisplayMedia $media, QueueSetupQuery $setup): void
    {
        $this->configure($media, $setup);
    }

    public function toggleItem(string $uuid, ManageDisplayMedia $media, QueueSetupQuery $setup): void
    {
        $this->attempt(function () use ($uuid, $media, $setup): void {
            $row = $setup->mediaItem($this->viewer(), $this->display, $uuid);
            $media->update($row, ['enabled' => ! $row->is_enabled], $this->viewer());
            $this->dispatch('display-media-changed');
        });
    }

    public function editItem(string $uuid, QueueSetupQuery $setup): void
    {
        $this->resetErrorBag();

        $this->attempt(function () use ($uuid, $setup): void {
            $row = $setup->mediaItem($this->viewer(), $this->display, $uuid);
            $row->loadMissing('mediaItem');

            $this->editing = $row->uuid;
            $this->caption = $row->caption?->all() ?? [];
            $this->alt = $row->isVideo() ? [] : ($row->mediaItem?->alt_text?->all() ?? []);
        });
    }

    public function cancelItem(): void
    {
        $this->reset(['editing', 'caption', 'alt']);
        $this->resetErrorBag();
    }

    public function saveItem(ManageDisplayMedia $media, QueueSetupQuery $setup, TenantLocales $locales): void
    {
        $rules = [];

        foreach ($locales->enabled() as $locale) {
            $rules['caption.'.$locale] = ['nullable', 'string', 'max:'.QueueDisplayMedia::CAPTION_MAX];
            $rules['alt.'.$locale] = ['nullable', 'string', 'max:'.QueueDisplayMedia::ALT_MAX];
        }

        $this->validate($rules, [], $this->attributeNames($locales));

        $this->attempt(function () use ($media, $setup): void {
            $row = $setup->mediaItem($this->viewer(), $this->display, $this->editing);
            $media->update($row, ['caption' => $this->caption, 'alt' => $this->alt], $this->viewer());

            $this->reset(['editing', 'caption', 'alt']);
            $this->succeeded(__('manager_queue.media.saved'));
            $this->dispatch('display-media-changed');
        });
    }

    /** Drag and drop, and the handle's arrow keys (x-sortable). */
    public function moveItem(string $uuid, int $toIndex, ManageDisplayMedia $media, QueueSetupQuery $setup): void
    {
        $this->attempt(function () use ($uuid, $toIndex, $media, $setup): void {
            $media->move($setup->mediaItem($this->viewer(), $this->display, $uuid), $toIndex, $this->viewer());
        });
    }

    /** The visible Move up / Move down buttons. */
    public function moveItemBy(string $uuid, int $delta, ManageDisplayMedia $media, QueueSetupQuery $setup): void
    {
        $this->attempt(function () use ($uuid, $delta, $media, $setup): void {
            $media->step($setup->mediaItem($this->viewer(), $this->display, $uuid), $delta, $this->viewer());
        });
    }

    public function removeItem(string $uuid, ManageDisplayMedia $media, QueueSetupQuery $setup): void
    {
        $this->attempt(function () use ($uuid, $media, $setup): void {
            $media->remove($setup->mediaItem($this->viewer(), $this->display, $uuid), $this->viewer());

            if ($this->editing === $uuid) {
                $this->reset(['editing', 'caption', 'alt']);
            }

            $this->succeeded(__('manager_queue.media.removed'));
            $this->dispatch('display-media-changed');
        });
    }

    public function closeMedia(): void
    {
        $this->dispatch('display-media-closed');
    }

    public function render(QueueSetupQuery $setup, TenantLocales $locales): View
    {
        $screen = $this->screen($setup);
        $items = $setup->media($this->viewer(), $this->display);
        $max = MediaOwner::QueueDisplay->maxItems();
        $caption = ['name' => 'caption', 'label' => __('manager_queue.media.caption'), 'max' => QueueDisplayMedia::CAPTION_MAX, 'counter' => true];

        return view('livewire.center.queue.display-media', [
            'screenName' => $screen->name,
            'items' => array_map(function (array $item): array {
                $item['size'] = is_int($item['size_bytes']) ? $this->size($item['size_bytes']) : null;

                return $item;
            }, $items),
            'max' => $max,
            'full' => count($items) >= $max,
            'locales' => $locales->enabled(),
            'primary' => $locales->default(),
            'imageMb' => intdiv((int) config('metastyle.catalog.media.max_bytes', 5242880), 1048576),
            'videoMb' => intdiv((int) config('site.media.video_max_kb', 12288), 1024),
            // A video's words are its caption; only an image has alt text.
            'textFields' => [
                'image' => [$caption, ['name' => 'alt', 'label' => __('manager_queue.media.alt'), 'max' => QueueDisplayMedia::ALT_MAX]],
                'video' => [$caption],
            ],
            'minSeconds' => QueueDisplay::MIN_SLIDE_SECONDS,
            'maxSeconds' => QueueDisplay::MAX_SLIDE_SECONDS,
        ]);
    }

    /** Kilobytes under a megabyte: a short clip must not read "0.0 MB". */
    private function size(int $bytes): string
    {
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1).' MB'
            : number_format(max(1, (int) round($bytes / 1024))).' KB';
    }

    private function configure(ManageDisplayMedia $media, QueueSetupQuery $setup): void
    {
        $this->validate(['slideSeconds' => ['required', 'integer', 'min:'.QueueDisplay::MIN_SLIDE_SECONDS, 'max:'.QueueDisplay::MAX_SLIDE_SECONDS]], [], [
            'slideSeconds' => __('manager_queue.media.slide_seconds'),
        ]);

        $this->attempt(function () use ($media, $setup): void {
            $screen = $media->configure($this->screen($setup), $this->enabled, $this->slideSeconds, $this->viewer());

            $this->enabled = $screen->promo_enabled;
            $this->slideSeconds = $screen->slideSeconds();
            $this->dispatch('display-media-changed');
        });
    }

    private function screen(QueueSetupQuery $setup): QueueDisplay
    {
        return $setup->display($this->viewer(), $this->display);
    }

    /**
     * @return array<string, string>
     */
    private function attributeNames(TenantLocales $locales): array
    {
        $names = [];

        foreach ($locales->enabled() as $locale) {
            $names['caption.'.$locale] = __('manager_queue.media.caption');
            $names['alt.'.$locale] = __('manager_queue.media.alt');
        }

        return $names;
    }
}
