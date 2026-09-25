<?php

declare(strict_types=1);

namespace App\Livewire\Center\Appearance;

use App\Kernel\Identity\Models\User;
use App\Modules\CenterSite\Application\Actions\UploadSiteMedia;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * One image or video slot of the site builder.
 *
 * Choosing a file uploads it through UploadSiteMedia (validated on its bytes,
 * stored in the center's site library) and tells the builder the new uuid;
 * the builder puts it in the draft. In `append` mode (the gallery) every
 * chosen image becomes a new gallery item. Removing is the builder's: it only
 * unreferences the file.
 */
final class SiteMediaSlot extends Component
{
    use WithFileUploads;

    /** The content path this slot fills, e.g. `hero.image`. */
    #[Locked]
    public string $target = '';

    /** `image` or `video`. */
    #[Locked]
    public string $kind = 'image';

    /** Gallery mode: the section id new images are appended to. */
    #[Locked]
    public string $append = '';

    #[Locked]
    public ?string $url = null;

    #[Locked]
    public ?string $poster = null;

    #[Locked]
    public string $label = '';

    #[Locked]
    public bool $canManage = false;

    /**
     * The one file chosen for a single slot. Not `$upload`: `$wire.upload` is
     * Livewire's own upload function, which would shadow the property.
     *
     * @var TemporaryUploadedFile|null
     */
    public $file;

    /** @var array<int, mixed> */
    public $uploads = [];

    public function updatedFile(UploadSiteMedia $store): void
    {
        $file = $this->file;
        try {
            if ($file instanceof UploadedFile) {
                $item = $store($file, $this->kind === 'video' ? 'video' : 'image', $this->actor());
                $this->dispatch('site-media-selected', target: $this->target, uuid: $item->uuid);
            }
        } catch (ValidationException $e) {
            $this->addError('file', (string) collect($e->errors())->flatten()->first());
        } catch (AuthorizationException $e) {
            $this->addError('file', $e->getMessage());
        } finally {
            $this->reset('file');
        }
    }

    public function updatedUploads(UploadSiteMedia $store): void
    {
        $failed = null;
        foreach (array_slice((array) $this->uploads, 0, 12) as $file) {
            try {
                if ($file instanceof UploadedFile && $this->append !== '') {
                    $item = $store($file, 'image', $this->actor());
                    $this->dispatch('site-media-added', section: $this->append, uuid: $item->uuid);
                }
            } catch (ValidationException $e) {
                $failed = (string) collect($e->errors())->flatten()->first();
            } catch (AuthorizationException $e) {
                $failed = $e->getMessage();
            }
        }
        if ($failed !== null) {
            $this->addError('file', $failed);
        }
        $this->reset('uploads');
    }

    public function render(): mixed
    {
        return view('livewire.center.appearance.site-media-slot', [
            'rules' => $this->kind === 'video'
                ? __('manager_site.media.video_rules', ['max' => intdiv((int) config('site.media.video_max_kb', 12288), 1024)])
                : __('manager_site.media.image_rules', ['max' => intdiv((int) config('metastyle.catalog.media.max_bytes', 5242880), 1048576)]),
            'accept' => $this->kind === 'video' ? 'video/mp4,video/webm' : 'image/jpeg,image/png,image/webp',
            'inputId' => 'media-'.substr(md5($this->target.$this->append), 0, 10),
        ]);
    }

    private function actor(): User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : abort(403);
    }
}
