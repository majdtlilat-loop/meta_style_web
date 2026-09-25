<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Time\BranchClock;
use App\Livewire\Center\Concerns\RequiresFeature;
use App\Livewire\Center\Customers\Concerns\FormatsLocalDates;
use App\Livewire\Center\Reviews\Concerns\BuildsReviewCards;
use App\Livewire\Center\Reviews\Concerns\ReviewFilterOptions;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Employees\Domain\Models\Employee;
use App\Modules\Reviews\Application\Actions\ModerateReview;
use App\Modules\Reviews\Application\ReviewPresenter;
use App\Modules\Reviews\Application\ReviewsAccess;
use App\Modules\Reviews\Application\ReviewsQuery;
use App\Modules\Reviews\Domain\Enums\ReviewStatus;
use App\Modules\Reviews\Domain\Exceptions\ReviewFailed;
use App\Modules\Reviews\Domain\Models\Review;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * What customers said, and what a manager does about it.
 *
 * A summary that follows the branch and dates chosen, per-service and
 * per-employee averages, filters, the list (keyset pages by uuid — no numeric
 * id in the state) and the three moderation actions. The rules are the
 * Actions': eligibility, the capability, the rating targets and the moderation
 * trail are all decided there, and an architecture test refuses any of them
 * here (docs/22-REVIEWS.md §§2, 23).
 *
 * Branch scope belongs to `ReviewsQuery` and `RatingSummary`, not to the
 * filter boxes: a manager of one branch cannot reach another's reviews by
 * editing what this screen sends (§50). Dates are branch-local days, the last
 * one included. Losing `reviews` stops new links only: reading and moderating
 * go on (§18).
 */
#[Layout('components.layouts.app')]
final class Reviews extends Component
{
    use BuildsReviewCards;
    use FormatsLocalDates;
    use RequiresFeature;
    use ReviewFilterOptions;

    public string $status = '';

    public string $rating = '';

    public string $from = '';

    public string $to = '';

    public string $branch = '';

    public string $service = '';

    public string $employee = '';

    /** The review being moderated, by uuid. */
    public string $acting = '';

    public string $reason = '';

    /** Keyset cursor: show reviews older than this one (a uuid). */
    public string $before = '';

    /** @var list<string> cursors of the newer pages, for "Newer". */
    public array $trail = [];

    public string $error = '';

    public string $saved = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'rating', 'from', 'to', 'branch', 'service', 'employee'], true)) {
            $this->before = '';
            $this->trail = [];
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['status', 'rating', 'from', 'to', 'branch', 'service', 'employee', 'before', 'trail']);
    }

    public function older(string $uuid): void
    {
        $this->trail[] = $this->before;
        $this->before = $uuid;
    }

    public function newer(): void
    {
        $this->before = (string) array_pop($this->trail);
    }

    public function moderate(string $uuid): void
    {
        $this->reset(['error', 'saved', 'reason']);
        $this->acting = $uuid;
    }

    public function stopModerating(): void
    {
        $this->reset(['acting', 'reason']);
    }

    public function hide(ReviewsQuery $query, ModerateReview $moderate): void
    {
        $this->act($query, fn (Review $review, User $user) => $moderate->hide($review, $user, $this->reason), __('manager_customers.reviews.hidden_saved'));
    }

    /**
     * Shown again. No reason is required to undo a hiding — the audit row
     * already carries who hid it and why (§47).
     */
    public function unhide(string $uuid, ReviewsQuery $query, ModerateReview $moderate): void
    {
        $this->acting = $uuid;

        $this->act($query, fn (Review $review, User $user) => $moderate->unhide($review, $user), __('manager_customers.reviews.shown_saved'));
    }

    public function flag(ReviewsQuery $query, ModerateReview $moderate): void
    {
        $this->act($query, fn (Review $review, User $user) => $moderate->flag($review, $user, $this->reason === '' ? null : $this->reason), __('manager_customers.reviews.flagged_saved'));
    }

    public function render(ReviewsQuery $query, ReviewPresenter $presenter, ReviewsAccess $access): View
    {
        $user = $this->user();

        try {
            // Reading history survives a downgrade (§18).
            $access->authorize($user, Permission::ReviewView, 0, __('manager_customers.errors.may_not_view_reviews'));
        } catch (AuthorizationException) {
            abort(403);
        }

        $offer = $this->lockedFeature('reviews');

        if ($offer !== null && ! $query->anyCollected()) {
            return view('livewire.center.feature-locked', ['offer' => $offer])->title(__('ui.manager_nav.items.reviews'));
        }

        $branches = $this->branchOptions($user);
        $services = $this->ratedServiceOptions();
        $employees = $this->ratedEmployeeOptions();
        $page = $query->pageBefore($user, [
            'status' => ReviewStatus::tryFrom($this->status)?->value,
            'rating' => $this->rating === '' ? null : max(1, min(5, (int) $this->rating)),
            'from' => $this->from === '' ? null : $this->from,
            'to' => $this->to === '' ? null : $this->to,
            'branch' => $this->idFor($branches, $this->branch, Branch::class),
            'service' => $this->idFor($services, $this->service, Service::class),
            'employee' => $this->idFor($employees, $this->employee, Employee::class),
        ], $this->before === '' ? null : $this->before);

        $cards = $this->reviewCards($presenter->collection($page['reviews'], $page['ratings']));
        $last = $cards === [] ? null : $cards[array_key_last($cards)]['id'];

        return view('livewire.center.reviews', [
            'offer' => $offer,
            'canModerate' => $user->hasPermission(Permission::ReviewManage),
            'reviews' => $cards,
            'olderCursor' => $page['has_more'] ? $last : null,
            'hasNewer' => $this->trail !== [] || $this->before !== '',
            'statuses' => array_map(static fn (ReviewStatus $s): array => ['value' => $s->value, 'label' => $s->label()], ReviewStatus::cases()),
            'branches' => $branches,
            'services' => $services,
            'employees' => $employees,
            'summaryKey' => md5(implode('|', [$this->branch, $this->from, $this->to])),
            'hasFilters' => $this->status !== '' || $this->rating !== '' || $this->from !== '' || $this->to !== ''
                || $this->branch !== '' || $this->service !== '' || $this->employee !== '',
            // The date pickers' upper bound: today on the center's clock.
            'today' => BranchClock::localDate(CarbonImmutable::now(), $this->centerTimezone()),
        ])->title(__('ui.manager_nav.items.reviews'));
    }

    /**
     * @param  callable(Review, User): Review  $act
     */
    private function act(ReviewsQuery $query, callable $act, string $done): void
    {
        $this->error = '';
        $this->saved = '';

        $review = $query->find($this->user(), $this->acting);

        if (! $review instanceof Review) {
            // Out of scope reads like one that does not exist.
            $this->error = __('manager_customers.errors.review_not_found');

            return;
        }

        try {
            $act($review, $this->user());
            $this->saved = $done;
        } catch (ReviewFailed|AuthorizationException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->acting = '';
        $this->reason = '';
    }

    private function user(): User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : abort(403);
    }
}
