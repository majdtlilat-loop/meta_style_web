<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Reviews\Domain\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * What one customer said about one completed visit.
 *
 * ## The customer's words are not editable
 *
 * `overall_rating` and `public_comment` are written once, at submission, and
 * nothing may rewrite them — not a manager, not moderation, not an API. A
 * center that could edit a review would be publishing its own opinion under a
 * customer's name. Moderation changes `status` and records WHO and WHY beside
 * it; the content stays exactly as it arrived (docs/22-REVIEWS.md §15).
 *
 * ## One per visit
 *
 * `service_journey_id` is UNIQUE. Two tills, two tabs and two simultaneous
 * submissions of the same token all end with one row, because the database says
 * so and not because the code remembered to check.
 *
 * ## Not a financial record
 *
 * `invoice_id` is a traceability link, nullable, and nothing reads a review to
 * decide anything about money. `branch_id` is snapshotted from the journey so
 * staff scoping and branch filters are one indexed column rather than a join
 * through two tables.
 *
 * @property int $id
 * @property string $uuid
 * @property int $review_invitation_id
 * @property int $service_journey_id
 * @property int $branch_id
 * @property int|null $customer_id
 * @property int|null $invoice_id
 * @property ReviewStatus $status
 * @property int $overall_rating
 * @property string|null $public_comment
 * @property Carbon $submitted_at
 * @property Carbon|null $moderated_at
 * @property string|null $moderated_by_id
 * @property string|null $moderated_by_label
 * @property string|null $moderation_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read ReviewInvitation|null $invitation
 */
final class Review extends Model
{
    use UsesTenantConnection;

    /** The worst and best a customer may say. Validated, never clamped. */
    public const MIN_RATING = 1;

    public const MAX_RATING = 5;

    /** A comment box, not an essay — and not a place to paste a document. */
    public const MAX_COMMENT_LENGTH = 1000;

    protected $table = 'reviews';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReviewStatus::class,
            'overall_rating' => 'integer',
            'submitted_at' => 'datetime',
            'moderated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $review): void {
            $review->uuid ??= (string) Str::uuid();
        });

        /*
         * The customer's own words are immutable. Moderation is a status, an
         * actor and a reason — never a rewrite (§15). Enforced at the model so
         * a well-meaning `->update()` somewhere cannot quietly do it.
         */
        self::updating(function (self $review): void {
            foreach (['overall_rating', 'public_comment', 'submitted_at', 'service_journey_id', 'customer_id'] as $frozen) {
                if ($review->isDirty($frozen)) {
                    throw new \LogicException("A review's {$frozen} is what the customer submitted and is never changed.");
                }
            }
        });
    }

    /**
     * @return BelongsTo<ReviewInvitation, $this>
     */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(ReviewInvitation::class, 'review_invitation_id');
    }

    /**
     * @return HasMany<ReviewRating, $this>
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(ReviewRating::class);
    }

    public function isVisible(): bool
    {
        return $this->status->countsTowardsRatings();
    }
}
