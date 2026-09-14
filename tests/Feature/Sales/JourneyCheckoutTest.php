<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Authorization\Permission;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\AdjustSale;
use App\Modules\Sales\Application\Actions\ChangeSaleLine;
use App\Modules\Sales\Application\Actions\CheckoutJourney;
use App\Modules\Sales\Application\Actions\CloseSale;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Application\CheckoutReview;
use App\Modules\Sales\Domain\Enums\PriceSource;
use App\Modules\Sales\Domain\Enums\SaleSource;
use App\Modules\Sales\Domain\Enums\SaleStatus;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\Sales\Domain\Models\InvoiceItem;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Models\SaleItem;
use App\Modules\ServiceJourney\Application\Actions\AbortJourney;
use App\Modules\ServiceJourney\Application\Actions\CompleteJourney;
use App\Modules\ServiceJourney\Application\Actions\TransitionStage;
use App\Modules\ServiceJourney\Domain\Enums\JourneyStatus;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Checking a visit out
|--------------------------------------------------------------------------
|
| docs/18-SALES.md §§7–8, 32–33, 38–39.
|
|   BOOKED     the appointment item — reserved, at the quoted price
|   PERFORMED  the journey stage — whether it happened
|   CHARGED    the sale line — what the customer pays
|
| Three facts. A sale reads the first two and writes only the third.
|
*/

function jcSeed(): array
{
    $seed = test()->seedBookableCenter();

    for ($i = 2; $i <= 4; $i++) {
        test()->seedBookableEmployee($seed['service'], $seed['branch'], "Stylist {$i}");
    }

    return $seed;
}

it('charges a completed walk-in stage at the price recorded on the visit', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jcSeed();
        $owner = $this->ownerWithCatalogAccess();

        $journey = $this->walkInVisit($seed, $owner, ['completed']);

        $sale = app(CheckoutJourney::class)($journey, $owner);
        $line = SaleItem::query()->where('sale_id', $sale->id)->firstOrFail();

        expect($sale->source)->toBe(SaleSource::WalkInCheckout)
            ->and($sale->service_journey_id)->toBe($journey->id)
            ->and($sale->customer_id)->toBe($journey->customerId())
            ->and($line->price_source)->toBe(PriceSource::Journey)
            ->and($line->journey_stage_id)->not->toBeNull()
            ->and($line->unit_price_minor)->toBe(20000)
            ->and($sale->fresh()?->grand_total_minor)->toBe(20000);
    });
});

it('charges a booked visit at its booked price, with variation and add-ons kept apart', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jcSeed();
        $owner = $this->ownerWithCatalogAccess();
        $medium = $seed['service']->variations()->where('price_minor', 25000)->firstOrFail();

        $journey = $this->bookedVisit($seed, $owner, $medium->uuid, [$seed['addon']->uuid]);

        $sale = app(CheckoutJourney::class)($journey, $owner);
        $line = SaleItem::query()->where('sale_id', $sale->id)->with('addons')->firstOrFail();

        // Booking stored 30 000 = 25 000 + a 5 000 wash. The line carries the
        // service price and the add-on separately, and still totals 30 000.
        expect($sale->source)->toBe(SaleSource::JourneyCheckout)
            ->and($line->unit_price_minor)->toBe(25000)
            ->and($line->addons_unit_total_minor)->toBe(5000)
            ->and($line->addons)->toHaveCount(1)
            ->and($line->variation_name?->get('en'))->toBe('Medium Hair')
            ->and($sale->fresh()?->grand_total_minor)->toBe(30000);
    });
});

it('never reprices a booking from today\'s catalog', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jcSeed();
        $owner = $this->ownerWithCatalogAccess();

        $journey = $this->bookedVisit($seed, $owner);

        // The price went up between the booking and the checkout.
        $seed['service']->forceFill(['price_minor' => 99000])->save();

        $sale = app(CheckoutJourney::class)($journey, $owner);

        expect($sale->fresh()?->grand_total_minor)->toBe(20000);
    });
});

