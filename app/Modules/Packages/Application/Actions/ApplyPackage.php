<?php

declare(strict_types=1);

namespace App\Modules\Packages\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Packages\Application\ActivatePackages;
use App\Modules\Packages\Application\PackageLedger;
use App\Modules\Packages\Application\PackagesAccess;
use App\Modules\Packages\Application\PackagesAudit;
use App\Modules\Packages\Application\RedeemedSessions;
use App\Modules\Packages\Domain\Enums\PackageMovement;
use App\Modules\Packages\Domain\Enums\PackageSource;
use App\Modules\Packages\Domain\Exceptions\PackagesFailed;
use App\Modules\Packages\Domain\Models\CustomerPackage;
use App\Modules\Packages\Domain\Models\CustomerPackageItem;
use App\Modules\Sales\Application\SaleBenefits;
use App\Modules\Sales\Domain\Data\BenefitGrant;
use App\Modules\Sales\Domain\Enums\SaleItemKind;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Models\SaleAdjustment;
use App\Modules\Sales\Domain\Models\SaleItem;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/**
 * Covers a service line with the customer's package at checkout — and takes
 * that back.
 *
 *     BEGIN
 *       lock the sale                          (SaleBenefits)
 *       the line is a SERVICE on this draft, with no other benefit
 *       lock the customer's package            ← the second lock, always
 *       it is theirs, active, not expired
 *       an item covers this service (and variation)
 *       enough sessions left — proven from the history
 *       write the `redemption` (the performed stage, when the line has one)
 *       the discount: the line's unit price × sessions, on that line only
 *     COMMIT
 *
 * Consumption follows PERFORMANCE: a stage of the visit, whose completion is
 * the proof, or a service charged directly at the till, which somebody must
 * CONFIRM was performed — never when an appointment is booked, and never
 * merely because a line was added to a cart. One unit of a line uses one session;
 * covering 1 of a line of 2 discounts exactly one unit, and add-ons stay
 * charged (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§16–17).
 *
 * Uses a package the customer ALREADY PAID FOR, so it needs `package.view` and
 * the till's own `sale.create` — not the `packages` entitlement, which governs
 * selling new ones (§22).
 */
final class ApplyPackage
{
    public const SOURCE = 'package';

    public function __construct(
        private readonly PackagesAccess $access,
        private readonly PackageLedger $ledger,
        private readonly ActivatePackages $activation,
        private readonly SaleBenefits $benefits,
        private readonly RedeemedSessions $redeemed,
        private readonly PackagesAudit $audit,
    ) {}

