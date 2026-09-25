<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Livewire\Center\Customers\BookingsPanel;
use App\Livewire\Center\Customers\PurchasesPanel;
use App\Livewire\Center\Customers\ReviewsPanel;
use App\Livewire\Center\Customers\VisitsPanel;
use App\Modules\Booking\Application\CustomerProfileAppointments;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Reviews\Application\ReviewsQuery;
use App\Modules\Sales\Application\CustomerProfileSales;
use App\Modules\ServiceJourney\Application\CustomerProfileVisits;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The customer page's history: bookings, visits, purchases, reviews
|--------------------------------------------------------------------------
|
| Each panel is its owning module's own read — permission AND branch scope in
| the query — so the Customers module imports none of them (docs/04). A viewer
| without the permission gets the panel's refusal, never the rows.
|
*/

it('lists a customer\'s upcoming and past bookings for staff who may see bookings', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $other = $this->seedCustomer('Somebody Else', '0770 999 8888');

        $mine = $this->bookFor($seed, $owner, $customer, 2);
        $this->bookFor($seed, $owner, $other, 3);

        $found = app(CustomerProfileAppointments::class)->forCustomer($owner, (int) $customer->getKey());

        expect(array_map(fn ($a) => $a->uuid, $found['upcoming']))->toBe([$mine->uuid])
            ->and($found['past'])->toBe([]);

        Livewire::actingAs($owner)
            ->test(BookingsPanel::class, ['customer' => $customer->uuid])
            ->assertSee((string) $mine->reference)
            ->assertSee('Haircut');

        $noBookings = $this->staffWith([Permission::CustomerView], 'nobookings@alpha.test');

        expect(fn () => app(CustomerProfileAppointments::class)->forCustomer($noBookings, (int) $customer->getKey()))
            ->toThrow(AuthorizationException::class);

        Livewire::actingAs($noBookings)
            ->test(BookingsPanel::class, ['customer' => $customer->uuid])
            ->assertDontSee((string) $mine->reference);
    });
});

it('lists a customer\'s visits — walk-in and booked — with who performed them', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();

        $walkIn = $this->customerVisit($seed, $owner, $customer);
        $booked = $this->bookedCustomerVisit($seed, $owner, $customer);
        $this->walkInVisit($seed, $owner);

        $visits = app(CustomerProfileVisits::class)->forCustomer($owner, (int) $customer->getKey());

        expect(collect($visits)->pluck('uuid')->sort()->values()->all())
            ->toBe(collect([$walkIn->uuid, $booked->uuid])->sort()->values()->all());

        Livewire::actingAs($owner)
            ->test(VisitsPanel::class, ['customer' => $customer->uuid])
            ->assertSee('Haircut')
            ->assertSee((string) $seed['employee']->name->get());

        $noJourney = $this->staffWith([Permission::CustomerView], 'nojourney@alpha.test');

        expect(fn () => app(CustomerProfileVisits::class)->forCustomer($noJourney, (int) $customer->getKey()))
            ->toThrow(AuthorizationException::class);
    });
});

it('lists issued purchases, never drafts, and narrows them to the viewer\'s branches', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();

        $invoice = $this->customerInvoice($seed, $owner, $customer);
        $this->customerSale($seed['branch'], $owner, $customer);

        $sales = app(CustomerProfileSales::class)->forCustomer($owner, (int) $customer->getKey());

        expect($sales)->toHaveCount(1);

        Livewire::actingAs($owner)
            ->test(PurchasesPanel::class, ['customer' => $customer->uuid])
            ->assertSee($invoice->number);

        // Someone who works only at another branch sees none of them.
        $elsewhere = $this->seedBranch('Karrada');
        $branchStaff = $this->staffWith([Permission::CustomerView, Permission::SaleView], 'branch@alpha.test');
        $branchStaff->forceFill(['all_branches' => false])->save();
        $branchStaff->syncBranchScope([(int) $elsewhere->id]);

        expect(app(CustomerProfileSales::class)->forCustomer($branchStaff->fresh() ?? $branchStaff, (int) $customer->getKey()))->toBe([]);

        $noSales = $this->staffWith([Permission::CustomerView], 'nosales@alpha.test');

        expect(fn () => app(CustomerProfileSales::class)->forCustomer($noSales, (int) $customer->getKey()))
            ->toThrow(AuthorizationException::class);
    });
});

it('shows what this customer said, and only this customer', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $theirs = $this->leaveReview($seed, $owner, 4, phone: '0750 123 4601');
        $this->leaveReview($seed, $owner, 2, phone: '0750 123 4602');

        $customerId = (int) $theirs->customer_id;
        $customerUuid = (string) Customer::query()->whereKey($customerId)->value('uuid');

        $page = app(ReviewsQuery::class)->page($owner, ['customer' => $customerId]);

        expect(collect($page['reviews'])->pluck('uuid')->all())->toBe([$theirs->uuid]);

        Livewire::actingAs($owner)
            ->test(ReviewsPanel::class, ['customer' => $customerUuid])
            ->assertSee('4 / 5')
            ->assertDontSee('2 / 5');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
