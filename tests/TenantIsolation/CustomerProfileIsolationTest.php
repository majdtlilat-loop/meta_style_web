<?php

declare(strict_types=1);

use App\Livewire\Center\Customers\Profile;
use App\Livewire\Center\Customers\ReviewsPanel;
use App\Livewire\Center\Customers\TagsDrawer;
use App\Modules\Booking\Application\CustomerProfileAppointments;
use App\Modules\Customers\Application\Actions\ManageCustomerTag;
use App\Modules\Customers\Application\CustomerQuery;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Customers\Domain\Models\CustomerTag;
use App\Modules\Loyalty\Application\Actions\AdjustPoints;
use App\Modules\Loyalty\Application\LoyaltyQuery;
use App\Modules\Loyalty\Domain\Enums\PointsDirection;
use App\Modules\Memberships\Application\MembershipsQuery;
use App\Modules\Packages\Application\PackagesQuery;
use App\Modules\Reviews\Application\ReviewsQuery;
use App\Modules\Sales\Application\CustomerProfileSales;
use App\Modules\ServiceJourney\Application\CustomerProfileVisits;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
|--------------------------------------------------------------------------
| The Manager customer page, points holders and benefit lists stay per center
|--------------------------------------------------------------------------
|
| docs/11-TESTING-STRATEGY.md §4 — release gate. The customer page reads six
| modules; the loyalty, membership and package pages list holders. None of it
| may reach another center: a uuid from one center is simply not found in the
| other (404, never 403), and every list starts empty there.
|
*/

it('never opens another center\'s customer page, and lists none of its holders', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');
    $betaSlug = $beta['registration']->requested_slug;

    [$uuid, $customerId] = $this->asCenter($alpha['tenant'], function (): array {
        $this->grantLoyalty();
        $this->grantPackages();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer('Alpha Only', '0750 123 4567');

        $this->loyaltyProgram($owner);
        app(AdjustPoints::class)($customer->uuid, $owner, PointsDirection::In, 100, 'Opening balance');
        $this->paidPackage($seed, $owner, $customer);
        $this->bookFor($seed, $owner, $customer, 2);

        return [$customer->uuid, (int) $customer->getKey()];
    });

    $this->asCenter($beta['tenant'], function () use ($uuid, $customerId, $betaSlug): void {
        $owner = $this->ownerWithCatalogAccess();

        expect(fn () => app(CustomerQuery::class)->find($uuid, $owner))->toThrow(NotFoundHttpException::class)
            ->and(app(LoyaltyQuery::class)->totals($owner))->toBe(['members' => 0, 'outstanding_points' => 0, 'lifetime_points' => 0])
            ->and(app(LoyaltyQuery::class)->members($owner)->total())->toBe(0)
            ->and(app(PackagesQuery::class)->holders($owner)->total())->toBe(0)
            ->and(app(PackagesQuery::class)->liveCountsByDefinition($owner))->toBe([])
            ->and(app(MembershipsQuery::class)->members($owner)->total())->toBe(0)
            // Even the right internal id finds nothing in the other database.
            ->and(app(CustomerProfileAppointments::class)->forCustomer($owner, $customerId))->toBe(['upcoming' => [], 'past' => []])
            ->and(app(CustomerProfileVisits::class)->forCustomer($owner, $customerId))->toBe([])
            ->and(app(CustomerProfileSales::class)->forCustomer($owner, $customerId))->toBe([]);

        $this->actingAs($owner);
        $this->get("http://{$betaSlug}.localhost:8000/manager/customers/{$uuid}")->assertNotFound();

        // Livewire's test broker hands an HTTP exception to the handler: a 404.
        Livewire::actingAs($owner)->test(Profile::class, ['uuid' => $uuid])->assertNotFound();
    });
});

it('keeps a customer\'s reviews and the center\'s customer tags per center', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');

    [$customerId, $customerUuid, $tagUuid] = $this->asCenter($alpha['tenant'], function (): array {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $review = $this->leaveReview($seed, $owner, 5, phone: '0750 123 4801');
        $tag = app(ManageCustomerTag::class)->save($owner, ['en' => 'Alpha VIP']);

        return [
            (int) $review->customer_id,
            (string) Customer::query()->whereKey($review->customer_id)->value('uuid'),
            $tag->uuid,
        ];
    });

    $this->asCenter($beta['tenant'], function () use ($customerId, $customerUuid, $tagUuid): void {
        $owner = $this->ownerWithCatalogAccess();

        // The same internal id names nobody here, and the panel's uuid is a 404.
        expect(app(ReviewsQuery::class)->page($owner, ['customer' => $customerId])['reviews'])->toBe([])
            ->and(CustomerTag::query()->where('uuid', $tagUuid)->exists())->toBeFalse();

        Livewire::actingAs($owner)->test(ReviewsPanel::class, ['customer' => $customerUuid])->assertNotFound();

        // The tags drawer lists only this center's tags.
        Livewire::actingAs($owner)->test(TagsDrawer::class)
            ->call('show')
            ->assertDontSee('Alpha VIP');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
