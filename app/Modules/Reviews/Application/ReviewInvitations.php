<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Application;

use App\Kernel\Audit\Actor;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\Reviews\Domain\Enums\InvitationStatus;
use App\Modules\Reviews\Domain\Models\ReviewInvitation;
use App\Modules\Reviews\Domain\ReviewToken;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Carbon\CarbonImmutable;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SensitiveParameter;

/**
 * Mints a visit's review link, and composes the URL the customer receives.
 *
 * The ONE place a review secret is created. It persists the DIGEST and returns
 * the plaintext to its caller — the only copy there will ever be. Nothing can
 * look one up again, which is exactly the point: a desk that wants a link for
 * an older visit issues a NEW one, and the old link stops working, the same way
 * an invoice link behaves (docs/18-SALES.md §20, docs/22-REVIEWS.md §5).
 *
 * That is also why the plaintext never travels on an event or in a notification
 * payload: a customer with an account is sent to their review through their own
 * signed-in session, not through a secret copied into a second table (§40).
 */
final class ReviewInvitations
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly UrlGenerator $urls,
    ) {}

    /**
     * Creates the visit's live invitation. Runs inside the caller's transaction,
     * so an invitation never outlives a rollback.
     *
     * @return array{0: ReviewInvitation, 1: string} the row, and the plaintext
     *                                               secret — never store it,
     *                                               never log it
     */
    public function mint(ServiceJourney $journey, Actor $actor, ?CarbonImmutable $now = null): array
    {
        if (DB::connection('tenant')->transactionLevel() < 1) {
            throw new RuntimeException('ReviewInvitations::mint() must run inside a transaction.');
        }

        $at = ($now ?? CarbonImmutable::now())->utc();
        $secret = ReviewToken::generate();

        /** @var ReviewInvitation $invitation */
        $invitation = ReviewInvitation::query()->create([
            'service_journey_id' => $journey->getKey(),
            'branch_id' => $journey->branchId(),
            'customer_id' => $journey->customerId() === 0 ? null : $journey->customerId(),
            'token_hash' => ReviewToken::hash($secret),
            'status' => InvitationStatus::Issued,
            'issued_at' => $at,
            'expires_at' => $at->addDays($this->validDays()),
            'issued_by_type' => $actor->type->value,
            'issued_by_id' => $actor->id,
            'issued_by_label' => $actor->label,
        ]);

        return [$invitation, $secret];
    }

    /**
     * The customer's URL for a secret that was just minted.
     *
     * The public review page lives on the center's own host since Phase 15,
     * published under that host's slug — the only address a center answers on
     * (`ResolvePublicTenant` refuses any other). So the link takes the slug
     * the current request resolved (the Manager host, or the API's token
     * binding), exactly as an invoice link does (`Sales\Application\InvoiceLinks`);
     * the public key only when there is no request host at all.
     */
    public function url(#[SensitiveParameter] string $secret): string
    {
        $center = $this->urls->getDefaultParameters()['center'] ?? null;

        return route('review.public', [
            'center' => is_string($center) && $center !== '' ? $center : ($this->tenants->require()->slug ?? $this->tenants->require()->publicKey),
            'token' => $secret,
        ]);
    }

    /**
     * How long a new invitation lasts. Read at MINT time and stored on the row,
     * so changing the setting never moves an expiry a customer was already
     * given (docs/22-REVIEWS.md §6).
     */
    public function validDays(): int
    {
        $days = (int) config('reviews.invitation_valid_days', 30);

        return max(1, min($days, 365));
    }
}
