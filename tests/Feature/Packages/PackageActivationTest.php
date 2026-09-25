<?php

declare(strict_types=1);

use App\Kernel\Database\AfterCommitFailed;
use App\Modules\Finance\Domain\Enums\EntryKind;
use App\Modules\Finance\Domain\Models\FinanceEntry;
use App\Modules\Packages\Application\ActivatePackages;
use App\Modules\Packages\Application\PackagesQuery;
use App\Modules\Packages\Domain\Enums\PackageMovement;
use App\Modules\Packages\Domain\Models\CustomerPackage;
use App\Modules\Packages\Domain\Models\PackageTransaction;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Application\InvoiceSettlement;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\Enums\SettlementState;
use App\Modules\Payments\Domain\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;

/*
|--------------------------------------------------------------------------
| Package activation after the money commits
|--------------------------------------------------------------------------
|
| docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§1, 15.
|
| A package becomes the customer's when the invoice that sold it is settled —
| after that payment commits, never as part of it. A failure cannot roll the
| payment back or turn it into an error, and what it left undone is activated
| from the sale and its payments, exactly once.
|
*/

/**
 * Makes every package activation fail while `$down` is true.
 */
function packageStoreDown(bool &$down): void
{
    CustomerPackage::creating(static function () use (&$down): void {
        if ($down) {
            throw new RuntimeException('Package storage unavailable');
        }
    });
}

it('activates nothing until the invoice that sold the package is settled', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPackages();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $invoice = $this->packageInvoice($seed['branch'], $owner, $customer, $this->packageDefinition($seed, $owner));

        expect(CustomerPackage::query()->count())->toBe(0);

        app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 40000);

        expect(CustomerPackage::query()->count())->toBe(0);

        app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 40000);

        expect(CustomerPackage::query()->count())->toBe(1)
            ->and(PackageTransaction::query()->where('kind', PackageMovement::Allocation->value)->sum('quantity'))->toEqual(5);
    });
});

it('never lets a failing activation roll back or fail the payment, and reconciliation activates it exactly once', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPackages();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $invoice = $this->packageInvoice($seed['branch'], $owner, $customer, $this->packageDefinition($seed, $owner));

        Exceptions::fake([AfterCommitFailed::class]);
        $down = true;
        packageStoreDown($down);

        $payment = app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 80000);

        expect(Payment::query()->whereKey($payment->id)->value('status'))->toBe(PaymentStatus::Succeeded)
            ->and(FinanceEntry::query()->where('kind', EntryKind::Collection->value)->count())->toBe(1)
            ->and(app(InvoiceSettlement::class)->forInvoice($invoice)->state)->toBe(SettlementState::Paid)
            ->and(CustomerPackage::query()->count())->toBe(0);

        Exceptions::assertReported(static fn (AfterCommitFailed $e): bool => $e->label === 'packages.activate_on_payment');

        $down = false;
        $activation = app(ActivatePackages::class);
        $since = CarbonImmutable::now()->subDay();

        expect($activation->reconcile($since))->toBe(1)
            ->and($activation->reconcile($since))->toBe(0)
            ->and(CustomerPackage::query()->count())->toBe(1)
            ->and(PackageTransaction::query()->where('kind', PackageMovement::Allocation->value)->count())->toBe(1);
    });
});

it('keeps reads read-only, and leaves the repair of a lost activation to the reconcile command', function (): void {
    $center = $this->registerCenter();

    [$customer, $owner] = $this->asCenter($center['tenant'], function (): array {
        $this->grantPackages();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $invoice = $this->packageInvoice($seed['branch'], $owner, $customer, $this->packageDefinition($seed, $owner));

        Exceptions::fake([AfterCommitFailed::class]);
        $down = true;
        packageStoreDown($down);
        app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 80000);
        $down = false;

        // Reading writes nothing — not even a repair.
        expect(app(PackagesQuery::class)->forCustomer($customer->uuid, $owner)['packages'])->toBe([])
            ->and(app(PackagesQuery::class)->ofCustomer((int) $customer->getKey()))->toBe([])
            ->and(CustomerPackage::query()->count())->toBe(0);

        return [$customer, $owner];
    });

    $this->artisan('metastyle:reconcile', ['--tenant' => $center['tenant']->id])
        ->expectsOutputToContain('1 item(s) repaired.')
        ->assertSuccessful();

    $this->asCenter($center['tenant'], function () use ($customer, $owner): void {
        expect(app(PackagesQuery::class)->forCustomer($customer->uuid, $owner)['packages'])->toHaveCount(1)
            ->and(PackageTransaction::query()->where('kind', PackageMovement::Allocation->value)->count())->toBe(1);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
