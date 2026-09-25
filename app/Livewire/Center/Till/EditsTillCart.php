<?php

declare(strict_types=1);

namespace App\Livewire\Center\Till;

use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\AdjustSale;
use App\Modules\Sales\Application\Actions\ChangeSaleLine;
use App\Modules\Sales\Application\SalesQuery;
use App\Modules\Sales\Application\TillCatalog;
use App\Modules\Sales\Domain\Enums\AdjustmentType;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Pricing\SalePricing;
use InvalidArgumentException;

/**
 * Everything that changes the lines of the open cart.
 *
 * Every button calls the Sales Action the API calls. The only conversion done
 * here is PARSING what a person typed — "12.5", "25000" — into the integer the
 * Action validates, through `Money::fromMajorString()` and
 * `SalePricing::basisPoints()`. Nothing is added, multiplied or rounded; the
 * totals come back from `SalePricing` on the sale row (docs/18-SALES.md §9).
 */
trait EditsTillCart
{
    // ---- a service being configured before it is added ---------------------

    public string $pickService = '';

    public string $pickVariation = '';

    /** @var list<string> */
    public array $pickAddons = [];

    public int $pickQuantity = 1;

    /** Who performed it, when the cashier names someone. */
    public string $pickEmployee = '';

    // ---- custom line, override, adjustment --------------------------------

    public string $customName = '';

    public string $customPrice = '';

    public string $customReason = '';

    public string $overrideLine = '';

    public string $overridePrice = '';

    public string $overrideReason = '';

    public string $adjustmentType = 'discount_percent';

    public string $adjustmentValue = '';

    public string $adjustmentReason = '';

    public function pick(string $serviceUuid): void
    {
        $this->pickService = $serviceUuid;
        $this->reset(['pickVariation', 'pickAddons', 'pickQuantity', 'pickEmployee']);
    }

    public function cancelPick(): void
    {
        $this->reset(['pickService', 'pickVariation', 'pickAddons', 'pickQuantity', 'pickEmployee']);
    }

    public function addService(AddSaleLine $add, SalesQuery $query): void
    {
        $this->attempt(function () use ($add, $query): void {
            $add($query->find($this->sale, $this->user()), $this->user(), [
                'kind' => 'service',
                'service' => $this->pickService,
                'variation' => $this->pickVariation === '' ? null : $this->pickVariation,
                'addons' => array_values(array_filter($this->pickAddons, 'is_string')),
                'quantity' => max(1, $this->pickQuantity),
                'employee' => $this->pickEmployee === '' ? null : $this->pickEmployee,
            ]);

            $this->reset(['pickService', 'pickVariation', 'pickAddons', 'pickQuantity', 'pickEmployee']);
        });
    }

    public function addProduct(string $productUuid, AddSaleLine $add, SalesQuery $query): void
    {
        $this->attempt(function () use ($productUuid, $add, $query): void {
            $add($query->find($this->sale, $this->user()), $this->user(), ['kind' => 'product', 'product' => $productUuid]);
        });
    }

    /**
     * Enter in the search box: a scanned barcode or SKU that names exactly one
     * product goes straight onto the sale.
     */
    public function scan(TillCatalog $catalog, AddSaleLine $add, SalesQuery $query): void
    {
        $product = $catalog->productByCode($this->search);

        if ($product === null || $this->sale === '') {
            return;
        }

        $this->addProduct($product, $add, $query);

        if ($this->error === '') {
            $this->search = '';
        }
    }

    public function addStage(string $stageUuid, AddSaleLine $add, SalesQuery $query): void
    {
        $this->attempt(function () use ($stageUuid, $add, $query): void {
            $add($query->find($this->sale, $this->user()), $this->user(), ['kind' => 'journey_stage', 'stage' => $stageUuid]);
        });
    }

    public function addCustom(AddSaleLine $add, SalesQuery $query): void
    {
        $this->attempt(function () use ($add, $query): void {
            $sale = $query->find($this->sale, $this->user());

            $add($sale, $this->user(), [
                'kind' => 'custom',
                'name' => $this->customName,
                'unit_price_minor' => $this->minor($this->customPrice, $sale),
                'reason' => $this->customReason,
            ]);

            $this->reset(['customName', 'customPrice', 'customReason']);
        });
    }

    public function quantity(string $lineUuid, int $quantity, ChangeSaleLine $change, SalesQuery $query): void
    {
        $this->attempt(function () use ($lineUuid, $quantity, $change, $query): void {
            $change->update($query->find($this->sale, $this->user()), $lineUuid, $this->user(), ['quantity' => $quantity]);
        });
    }

    public function removeLine(string $lineUuid, ChangeSaleLine $change, SalesQuery $query): void
    {
        $this->attempt(function () use ($lineUuid, $change, $query): void {
            $change->remove($query->find($this->sale, $this->user()), $lineUuid, $this->user());
        });
    }

    public function startOverride(string $lineUuid): void
    {
        $this->overrideLine = $lineUuid;
        $this->reset(['overridePrice', 'overrideReason']);
    }

    public function overrideLinePrice(ChangeSaleLine $change, SalesQuery $query): void
    {
        $this->attempt(function () use ($change, $query): void {
            $sale = $query->find($this->sale, $this->user());

            $change->overridePrice($sale, $this->overrideLine, $this->user(), $this->minor($this->overridePrice, $sale), $this->overrideReason);

            $this->reset(['overrideLine', 'overridePrice', 'overrideReason']);
        });
    }

    public function clearOverride(string $lineUuid, ChangeSaleLine $change, SalesQuery $query): void
    {
        $this->attempt(function () use ($lineUuid, $change, $query): void {
            $change->overridePrice($query->find($this->sale, $this->user()), $lineUuid, $this->user(), null, null);
        });
    }

    public function addAdjustment(AdjustSale $adjust, SalesQuery $query): void
    {
        $this->attempt(function () use ($adjust, $query): void {
            $sale = $query->find($this->sale, $this->user());
            $type = AdjustmentType::tryFrom($this->adjustmentType);

            // A benefit is applied from the customer's benefits, never typed.
            if ($type === null || $type->isBenefit()) {
                $type = AdjustmentType::DiscountFixed;
            }

            $value = $type->isPercentage()
                ? SalePricing::basisPoints($this->adjustmentValue)
                : $this->minor($this->adjustmentValue, $sale);

            $adjust->add($sale, $this->user(), $type, $value, $this->adjustmentReason);

            $this->reset(['adjustmentValue', 'adjustmentReason']);
        });
    }

    public function removeAdjustment(string $uuid, AdjustSale $adjust, SalesQuery $query): void
    {
        $this->attempt(function () use ($uuid, $adjust, $query): void {
            $adjust->remove($query->find($this->sale, $this->user()), $uuid, $this->user());
        });
    }

    /**
     * Parses a TYPED amount into minor units — the one conversion this screen
     * does, and it is a parse, not arithmetic.
     *
     * @throws SaleFailed
     */
    private function minor(string $typed, Sale $sale): int
    {
        try {
            return Money::fromMajorString($typed, Currency::tryFrom($sale->currency) ?? Currency::default())->minor;
        } catch (InvalidArgumentException) {
            throw SaleFailed::policy('Enter an amount like 25000.');
        }
    }
}