    /**
     * @throws PackagesFailed
     * @throws SaleFailed
     * @throws AuthorizationException
     */
    public function apply(Sale $sale, User $actingUser, string $lineUuid, string $packageUuid, int $sessions = 1, bool $performed = false, ?CarbonImmutable $now = null): SaleAdjustment
    {
        $this->access->authorize($actingUser, Permission::PackageView, 'You may not use customers\' packages.');

        if ($sessions < 1 || $sessions > 999) {
            throw PackagesFailed::policy('Use at least one session.');
        }

        $at = ($now ?? CarbonImmutable::now())->utc();

        // A package the customer paid for but a lost callback never activated
        // is activated first, from the sale and its payments (§1).
        if ($sale->customer_id !== null) {
            $this->activation->reconcileCustomer($sale->customer_id);
        }

        return $this->benefits->apply($sale, $actingUser, $lineUuid, function (Sale $locked, ?SaleItem $line) use ($packageUuid, $sessions, $performed, $actingUser, $at): BenefitGrant {
            if (! $line instanceof SaleItem || $line->kind !== SaleItemKind::Service || $line->service_id === null) {
                throw PackagesFailed::policy('A package covers a service line.');
            }

            if ($locked->customer_id === null) {
                throw PackagesFailed::policy('Attach the customer to the sale before using their package.');
            }

            /** @var CustomerPackage|null $package */
            $package = CustomerPackage::query()->where('uuid', $packageUuid)->lockForUpdate()->first();

            if (! $package instanceof CustomerPackage || $package->customer_id !== $locked->customer_id) {
                throw PackagesFailed::policy('That package is not this customer\'s.');
            }

            if (! $package->isUsable($at)) {
                throw PackagesFailed::invalidTransition('That package is '.$package->state($at).'.');
            }

            if ($sessions > $line->quantity) {
                throw PackagesFailed::policy('That line has only '.$line->quantity.' to cover.');
            }

            $item = $package->items()->get()->first(
                static fn (CustomerPackageItem $candidate): bool => $candidate->covers((int) $line->service_id, $line->service_variation_id),
            );

            if (! $item instanceof CustomerPackageItem) {
                throw PackagesFailed::policy('That package does not cover this service.');
            }

            $left = $this->ledger->left($package)[(int) $item->getKey()] ?? 0;

            if ($left < $sessions) {
                throw PackagesFailed::policy('Only '.$left.' sessions of it are left.', ['left' => $left]);
            }

            $amount = $line->unit_price_minor * $sessions;

            if ($amount < 1) {
                throw PackagesFailed::policy('That service costs nothing here, so there is nothing to cover.');
            }

            // A session is spent on a service that was PERFORMED. A visit line
            // proves it — the stage it came from is completed. A line typed at
            // the till proves nothing by itself, so somebody has to say so
            // (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §16).
            $performance = $this->performance($line, $performed);

            $redemption = (string) Str::uuid();
            $actor = Actor::staff($actingUser);

            $this->ledger->append($package, $item, PackageMovement::Redemption, $sessions,
                PackageSource::Benefit, $redemption, $at,
                saleUuid: $locked->uuid, journeyStageId: $line->journey_stage_id, reason: 'Covered at checkout', actor: $actor);

            $this->audit->record('package.redeemed', $actor, $package, $package->uuid,
                after: ['sessions' => $sessions, 'left' => $left - $sessions, 'amount_minor' => $amount, 'performance' => $performance],
                meta: ['sale' => $locked->uuid, 'line' => $line->uuid, 'redemption' => $redemption],
                severity: AuditSeverity::Notice,
            );

            return new BenefitGrant(self::SOURCE, $redemption, $amount, 'Package: '.$package->name->get(app()->getLocale()).' ×'.$sessions);
        });
    }

    /**
     * How this line proves the service was performed.
     *
     * A visit line carries the stage it was performed in, and that stage must
     * be completed — the visit board is the proof. Sales already refuses to
     * charge an unperformed stage (`JourneyChargeCandidates::forStage`), so
     * that check here is a second gate rather than the first.
     *
     * A line typed at the till has no such record, so a package may only cover
     * it when the person at the till confirms the service was actually
     * performed; adding a line to a cart is not performance (§16).
     *
     * @throws PackagesFailed
     */
    private function performance(SaleItem $line, bool $confirmed): string
    {
        if ($line->journey_stage_id !== null) {
            /** @var JourneyStage|null $stage */
            $stage = JourneyStage::query()->whereKey($line->journey_stage_id)->first();

            if (! $stage instanceof JourneyStage || $stage->status !== StageStatus::Completed) {
                throw PackagesFailed::policy('That service has not been performed yet.');
            }

            return 'journey_stage';
        }

        if (! $confirmed) {
            throw PackagesFailed::policy('Confirm that the service was performed before using a package session.');
        }

        return 'staff_confirmed';
    }

    /**
     * @throws SaleFailed
     * @throws AuthorizationException
     */
    public function withdraw(Sale $sale, User $actingUser, string $lineUuid): void
    {
        $this->access->authorize($actingUser, Permission::PackageView, 'You may not use customers\' packages.');

        $adjustment = SaleAdjustment::query()
            ->where('sale_id', $sale->getKey())
            ->where('source_type', self::SOURCE)
            ->whereIn('sale_item_id', SaleItem::query()->select('id')->where('sale_id', $sale->getKey())->where('uuid', $lineUuid))
            ->first();

        if (! $adjustment instanceof SaleAdjustment || $adjustment->source_reference === null) {
            throw PackagesFailed::policy('No package covers that line.');
        }

        $this->benefits->withdraw($sale, $actingUser, self::SOURCE, $adjustment->source_reference,
            function (Sale $locked, SaleAdjustment $benefit) use ($actingUser): void {
                $this->redeemed->giveBack((string) $benefit->source_reference, 'Withdrawn from the sale', Actor::staff($actingUser));
            },
        );
    }
}
