<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Application;

use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Employees\Domain\Models\Employee;
use App\Modules\Reviews\Domain\Enums\InvitationStatus;
use App\Modules\Reviews\Domain\Models\ReviewInvitation;
use App\Modules\Reviews\Domain\ReviewToken;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Carbon\CarbonImmutable;

/**
 * What a stranger holding a review link is allowed to see.
 *
 * ## An allow-list, and a very short one
 *
 * The page needs three things: which center and branch this was, what was
 * performed, and by whom. Nothing else is emitted — no journey uuid, no sale,
 * no invoice, no prices, no notes, no customer details, no employee ids, no
 * internal keys at all (docs/22-REVIEWS.md §21).
 *
 * The only identifiers on the page are the STAGE uuids, because a rating has to
 * name what it is rating, and they are meaningless anywhere else: a stage uuid
 * opens nothing, and submission accepts one only for the visit the presented
 * link already unlocked.
 *
 * ## One answer for every kind of "no"
 *
 * Unknown, revoked, expired and not-eligible all return null, and the page is a
 * plain 404. Distinguishing them would tell a stranger that a particular link
 * existed, which is a visit, which is a customer (§46). A link that has already
 * been USED is the one case the page names, because whoever is holding it has
 * already proved they had it — and being told "thank you, we have your review"
 * is better than being told nothing at all.
 *
 * Nothing here writes. The invitation is not consumed by being looked at.
 */
final class PublicReview
{
    public function __construct(
        private readonly ReviewEligibility $eligibility,
        private readonly TenantLocales $locales,
        private readonly TenantContext $tenants,
        private readonly LanguageRegistry $languages,
    ) {}

    /**
     * The invitation behind a presented secret, or null.
     *
     * Does NOT judge expiry or status — callers do, because the page shows a
     * used link a different face from an unknown one.
     */
    public function invitation(#[\SensitiveParameter] string $token): ?ReviewInvitation
    {
        if (! ReviewToken::isWellFormed($token)) {
            return null;
        }

        /** @var ReviewInvitation|null $invitation */
        $invitation = ReviewInvitation::query()->where('token_hash', ReviewToken::hash($token))->first();

        return $invitation;
    }

    /**
     * The form's contents, or null when there is nothing to show.
     *
     * @return array{center: string, branch: string, visited_on: string, locale: string, direction: string, stages: list<array{id: string, service: string, employee: string|null}>}|null
     */
    public function form(ReviewInvitation $invitation, ?CarbonImmutable $now = null): ?array
    {
        $at = ($now ?? CarbonImmutable::now())->utc();

        if (! $invitation->isUsable($at)) {
            return null;
        }

        /** @var ServiceJourney|null $journey */
        $journey = ServiceJourney::query()->whereKey($invitation->service_journey_id)->first();

        if (! $journey instanceof ServiceJourney || ! $this->eligibility->isReviewable($journey)) {
            return null;
        }

        /** @var Branch|null $branch */
        $branch = Branch::query()->whereKey($invitation->branch_id)->first();

        // The language the page is already being rendered in — resolved by the
        // `locale` middleware from the visitor's own request, and narrowed to
        // what this center actually publishes (docs/07-LOCALIZATION.md).
        $locale = $this->locales->resolve(app()->getLocale());

        $stages = [];

        foreach ($this->eligibility->ratableStages($journey) as $uuid => $stage) {
            $stages[] = [
                'id' => $uuid,
                'service' => $this->serviceName($stage, $locale),
                'employee' => $this->employeeName($stage, $locale),
            ];
        }

        return [
            'center' => $this->tenants->require()->name,
            'branch' => $branch instanceof Branch ? (string) $branch->name->get($locale) : '',
            // The date only. The hour of a customer's visit is not something a
            // page reachable by a link needs to state.
            'visited_on' => $journey->completed_at?->toDateString() ?? '',
            // Direction comes from the language registry, never a hardcoded
            // list of right-to-left locales (docs/07-LOCALIZATION.md).
            'locale' => $locale,
            'direction' => $this->languages->direction($locale),
            'stages' => $stages,
        ];
    }

    /**
     * The invitations one customer can still act on, already presented.
     *
     * For a SIGNED-IN customer, so no secret is involved: each one is named by
     * its own uuid, which their own account resolves (§40). The query lives
     * here rather than in a controller — a screen that resolved invitations
     * itself would be a second place the eligibility rules could drift
     * (docs/22-REVIEWS.md §2).
     *
     * @return list<array<string, mixed>>
     */
    public function pendingFor(int $customerId, int $limit = 10, ?CarbonImmutable $now = null): array
    {
        if ($customerId < 1) {
            return [];
        }

        $at = ($now ?? CarbonImmutable::now())->utc();

        /** @var list<ReviewInvitation> $invitations */
        $invitations = ReviewInvitation::query()
            ->where('customer_id', $customerId)
            ->where('status', InvitationStatus::Issued->value)
            ->where('expires_at', '>', $at)
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 50)))
            ->get()
            ->all();

        $pending = [];

        foreach ($invitations as $invitation) {
            $form = $this->form($invitation, $at);

            if ($form !== null) {
                $pending[] = ['id' => $invitation->uuid] + $form;
            }
        }

        return $pending;
    }

    public function isSpent(?ReviewInvitation $invitation): bool
    {
        return $invitation instanceof ReviewInvitation && $invitation->status === InvitationStatus::Used;
    }

    private function serviceName(JourneyStage $stage, string $locale): string
    {
        return (string) ($stage->serviceName()?->get($locale) ?? '');
    }

    /**
     * The person who ACTUALLY performed it, by name only. No employee id ever
     * reaches this page (§21).
     */
    private function employeeName(JourneyStage $stage, string $locale): ?string
    {
        if ($stage->employee_id === null) {
            return null;
        }

        /** @var Employee|null $employee */
        $employee = Employee::query()->whereKey($stage->employee_id)->first();

        return $employee instanceof Employee ? (string) $employee->name->get($locale) : null;
    }
}
