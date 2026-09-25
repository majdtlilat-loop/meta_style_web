<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Reviews\Domain\Enums\InvitationStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The right to review one completed visit, once.
 *
 * One row per journey — `service_journey_id` is UNIQUE, so the database itself
 * refuses a second invitation for the same visit however many times a completed
 * event is heard (docs/22-REVIEWS.md §4).
 *
 * The row holds `token_hash` only. The plaintext exists in the URL the minting
 * call returned and nowhere else, so a later read cannot show it again — the
 * desk issues a NEW link instead, exactly as it does for an invoice share link.
 *
 * `expires_at` is the only source of truth about expiry; there is no `expired`
 * status for a sweep to forget to write.
 *
 * @property int $id
 * @property string $uuid
 * @property int $service_journey_id
 * @property int $branch_id
 * @property int|null $customer_id
 * @property int|null $invoice_id
 * @property string $token_hash
 * @property InvitationStatus $status
 * @property Carbon $issued_at
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon|null $revoked_at
 * @property string $issued_by_type
 * @property string|null $issued_by_id
 * @property string|null $issued_by_label
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Review|null $review
 */
final class ReviewInvitation extends Model
{
    use UsesTenantConnection;

    protected $table = 'review_invitations';

    protected $guarded = [];

    /** Never serialised anywhere, by accident or otherwise. */
    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvitationStatus::class,
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $invitation): void {
            $invitation->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return HasOne<Review, $this>
     */
    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    /**
     * Whether this invitation can still be used at `$at`.
     *
     * Three conditions, one answer: nothing else may decide this, and the
     * caller that acts on it re-asks under the row lock (§14).
     */
    public function isUsable(CarbonInterface $at): bool
    {
        return $this->status === InvitationStatus::Issued && $this->expires_at->greaterThan($at);
    }
}
