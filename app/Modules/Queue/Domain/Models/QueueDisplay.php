<?php

declare(strict_types=1);

namespace App\Modules\Queue\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Departments\Domain\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A television, tablet or browser showing the queue.
 *
 * ## A first-class record, not settings hidden somewhere
 *
 * The public URL has to name something real. That is what lets a screen be
 * switched off and stop answering, lets one be scoped to the laser floor
 * without affecting the one at the door, and keeps the language and voice
 * choices attached to the device that makes them rather than to the center
 * (docs/17-QUEUE.md §9, correction 1).
 *
 * ## The public key is not the uuid
 *
 * A separate, rotatable value in the URL, beside the center's own public key:
 * `/q/{center}/{display}`. If a screen is stolen or a link is shared, the key
 * is replaced and nothing else that references this row is disturbed — the same
 * reasoning as the tenant public key (ADR-027, ADR-036).
 *
 * ## Three scopes, and only three
 *
 *     both null            the whole branch
 *     department_id set    one department's calls
 *     service_point_id set one counter's calls
 *
 * That covers a screen at the door, a screen on the laser floor and a screen
 * above a counter. Anything more would be a signage product, and a center never
 * supplies markup: the electronic menu learned that lesson (ADR-038).
 *
 * ## Promotional media and language rotation
 *
 * A screen may play the center's own images and videos beside the queue
 * (`promoMedia`, files stored and validated by the media kernel — never a URL,
 * markup or SVG) and may cycle its labels through several of the center's
 * languages. Both are presentation of THIS screen: neither touches a ticket,
 * and the call on screen always outranks them (docs/17-QUEUE.md §9).
 *
 * @property int $id
 * @property string $uuid
 * @property int $branch_id
 * @property string $name
 * @property string $public_key
 * @property int|null $department_id
 * @property int|null $service_point_id
 * @property string|null $locale
 * @property int $recent_calls_limit
 * @property bool $sound_enabled
 * @property bool $voice_enabled
 * @property list<string>|null $voice_locales
 * @property bool $promo_enabled
 * @property int $promo_slide_seconds
 * @property bool $rotation_enabled
 * @property list<string>|null $rotation_locales
 * @property int $rotation_seconds
 * @property bool $is_active
 * @property Carbon|null $archived_at
 */
final class QueueDisplay extends Model
{
    use UsesTenantConnection;

    /** A screen never lists more than this, however it is configured (§19). */
    public const MAX_RECENT = 20;

    /** Seconds one promotional image stays up: long enough to read, short enough to move. */
    public const MIN_SLIDE_SECONDS = 4;

    public const MAX_SLIDE_SECONDS = 60;

    /** Seconds each language stays on screen while the screen rotates languages. */
    public const MIN_ROTATION_SECONDS = 5;

    public const MAX_ROTATION_SECONDS = 60;

    protected $table = 'queue_displays';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'voice_locales' => 'array',
            'rotation_locales' => 'array',
            'recent_calls_limit' => 'integer',
            'promo_enabled' => 'boolean',
            'promo_slide_seconds' => 'integer',
            'rotation_enabled' => 'boolean',
            'rotation_seconds' => 'integer',
            'sound_enabled' => 'boolean',
            'voice_enabled' => 'boolean',
            'is_active' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $display): void {
            $display->uuid ??= (string) Str::uuid();
            $display->public_key ??= self::newPublicKey();
        });
    }

    /**
     * 32 hex characters from a cryptographic source.
     *
     * Not the uuid and not derived from anything: a display key appears in a
     * URL that will be opened on an unattended screen, and it must not be
     * guessable from any other identifier the center publishes.
     */
    public static function newPublicKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<QueueServicePoint, $this>
     */
    public function servicePoint(): BelongsTo
    {
        return $this->belongsTo(QueueServicePoint::class, 'service_point_id');
    }

    /**
     * The promotional images and videos this screen plays, in play order —
     * enabled or not. The public presentation filters; the Manager lists all.
     *
     * @return HasMany<QueueDisplayMedia, $this>
     */
    public function promoMedia(): HasMany
    {
        return $this->hasMany(QueueDisplayMedia::class, 'queue_display_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * Fails closed: a screen that is off or archived shows nothing at all.
     */
    public function isLive(): bool
    {
        return $this->is_active && $this->archived_at === null;
    }

    /**
     * How many past calls this screen lists, clamped.
     *
     * A stored 0 would make the screen blank and a stored 500 would make every
     * poll read a day of tickets. Neither is a configuration a center should be
     * able to reach by typing a number into a box (§19).
     */
    public function recentLimit(): int
    {
        return max(1, min(self::MAX_RECENT, $this->recent_calls_limit));
    }

    /**
     * How long one promotional image stays up, clamped: a stored 0 would spin
     * the carousel and a stored 255 would freeze it on one picture.
     */
    public function slideSeconds(): int
    {
        return max(self::MIN_SLIDE_SECONDS, min(self::MAX_SLIDE_SECONDS, (int) ($this->promo_slide_seconds ?? 8)));
    }

    /**
     * How long each language stays up while the screen rotates, clamped.
     */
    public function rotationSeconds(): int
    {
        return max(self::MIN_ROTATION_SECONDS, min(self::MAX_ROTATION_SECONDS, (int) ($this->rotation_seconds ?? 10)));
    }

    /**
     * The languages this screen was ASKED to cycle, as stored.
     *
     * Not yet narrowed to the center's enabled languages — that is a question
     * about the center, answered on every read by `DisplayLanguages`, so a
     * language switched off for the center leaves every screen at once.
     *
     * @return list<string>
     */
    public function rotationLocales(): array
    {
        $locales = $this->rotation_locales;

        return is_array($locales) ? array_values(array_unique(array_filter($locales, 'is_string'))) : [];
    }

    /**
     * The languages this screen speaks, in order.
     *
     * Empty means "the display language only". The client decides what it can
     * actually pronounce — see the note on Kurdish Sorani in `docs/17-QUEUE.md`
     * §16 — and always renders the text regardless.
     *
     * @return list<string>
     */
    public function voiceLocales(): array
    {
        $locales = $this->voice_locales;

        if (! is_array($locales) || $locales === []) {
            return $this->locale === null ? [] : [$this->locale];
        }

        return array_values(array_filter($locales, 'is_string'));
    }
}