it('does not charge skipped or unperformed services', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jcSeed();
        $owner = $this->ownerWithCatalogAccess();

        $journey = $this->walkInVisit($seed, $owner, ['completed', 'skipped', 'waiting']);

        $sale = app(CheckoutJourney::class)($journey, $owner);

        expect(SaleItem::query()->where('sale_id', $sale->id)->count())->toBe(1)
            ->and($sale->fresh()?->grand_total_minor)->toBe(20000);

        // And the checkout screen SAYS so, rather than leaving staff to infer it.
        $review = app(CheckoutReview::class)->forSale($sale->fresh() ?? $sale);

        expect(array_column($review, 'performed'))->toBe(['completed', 'skipped', 'waiting'])
            ->and($review[0]['charged'])->not->toBeNull()
            ->and($review[1]['charged'])->toBeNull()
            ->and($review[1]['chargeable'])->toBeFalse()
            ->and($review[2]['chargeable'])->toBeFalse();
    });
});

it('returns the same draft however many times checkout is opened', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jcSeed();
        $owner = $this->ownerWithCatalogAccess();

        $journey = $this->walkInVisit($seed, $owner, ['completed']);

        $first = app(CheckoutJourney::class)($journey, $owner);
        $second = app(CheckoutJourney::class)($journey, $owner);
        $third = app(CheckoutJourney::class)($journey->fresh() ?? $journey, $owner);

        expect($second->uuid)->toBe($first->uuid)
            ->and($third->uuid)->toBe($first->uuid)
            ->and(Sale::query()->count())->toBe(1)
            ->and(SaleItem::query()->count())->toBe(1);
    });
});

it('charges a service performed after checkout was opened, once', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jcSeed();
        $owner = $this->ownerWithCatalogAccess();

        $journey = $this->walkInVisit($seed, $owner, ['completed', 'waiting']);
        $sale = app(CheckoutJourney::class)($journey, $owner);

        /** @var JourneyStage $second */
        $second = $journey->stages()->orderBy('position')->get()[1];

        // Not yet performed: refused.
        expect(fn () => app(AddSaleLine::class)($sale, $owner, ['kind' => 'journey_stage', 'stage' => $second->uuid]))
            ->toThrow(SaleFailed::class, 'completed');

        app(TransitionStage::class)($second, StageStatus::InService, $owner);
        app(TransitionStage::class)($second->fresh() ?? $second, StageStatus::Completed, $owner);

        app(AddSaleLine::class)($sale, $owner, ['kind' => 'journey_stage', 'stage' => $second->uuid]);

        expect(fn () => app(AddSaleLine::class)($sale, $owner, ['kind' => 'journey_stage', 'stage' => $second->uuid]))
            ->toThrow(SaleFailed::class, 'already charged');

        expect($sale->fresh()?->grand_total_minor)->toBe(40000);
    });
});

it('refuses a stage from a different visit', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jcSeed();
        $owner = $this->ownerWithCatalogAccess();

        $mine = $this->walkInVisit($seed, $owner, ['completed']);
        $theirs = $this->walkInVisit($seed, $owner, ['completed']);

        $sale = app(CheckoutJourney::class)($mine, $owner);

        /** @var JourneyStage $foreign */
        $foreign = $theirs->stages()->firstOrFail();

        expect(fn () => app(AddSaleLine::class)($sale, $owner, ['kind' => 'journey_stage', 'stage' => $foreign->uuid]))
            ->toThrow(SaleFailed::class, 'not part of this visit');
    });
});

