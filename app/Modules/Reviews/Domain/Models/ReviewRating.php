<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Reviews\Domain\Enums\RatingDimension;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One optional detail score: this service, or this employee.
 *
 * Structured rows rather than a JSON blob on the review, because these are
 * QUERIED — "the average for this stylist", "the average for this service" —
 * and an aggregate over JSON is a scan that gets slower every month
 * (docs/22-REVIEWS.md §10).
 *
 * `journey_stage_id` is the proof and is NOT NULL: every row points at a stage
 * that was actually completed during the visit being reviewed, and the service
 * and employee are resolved FROM that stage rather than taken from the request.
 * So a customer can rate the person who performed the work and not the one who
 * was merely booked (§11), and cannot rate a service that never happened (§12).
 *
 * @property int $id
 * @property string $uuid
 * @property int $review_id
 * @property RatingDimension $dimension
 * @property int $journey_stage_id
 * @property int|null $service_id
 * @property int|null $employee_id
 * @property int $rating
 * @property Carbon $created_at
 */
final class ReviewRating extends Model
{
    use UsesTenantConnection;

    public const UPDATED_AT = null;

    protected $table = 'review_ratings';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dimension' => RatingDimension::class,
            'rating' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $rating): void {
            $rating->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<Review, $this>
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }
}
