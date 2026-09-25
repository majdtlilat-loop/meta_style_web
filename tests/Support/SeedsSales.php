<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\SaaS\Enums\OverrideMode;
use App\Kernel\SaaS\Models\TenantEntitlementOverride;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\Booking\Application\Actions\CreateAppointment;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Sales\Application\Actions\CreateDraftSale;
use App\Modules\Sales\Application\Actions\ManageCashierShift;
use App\Modules\Sales\Domain\Models\CashierShift;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\ServiceJourney\Application\Actions\CheckInAppointment;
use App\Modules\ServiceJourney\Application\Actions\CreateWalkInVisit;
use App\Modules\ServiceJourney\Application\Actions\TransitionStage;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Carts, shifts, products and visits for the Phase 9 tests.
 *
 * Thin on purpose, like the other seeders: each helper writes what one test
 * needs and goes through the real Actions wherever a real rule is involved, so a
 * failure points at the rule rather than at a fixture that bypassed it.
 *
 * ## `pos` comes from the plan; `printing` is bought
 *
 * The seeded trial plan already owns `pos` — that is the pre-Phase-9 matrix,
 * untouched — and does NOT own `printing`. So sales tests run without an
 * override, and every print test buys printing explicitly. That split is what
 * makes "POS without Printing still sees the invoice digitally" a meaningful
 * assertion rather than a vacuous one (docs/18-SALES.md §23).
 */
trait SeedsSales
{
    protected function grantPrinting(?string $tenantId = null): void
    {
        $tenantId ??= app(TenantContext::class)->id();

        TenantEntitlementOverride::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'entitlement' => 'printing'],
            ['mode' => OverrideMode::Grant, 'reason' => 'printing sold as an add-on', 'expires_at' => null],
        );

        app(Entitlements::class)->invalidate((string) $tenantId);
    }

    protected function revokeEntitlement(string $key, ?string $tenantId = null): void
    {
        $tenantId ??= app(TenantContext::class)->id();

        TenantEntitlementOverride::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'entitlement' => $key],
            ['mode' => OverrideMode::Revoke, 'reason' => 'withdrawn in a test', 'expires_at' => null],
        );

        app(Entitlements::class)->invalidate((string) $tenantId);
    }

    protected function openShift(Branch $branch, User $user): CashierShift
    {
        return app(ManageCashierShift::class)->open($branch->uuid, $user);
    }

    protected function draftSale(Branch $branch, User $user): Sale
    {
        return app(CreateDraftSale::class)($branch->uuid, $user, null, (string) Str::uuid());
    }

    protected function seedProduct(string $name = 'Shampoo', int $priceMinor = 12000, ?string $barcode = null): Product
    {
        /** @var Product $product */
        $product = Product::query()->create([
            'name' => TranslatedText::fromArray(['en' => $name, 'ar' => $name]),
            'barcode' => $barcode,
            'price_minor' => $priceMinor,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        return $product;
    }

    /**
     * A walk-in visit whose stages have been performed as asked: `completed`,
     * `skipped`, or left `waiting`.
     *
     * @param  list<string>  $outcomes  one per stage, in order
     */
    protected function walkInVisit(array $seed, User $user, array $outcomes = ['completed'], ?array $serviceUuids = null): ServiceJourney
    {
        $journey = app(CreateWalkInVisit::class)(
            new WalkInRequest(
                branchUuid: $seed['branch']->uuid,
                serviceUuids: $serviceUuids ?? array_fill(0, count($outcomes), $seed['service']->uuid),
                name: 'Walk-in Sara',
                idempotencyToken: (string) Str::uuid(),
            ),
            $user,
        );

        $this->perform($journey, $user, $outcomes);

        return $journey->fresh() ?? $journey;
    }

    /**
     * A BOOKED visit, checked in, with its stage performed as asked.
     *
     * @param  list<string>  $addonUuids
     */
    protected function bookedVisit(array $seed, User $user, ?string $variationUuid = null, array $addonUuids = [], string $outcome = 'completed'): ServiceJourney
    {
        $appointment = app(CreateAppointment::class)(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                startsAt: $this->localTime($seed['branch'], CarbonImmutable::now($seed['branch']->timezone)->addDays(33)->format('Y-m-d'), '10:00'),
                lines: [new BookingLine(serviceUuid: $seed['service']->uuid, variationUuid: $variationUuid, addonUuids: $addonUuids)],
                customer: CustomerRef::details('Booked Layla', '+96475'.random_int(10000000, 99999999)),
            ),
            BookingActor::staff($user),
        )->appointment;

        $journey = app(CheckInAppointment::class)($appointment, $user);

        $this->perform($journey, $user, [$outcome]);

        return $journey->fresh() ?? $journey;
    }

    /**
     * @param  list<string>  $outcomes
     */
    private function perform(ServiceJourney $journey, User $user, array $outcomes): void
    {
        /** @var list<JourneyStage> $stages */
        $stages = $journey->stages()->orderBy('position')->get()->all();

        foreach ($stages as $index => $stage) {
            $outcome = $outcomes[$index] ?? 'waiting';

            if ($outcome === 'completed') {
                app(TransitionStage::class)($stage, StageStatus::InService, $user);
                app(TransitionStage::class)($stage->fresh() ?? $stage, StageStatus::Completed, $user);
            } elseif ($outcome === 'skipped') {
                app(TransitionStage::class)($stage, StageStatus::Skipped, $user, ['reason' => 'Changed their mind']);
            }
        }
    }
}
