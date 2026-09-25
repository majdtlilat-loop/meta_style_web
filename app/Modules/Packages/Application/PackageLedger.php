<?php

declare(strict_types=1);

namespace App\Modules\Packages\Application;

use App\Kernel\Audit\Actor;
use App\Modules\Packages\Domain\Enums\PackageMovement;
use App\Modules\Packages\Domain\Enums\PackageSource;
use App\Modules\Packages\Domain\Models\CustomerPackage;
use App\Modules\Packages\Domain\Models\CustomerPackageItem;
use App\Modules\Packages\Domain\Models\PackageTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * The only writer of `package_transactions`, and the only place "sessions
 * left" is computed.
 *
 *     left(item) = Σ allocation − Σ redemption + Σ reversal − Σ cancellation
 *
 * Proven from the history every time; there is no counter to drift. Every
 * write runs on the LOCKED customer package, in the caller's transaction, and
 * is idempotent per (source, source uuid, kind)
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §14).
 */
final class PackageLedger
{
    public function append(
        CustomerPackage $locked,
        CustomerPackageItem $item,
        PackageMovement $kind,
        int $quantity,
        PackageSource $source,
        string $sourceUuid,
        CarbonInterface $occurredAt,
        ?string $saleUuid = null,
        ?int $journeyStageId = null,
        ?string $reason = null,
        ?Actor $actor = null,
    ): PackageTransaction {
        if (DB::connection('tenant')->transactionLevel() < 1) {
            throw new RuntimeException('PackageLedger::append() must run inside the transaction that used the package.');
        }

        if ($quantity < 1 || $quantity > 65_535 || $item->customer_package_id !== (int) $locked->getKey()) {
            throw new LogicException('A package movement moves a positive quantity of one of its own items.');
        }

        /** @var PackageTransaction|null $existing */
        $existing = PackageTransaction::query()
            ->where('source_type', $source->value)
            ->where('source_uuid', $sourceUuid)
            ->where('kind', $kind->value)
            ->first();

        if ($existing instanceof PackageTransaction) {
            return $existing;
        }

        if ($kind->sign() < 0 && ($this->left($locked)[(int) $item->getKey()] ?? 0) < $quantity) {
            // A caller that could take an item below zero has a bug; the
            // redemption checks first and says so plainly.
            throw new LogicException('A package movement cannot take an item below zero.');
        }

        /** @var PackageTransaction $transaction */
        $transaction = PackageTransaction::query()->create([
            'customer_package_id' => $locked->getKey(),
            'customer_package_item_id' => $item->getKey(),
            'kind' => $kind,
            'quantity' => $quantity,
            'source_type' => $source,
            'source_uuid' => $sourceUuid,
            'sale_uuid' => $saleUuid,
            'journey_stage_id' => $journeyStageId,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 190),
            'actor_id' => $actor?->id,
            'actor_label' => $actor?->label,
            'occurred_at' => $occurredAt,
            'created_at' => now()->utc(),
        ]);

        return $transaction;
    }

    /**
     * Sessions left, per item id — one grouped query.
     *
     * @return array<int, int>
     */
    public function left(CustomerPackage $package): array
    {
        return self::leftFor([(int) $package->getKey()])[(int) $package->getKey()] ?? [];
    }

    /**
     * Sessions left for several packages at once, per package then per item —
     * one grouped query for any number of packages.
     *
     * @param  list<int>  $packageIds
     * @return array<int, array<int, int>>
     */
    public static function leftFor(array $packageIds): array
    {
        if ($packageIds === []) {
            return [];
        }

        $rows = PackageTransaction::query()
            ->whereIn('customer_package_id', $packageIds)
            ->groupBy('customer_package_id', 'customer_package_item_id', 'kind')
            ->selectRaw('customer_package_id, customer_package_item_id, kind, SUM(quantity) AS total')
            ->toBase()
            ->get();

        $left = [];

        foreach ($rows as $row) {
            $package = (int) $row->customer_package_id;
            $item = (int) $row->customer_package_item_id;
            $kind = PackageMovement::from((string) $row->kind);

            $left[$package][$item] = ($left[$package][$item] ?? 0) + $kind->sign() * (int) $row->total;
        }

        return $left;
    }
}