it('leaves the visit untouched, including its customer', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jcSeed();
        $owner = $this->ownerWithCatalogAccess();
        $someoneElse = $this->seedCustomer('Somebody Else', '0770 555 1234');

        $journey = $this->walkInVisit($seed, $owner, ['completed']);
        app(CompleteJourney::class)($journey, $owner);
        $before = $journey->fresh()?->getAttributes();
        $stageBefore = JourneyStage::query()->where('service_journey_id', $journey->id)->firstOrFail()->getAttributes();

        $this->openShift($seed['branch'], $owner);
        $sale = app(CheckoutJourney::class)($journey, $owner);
        app(AdjustSale::class)->customer($sale, $owner, $someoneElse->uuid);
        app(FinalizeSale::class)($sale, $owner);

        // The sale moved to another customer; the visit did not.
        expect($sale->fresh()?->customer_id)->toBe($someoneElse->id)
            ->and($journey->fresh()?->getAttributes())->toBe($before)
            ->and(JourneyStage::query()->where('service_journey_id', $journey->id)->firstOrFail()->getAttributes())->toBe($stageBefore);
    });
});

it('refuses to check out an abandoned visit', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jcSeed();
        $owner = $this->ownerWithCatalogAccess();

        $journey = $this->walkInVisit($seed, $owner, ['waiting']);
        app(AbortJourney::class)($journey, $owner, 'Left');

        expect($journey->fresh()?->status)->toBe(JourneyStatus::Aborted);

        expect(fn () => app(CheckoutJourney::class)($journey->fresh() ?? $journey, $owner))
            ->toThrow(SaleFailed::class, 'abandoned');

        expect(Sale::query()->count())->toBe(0);
    });
});

it('releases the visit when its sale is voided, so it can be charged again', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jcSeed();
        $owner = $this->ownerWithCatalogAccess();
        $this->openShift($seed['branch'], $owner);

        $journey = $this->walkInVisit($seed, $owner, ['completed']);
        app(CompleteJourney::class)($journey, $owner);

        $first = app(CheckoutJourney::class)($journey, $owner);
        app(FinalizeSale::class)($first, $owner);

        // Finalized still counts as the visit's live sale.
        expect(app(CheckoutJourney::class)($journey, $owner)->uuid)->toBe($first->uuid);

        app(CloseSale::class)->void($first, $owner, 'Charged the wrong service');

        $second = app(CheckoutJourney::class)($journey, $owner);

        expect($second->uuid)->not->toBe($first->uuid)
            ->and(Sale::query()->where('service_journey_id', $journey->id)->count())->toBe(2)
            // The voided sale still records which visit it charged.
            ->and($first->fresh()?->service_journey_id)->toBe($journey->id);
    });
});

it('completes a visit without creating a sale, and without the POS at all', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jcSeed();
        $owner = $this->ownerWithCatalogAccess();

        // A center that has no POS.
        $this->revokeEntitlement('pos');

        $journey = $this->walkInVisit($seed, $owner, ['completed']);
        app(CompleteJourney::class)($journey->fresh() ?? $journey, $owner);

        expect($journey->fresh()?->status)->toBe(JourneyStatus::Completed)
            ->and(Sale::query()->count())->toBe(0);
    });
});

it('checks a visit out only at its own branch', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jcSeed();
        $owner = $this->ownerWithCatalogAccess();

        $journey = $this->walkInVisit($seed, $owner, ['completed']);

        $elsewhere = $this->seedBranch('Mansour');
        $cashier = $this->staffWith([Permission::SaleCreate, Permission::SaleView], 'cashier@alpha.test');
        $cashier->forceFill(['all_branches' => false])->save();
        $cashier->syncBranchScope([$elsewhere->id]);
        $cashier->forgetPermissionCache();

        expect(fn () => app(CheckoutJourney::class)($journey, $cashier->fresh() ?? $cashier))
            ->toThrow(AuthorizationException::class);

        expect(Sale::query()->count())->toBe(0);
    });
});

/*
|--------------------------------------------------------------------------
| A visit is invoiced once it is over
|--------------------------------------------------------------------------
|
| A draft may be prepared while the visit runs; it is finalized only once the
| visit is completed, and then carries every service the visit performed.
|
*/

