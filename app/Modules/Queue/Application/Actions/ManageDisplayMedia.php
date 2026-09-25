<?php

declare(strict_types=1);

namespace App\Modules\Queue\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Media\Application\ManageMedia;
use App\Kernel\Media\Application\StoreMediaItem;
use App\Kernel\Media\MediaKind;
use App\Kernel\Media\MediaOwner;
use App\Kernel\Media\Models\MediaItem;
use App\Kernel\Storage\MediaCollection;
use App\Modules\Queue\Domain\Exceptions\QueueFailed;
use App\Modules\Queue\Domain\Models\QueueDisplay;
use App\Modules\Queue\Domain\Models\QueueDisplayMedia;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A waiting-room screen's promotional images and videos.
 *
 * ## The media kernel stores; this decides how the screen plays
 *
 * Every file goes through `StoreMediaItem`, validated on its BYTES — JPEG, PNG
 * or WebP images, MP4 or WebM videos, never SVG, never HTML, never anything
 * executable — into the public `branding` collection, owned by THIS screen
 * (`MediaOwner::QueueDisplay`, capped by the kernel). Deleting goes through
 * `ManageMedia`, which removes the row and the file together. Nothing here
 * builds a path or accepts a URL (docs/09-STORAGE.md, ADR-038).
 *
 * ## Authorised like the screen itself
 *
 * `queue_management` (the setup tab) and `queue_display` (screens are what
 * this is for), `queue.display.manage`, and the screen's branch — a manager of
 * one branch never changes another branch's television. The kernel adds
 * `media.upload` for storing and deleting files.
 *
 * ## One intent at a time
 *
 * A move states "this item, that position"; the order is read back from the
 * locked rows and renumbered 0..n-1, never taken from the browser.
 *
 * ## It never touches the queue
 *
 * No ticket, call or announcement is read or written here. A playlist change
 * reaches the screen through the presentation digest on its next poll, and
 * the ticket on screen stays exactly where it was (docs/17-QUEUE.md §9).
 */
