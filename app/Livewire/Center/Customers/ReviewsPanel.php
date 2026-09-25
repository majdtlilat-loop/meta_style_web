<?php

declare(strict_types=1);

namespace App\Livewire\Center\Customers;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Livewire\Center\Concerns\RequiresFeature;
use App\Livewire\Center\Reviews\Concerns\BuildsReviewCards;
use App\Modules\Customers\Application\CustomerQuery;
use App\Modules\Reviews\Application\ReviewPresenter;
use App\Modules\Reviews\Application\ReviewsAccess;
use App\Modules\Reviews\Application\ReviewsQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The customer page's reviews: what this customer said about their visits.
 *
 * Reviews' own read — `review.view`, branch-scoped, never the entitlement: a
 * review already collected stays readable after a downgrade (docs/22 §18). The
 * customer's id comes from the Customers read that authorised the page, never
 * from the request. Moderation stays on the Reviews page. A center that never
 * had reviews and does not own them sees the compact upgrade notice instead.
 */
final class ReviewsPanel extends Component
{
    use BuildsReviewCards;
    use RequiresFeature;

    public const LIMIT = 30;

    #[Locked]
    public string $customer = '';

    public function render(CustomerQuery $customers, ReviewsQuery $reviews, ReviewPresenter $presenter, ReviewsAccess $access): View
    {
        $user = $this->user();

        try {
            $customer = $customers->find($this->customer, $user);
            $access->authorize($user, Permission::ReviewView, 0, __('manager_customers.errors.may_not_view_reviews'));
        } catch (AuthorizationException) {
            return view('livewire.center.customers.panel-denied');
        }

        $offer = $this->lockedFeature('reviews');

        if ($offer !== null && ! $reviews->anyCollected()) {
            return view('livewire.center.customers.reviews-panel', ['offer' => $offer, 'locked' => true, 'reviews' => [], 'reviewsUrl' => null]);
        }

        $page = $reviews->page($user, ['customer' => (int) $customer->getKey()], self::LIMIT);

        return view('livewire.center.customers.reviews-panel', [
            'offer' => $offer,
            'locked' => false,
            'reviews' => $this->reviewCards($presenter->collection($page['reviews'], $page['ratings'])),
            'reviewsUrl' => route('center.reviews'),
        ]);
    }

    private function user(): User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : abort(403);
    }
}