it('prepares and reuses a draft while the visit is still in progress', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jcSeed();
        $owner = $this->ownerWithCatalogAccess();

        $journey = $this->walkInVisit($seed, $owner, ['completed', 'waiting']);

        $first = app(CheckoutJourney::class)($journey, $owner);
        $again = app(CheckoutJourney::class)($journey, $owner);

        // The preview shows what is charged so far and what has not happened yet.
        $review = app(CheckoutReview::class)->forSale($again);

        expect($journey->fresh()?->status)->toBe(JourneyStatus::Active)
            ->and($again->uuid)->toBe($first->uuid)
            ->and($again->status)->toBe(SaleStatus::Draft)
            ->and(Sale::query()->count())->toBe(1)
            ->and(array_column($review, 'performed'))->toBe(['completed', 'waiting'])
            ->and($review[1]['charged'])->toBeNull();
    });
});

it('refuses to finalize a visit\'s sale while the visit is in progress, and consumes nothing', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jcSeed();
        $owner = $this->ownerWithCatalogAccess();
        $this->openShift($seed['branch'], $owner);

        $journey = $this->walkInVisit($seed, $owner, ['completed', 'waiting']);
        $sale = app(CheckoutJourney::class)($journey, $owner);

        expect(fn () => app(FinalizeSale::class)($sale, $owner))
            ->toThrow(SaleFailed::class, 'still in progress');

        expect($sale->fresh()?->status)->toBe(SaleStatus::Draft)
            ->and($sale->fresh()?->cashier_shift_id)->toBeNull()
            ->and(Invoice::query()->count())->toBe(0)
            ->and(DB::connection('tenant')->table('invoice_sequences')->count())->toBe(0)
            ->and(TenantAuditLog::query()->where('action', 'sale.finalized')->count())->toBe(0)
            ->and($journey->fresh()?->status)->toBe(JourneyStatus::Active);
    });
});

it('finalizes the same draft once the visit completes, with every service the visit performed', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jcSeed();
        $owner = $this->ownerWithCatalogAccess();
        $this->openShift($seed['branch'], $owner);

        // Checkout opened after the first service, before the second.
        $journey = $this->walkInVisit($seed, $owner, ['completed', 'waiting']);
        $draft = app(CheckoutJourney::class)($journey, $owner);

        expect(SaleItem::query()->where('sale_id', $draft->id)->count())->toBe(1);

        /** @var JourneyStage $second */
        $second = $journey->stages()->orderBy('position')->get()[1];
        app(TransitionStage::class)($second, StageStatus::InService, $owner);
        app(TransitionStage::class)($second->fresh() ?? $second, StageStatus::Completed, $owner);
        app(CompleteJourney::class)($journey->fresh() ?? $journey, $owner);

        // The desk still holds the draft it prepared, and finalizes THAT.
        $issued = app(FinalizeSale::class)($draft, $owner);

        $completedStages = JourneyStage::query()
            ->where('service_journey_id', $journey->id)
            ->where('status', StageStatus::Completed->value)
            ->pluck('id')
            ->all();

        $finalizedAudit = TenantAuditLog::query()->where('action', 'sale.finalized')->firstOrFail();

        expect($issued->invoice->sale_id)->toBe($draft->id)
            ->and(Sale::query()->count())->toBe(1)
            ->and($draft->fresh()?->status)->toBe(SaleStatus::Finalized)
            // No performed service lost from the published snapshot, none twice.
            ->and($completedStages)->toHaveCount(2)
            ->and(SaleItem::query()->where('sale_id', $draft->id)->whereIn('journey_stage_id', $completedStages)->count())->toBe(2)
            ->and(InvoiceItem::query()->where('invoice_id', $issued->invoice->id)->count())->toBe(2)
            ->and($issued->invoice->grand_total_minor)->toBe(40000)
            ->and($finalizedAudit->meta['visit_lines_added'] ?? null)->toBe(1);
    });
});