final class ManageDisplayMedia
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly TenantLocales $locales,
        private readonly StoreMediaItem $store,
        private readonly ManageMedia $media,
        private readonly Audit $audit,
    ) {}

    /**
     * Promotional media on or off for this screen, and how long an image stays.
     *
     * @throws AuthorizationException
     */
    public function configure(QueueDisplay $display, bool $enabled, ?int $slideSeconds, User $actingUser): QueueDisplay
    {
        $this->authorize($actingUser, $display);

        $display->forceFill([
            'promo_enabled' => $enabled,
            'promo_slide_seconds' => $slideSeconds === null
                ? $display->slideSeconds()
                : max(QueueDisplay::MIN_SLIDE_SECONDS, min(QueueDisplay::MAX_SLIDE_SECONDS, $slideSeconds)),
        ])->save();

        $this->record('queue.display.promotion_updated', $display, $actingUser, [
            'promo_enabled' => $display->promo_enabled,
            'promo_slide_seconds' => $display->promo_slide_seconds,
        ]);

        return $display;
    }

    /**
     * Adds one image or video at the end of the screen's playlist.
     *
     * @throws AuthorizationException
     * @throws ValidationException when the file is not an allowed image or video
     */
    public function add(QueueDisplay $display, UploadedFile $file, User $actingUser): QueueDisplayMedia
    {
        $this->authorize($actingUser, $display);

        // The kind decides which byte-level rules apply; the kernel then checks
        // the bytes themselves. An SVG or an HTML file sniffs as neither video
        // container and fails the image parser.
        $item = ($this->store)(
            $file,
            MediaOwner::QueueDisplay,
            (int) $display->getKey(),
            $actingUser,
            [],
            MediaCollection::Branding,
            $this->kind($file),
        );

        try {
            /** @var QueueDisplayMedia $row */
            $row = DB::connection('tenant')->transaction(function () use ($display, $item): QueueDisplayMedia {
                $this->lockDisplay($display);

                return QueueDisplayMedia::query()->create([
                    'queue_display_id' => $display->getKey(),
                    'media_item_id' => $item->getKey(),
                    'sort_order' => QueueDisplayMedia::query()->where('queue_display_id', $display->getKey())->count(),
                    'is_enabled' => true,
                ]);
            });
        } catch (Throwable $failure) {
            // The file must not outlive a playlist row that never existed.
            $this->media->delete($item, $actingUser);

            throw $failure;
        }

        $this->record('queue.display.media_added', $display, $actingUser, [
            'kind' => str_starts_with($item->mime_type, 'video/') ? 'video' : 'image',
        ]);

        return $row;
    }

    /**
     * Pause or play an item, and its caption / alt text per content language.
     *
     * Only the center's ENABLED languages are written; text stored for a
     * language the center switched off is kept, because disabling never
     * deletes (docs/07-LOCALIZATION.md §4).
     *
     * @param  array{enabled?: bool, caption?: array<string, string|null>, alt?: array<string, string|null>}  $input
     *
     * @throws AuthorizationException
     */
    public function update(QueueDisplayMedia $row, array $input, User $actingUser): QueueDisplayMedia
    {
        $display = $this->displayOf($row);
        $this->authorize($actingUser, $display);

        if (array_key_exists('enabled', $input)) {
            $row->is_enabled = (bool) $input['enabled'];
        }

        if (array_key_exists('caption', $input)) {
            $row->caption = $this->merge($row->caption, $input['caption'], QueueDisplayMedia::CAPTION_MAX);
        }

        $row->save();

        $media = $row->mediaItem;

        if (array_key_exists('alt', $input) && $media instanceof MediaItem && ! $row->isVideo()) {
            $media->forceFill(['alt_text' => $this->merge($media->alt_text, $input['alt'], QueueDisplayMedia::ALT_MAX)])->save();
        }

        $this->record('queue.display.media_updated', $display, $actingUser, [
            'enabled' => $row->is_enabled,
        ]);

        return $row;
    }

    /**
     * @return int the position the item ended at
     *
     * @throws AuthorizationException
     */
    public function move(QueueDisplayMedia $row, int $toIndex, User $actingUser): int
    {
        return $this->reorder($row, $actingUser, static fn (int $from): int => $toIndex);
    }

    /**
     * One step earlier (-1) or later (+1): the button and keyboard path. The
     * current position is read under the lock, never trusted from the page.
     *
     * @throws AuthorizationException
     */
    public function step(QueueDisplayMedia $row, int $delta, User $actingUser): int
    {
        return $this->reorder($row, $actingUser, static fn (int $from): int => $from + ($delta <=> 0));
    }

    /**
     * Removes the item AND its file, then closes the gap in the order.
     *
     * @throws AuthorizationException
     */
    public function remove(QueueDisplayMedia $row, User $actingUser): void
    {
        $display = $this->displayOf($row);
        $this->authorize($actingUser, $display);

        // Asked BEFORE anything is removed, so a refusal leaves both halves.
        if (! $actingUser->hasPermission(Permission::MediaUpload)) {
            throw new AuthorizationException('You may not manage media.');
        }

        $media = $row->mediaItem;

        // Row and file together (ManageMedia): a playlist row without a file
        // plays nothing, a file without a row is storage nobody reclaims. The
        // playlist row follows its media row (FK cascade).
        if ($media instanceof MediaItem) {
            $this->media->delete($media, $actingUser);
        }

        DB::connection('tenant')->transaction(function () use ($display, $row): void {
            $this->lockDisplay($display);
            QueueDisplayMedia::query()->whereKey($row->getKey())->delete();
            $this->renumber($display);
        });

        $this->record('queue.display.media_removed', $display, $actingUser, []);
    }

    /**
     * @param  callable(int): int  $target
     *
     * @throws AuthorizationException
     */
    private function reorder(QueueDisplayMedia $row, User $actingUser, callable $target): int
    {
        $display = $this->displayOf($row);
        $this->authorize($actingUser, $display);

        /** @var array{0: int, 1: int} $move */
        $move = DB::connection('tenant')->transaction(function () use ($display, $row, $target): array {
            $this->lockDisplay($display);

            /** @var list<int> $order */
            $order = QueueDisplayMedia::query()
                ->where('queue_display_id', $display->getKey())
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            $from = array_search((int) $row->getKey(), $order, true);

            if ($from === false) {
                throw QueueFailed::policy('That item is no longer on this screen.');
            }

            $to = max(0, min(count($order) - 1, $target($from)));

            array_splice($order, $from, 1);
            array_splice($order, $to, 0, [(int) $row->getKey()]);

            $this->persistOrder($order);

            return [$from, $to];
        });

        [$from, $to] = $move;

        if ($from !== $to) {
            $this->record('queue.display.media_moved', $display, $actingUser, ['from' => $from, 'to' => $to]);
        }

        return $to;
    }

    /**
     * @throws AuthorizationException
     * @throws QueueFailed
     */
    private function authorize(User $actingUser, QueueDisplay $display): void
    {
        $this->entitlements->ensure('queue_management');
        $this->entitlements->ensure('queue_display');

        if (! $actingUser->hasPermission(Permission::QueueDisplayManage)) {
            throw new AuthorizationException('You may not manage queue displays.');
        }

        if (! $actingUser->canAccessBranch((int) $display->branch_id)) {
            throw new AuthorizationException('You may not work in that branch.');
        }

        if ($display->archived_at !== null) {
            throw QueueFailed::policy('That item is no longer on this screen.');
        }
    }

    private function displayOf(QueueDisplayMedia $row): QueueDisplay
    {
        $display = $row->display;

        if (! $display instanceof QueueDisplay) {
            throw QueueFailed::policy('That item is no longer on this screen.');
        }

        return $display;
    }

    /**
     * Serialises every playlist write for one screen behind its row.
     */
    private function lockDisplay(QueueDisplay $display): void
    {
        QueueDisplay::query()->whereKey($display->getKey())->lockForUpdate()->first();
    }

    private function renumber(QueueDisplay $display): void
    {
        /** @var list<int> $order */
        $order = QueueDisplayMedia::query()
            ->where('queue_display_id', $display->getKey())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $this->persistOrder($order);
    }

    /**
     * @param  list<int>  $order
     */
    private function persistOrder(array $order): void
    {
        foreach ($order as $position => $id) {
            QueueDisplayMedia::query()->whereKey($id)->where('sort_order', '!=', $position)->update(['sort_order' => $position]);
        }
    }

    /**
     * Video when the bytes sniff as a video container, image otherwise; the
     * kernel then holds the file to that kind's rules.
     */
    private function kind(UploadedFile $file): MediaKind
    {
        $path = $file->getRealPath();

        if ($path === false) {
            return MediaKind::Image;
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        return str_starts_with($mime, 'video/') ? MediaKind::Video : MediaKind::Image;
    }

    /**
     * Plain text per ENABLED language, trimmed, without control characters,
     * bounded. Other languages keep what they had.
     *
     * @param  array<string, string|null>  $values
     */
    private function merge(?TranslatedText $current, array $values, int $max): ?TranslatedText
    {
        $merged = $current?->all() ?? [];

        foreach ($this->locales->enabled() as $locale) {
            if (! array_key_exists($locale, $values)) {
                continue;
            }

            $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $values[$locale]) ?? '';
            $text = mb_substr(trim($text), 0, $max);

            if ($text === '') {
                unset($merged[$locale]);
            } else {
                $merged[$locale] = $text;
            }
        }

        return $merged === [] ? null : TranslatedText::fromArray($merged);
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function record(string $action, QueueDisplay $display, User $actingUser, array $after): void
    {
        $this->audit->record(new AuditEvent(
            action: $action,
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: QueueDisplay::class,
            targetId: $display->uuid,
            targetLabel: $display->name,
            after: $after === [] ? null : $after,
        ));
    }
}
