<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application\Actions;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Sales\Application\JourneyChargeCandidates;
use App\Modules\Sales\Application\LinePriceResolver;
use App\Modules\Sales\Application\SaleLines;
use App\Modules\Sales\Application\SalesAccess;
use App\Modules\Sales\Application\SalesAudit;
use App\Modules\Sales\Domain\Data\LineSnapshot;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Models\SaleItem;
use App\Modules\Sales\Domain\SaleMutation;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Adds a line to a draft: a service, a product, a performed stage of the sale's
 * own visit, or — with `sale.adjust` and a reason — a custom line.
 *
 * The price is resolved HERE, server-side, from the catalog or the visit's
 * snapshot. A browser can ask for "Haircut, Medium, with a wash"; it can never
 * say what that costs (docs/18-SALES.md §9).
 */
final class AddSaleLine
{
    public function __construct(
        private readonly SalesAccess $access,
        private readonly LinePriceResolver $prices,
        private readonly JourneyChargeCandidates $candidates,
        private readonly SaleLines $lines,
        private readonly SaleMutation $mutation,
        private readonly SalesAudit $audit,
    ) {}

    /**
     * @param  array{kind: string, service?: string|null, variation?: string|null, addons?: list<string>, product?: string|null, stage?: string|null, name?: string|null, unit_price_minor?: int|null, reason?: string|null, quantity?: int|null, note?: string|null}  $input
     *
     * @throws SaleFailed
     * @throws AuthorizationException
     */
    public function __invoke(Sale $sale, User $actingUser, array $input): SaleItem
    {
        $this->access->ensure($actingUser, Permission::SaleCreate, $sale->branch_id, 'You may not change sales.');

        $reason = $input['kind'] === 'custom' ? $this->customReason($sale, $actingUser, $input) : null;

        $snapshot = match ($input['kind']) {
            'service' => $this->prices->service(
                $sale,
                (string) ($input['service'] ?? ''),
                $input['variation'] ?? null,
                $input['addons'] ?? [],
            ),
            'product' => $this->prices->product($sale, (string) ($input['product'] ?? '')),
            'journey_stage' => $this->stage($sale, (string) ($input['stage'] ?? '')),
            'custom' => $this->prices->custom($sale, (string) ($input['name'] ?? ''), (int) ($input['unit_price_minor'] ?? -1)),
            default => throw SaleFailed::policy('That is not something a sale can charge for.'),
        };

        $quantity = (int) ($input['quantity'] ?? 1);

        // A performed stage is one service, performed once.
        if ($snapshot->journeyStageId !== null) {
            $quantity = 1;
        }

        [, $item] = $this->mutation->apply(
            $sale,
            fn (Sale $locked): SaleItem => $this->lines->append($locked, $snapshot, $quantity, $input['note'] ?? null),
        );

        $this->audit->record('sale.line_added', $actingUser, $sale, after: [
            'line' => $item->uuid,
            'kind' => $item->kind->value,
            'quantity' => $item->quantity,
            'unit_price_minor' => $item->unit_price_minor,
            'price_source' => $item->price_source->value,
        ], reason: $reason);

        return $item;
    }

    private function stage(Sale $sale, string $stageUuid): LineSnapshot
    {
        if ($sale->service_journey_id === null) {
            throw SaleFailed::policy('This sale is not linked to a visit.');
        }

        /** @var JourneyStage|null $stage */
        $stage = JourneyStage::query()
            ->where('uuid', $stageUuid)
            // Never another visit's service, and never another branch's.
            ->where('service_journey_id', $sale->service_journey_id)
            ->with('item.addons')
            ->first();

        if (! $stage instanceof JourneyStage) {
            throw SaleFailed::policy('That service is not part of this visit.');
        }

        return $this->candidates->forStage($stage, $sale->currency);
    }

    /**
     * A price no catalog agreed to needs the same authority as a discount, and
     * a reason.
     *
     * @param  array{reason?: string|null}  $input
     */
    private function customReason(Sale $sale, User $actingUser, array $input): string
    {
        $this->access->ensure($actingUser, Permission::SaleAdjust, $sale->branch_id, 'You may not add custom charges.');

        $reason = trim((string) ($input['reason'] ?? ''));

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 190) {
            throw SaleFailed::policy('A custom charge needs a reason.');
        }

        return $reason;
    }
}