it('brings a reopened checkout up to date with services performed since, exactly once', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jcSeed();
        $owner = $this->ownerWithCatalogAccess();
        $this->openShift($seed['branch'], $owner);

        $journey = $this->walkInVisit($seed, $owner, ['completed', 'waiting']);
        $draft = app(CheckoutJourney::class)($journey, $owner);

        /** @var JourneyStage $second */
        $second = $journey->stages()->orderBy('position')->get()[1];
        app(TransitionStage::class)($second, StageStatus::InService, $owner);
        app(TransitionStage::class)($second->fresh() ?? $second, StageStatus::Completed, $owner);

        $reopened = app(CheckoutJourney::class)($journey, $owner);
        app(CheckoutJourney::class)($journey, $owner);

        expect($reopened->uuid)->toBe($draft->uuid)
            ->and(SaleItem::query()->where('sale_id', $draft->id)->count())->toBe(2)
            ->and($reopened->grand_total_minor)->toBe(40000)
            ->and(TenantAuditLog::query()->where('action', 'sale.visit_lines_added')->count())->toBe(1);

        app(CompleteJourney::class)($journey->fresh() ?? $journey, $owner);
        $issued = app(FinalizeSale::class)($reopened, $owner);

        expect(InvoiceItem::query()->where('invoice_id', $issued->invoice->id)->count())->toBe(2)
            ->and($issued->invoice->grand_total_minor)->toBe(40000);
    });
});

it('refuses to finalize the sale of a visit that was abandoned', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jcSeed();
        $owner = $this->ownerWithCatalogAccess();
        $this->openShift($seed['branch'], $owner);

        $journey = $this->walkInVisit($seed, $owner, ['completed', 'waiting']);
        $sale = app(CheckoutJourney::class)($journey, $owner);

        app(AbortJourney::class)($journey->fresh() ?? $journey, $owner, 'Left before the second service');

        expect(fn () => app(FinalizeSale::class)($sale, $owner))
            ->toThrow(SaleFailed::class, 'abandoned');

        expect($sale->fresh()?->status)->toBe(SaleStatus::Draft)
            ->and(Invoice::query()->count())->toBe(0);

        // The draft is discarded instead, which releases the visit.
        app(CloseSale::class)->discard($sale, $owner);

        expect(Sale::query()->count())->toBe(0);
    });
});

it('finalizes a direct sale with no visit behind it, untouched by the visit rule', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jcSeed();
        $owner = $this->ownerWithCatalogAccess();
        $this->openShift($seed['branch'], $owner);

        // A visit is in progress in the building; it has nothing to do with this.
        $this->walkInVisit($seed, $owner, ['waiting']);

        $sale = $this->draftSale($seed['branch'], $owner);
        app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);

        $issued = app(FinalizeSale::class)($sale, $owner);

        expect($sale->fresh()?->status)->toBe(SaleStatus::Finalized)
            ->and($sale->fresh()?->service_journey_id)->toBeNull()
            ->and($issued->invoice->grand_total_minor)->toBe(20000);
    });
});

it('keeps a performed service on the bill: its price can change, with a reason, but the line stays', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jcSeed();
        $owner = $this->ownerWithCatalogAccess();

        $journey = $this->walkInVisit($seed, $owner, ['completed']);
        $sale = app(CheckoutJourney::class)($journey, $owner);
        $line = SaleItem::query()->where('sale_id', $sale->id)->firstOrFail();

        expect(fn () => app(ChangeSaleLine::class)->remove($sale, $line->uuid, $owner))
            ->toThrow(SaleFailed::class, 'stays on the bill');

        app(ChangeSaleLine::class)->overridePrice($sale, $line->uuid, $owner, 0, 'Redo after a complaint');

        expect(SaleItem::query()->where('sale_id', $sale->id)->count())->toBe(1)
            ->and($sale->fresh()?->grand_total_minor)->toBe(0)
            ->and($line->fresh()?->original_unit_price_minor)->toBe(20000)
            ->and($line->fresh()?->price_override_reason)->toBe('Redo after a complaint');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
