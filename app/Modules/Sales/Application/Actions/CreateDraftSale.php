<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application\Actions;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Currency;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Sales\Application\SalesAccess;
use App\Modules\Sales\Application\SalesAudit;
use App\Modules\Sales\Domain\Enums\SaleSource;
use App\Modules\Sales\Domain\Enums\SaleStatus;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Opens an empty cart at the till: a direct POS sale with no visit.
 *
 * A customer who walks in only to buy a bottle of shampoo gets a sale and
 * nothing else — no fake appointment, no fake visit (docs/18-SALES.md §31).
 *
 * ## Idempotent on a client token
 *
 * The till sends a token with "new sale". A double-tap sends it twice; the
 * second lookup finds the draft the first one made, and the unique index turns
 * a race between the two into the same answer.
 */
final class CreateDraftSale
{
    public function __construct(
        private readonly SalesAccess $access,
        private readonly SalesAudit $audit,
    ) {}

    /**
     * @throws SaleFailed
     * @throws AuthorizationException
     */
    public function __invoke(string $branchUuid, User $actingUser, ?string $customerUuid = null, ?string $idempotencyToken = null): Sale
    {
        /** @var Branch|null $branch */
        $branch = Branch::query()->where('uuid', $branchUuid)->first();

        if (! $branch instanceof Branch || ! $branch->is_active || $branch->archived_at !== null) {
            throw SaleFailed::policy('That branch is not available for sales.');
        }

        $this->access->ensure($actingUser, Permission::SaleCreate, $branch->id, 'You may not open sales.');

        $token = $this->token($idempotencyToken);

        if ($token !== null) {
            $existing = $this->byToken($token, $branch);

            if ($existing instanceof Sale) {
                return $existing;
            }
        }

        $customer = $this->customer($customerUuid);

        try {
            /** @var Sale $sale */
            $sale = Sale::query()->create([
                'branch_id' => $branch->id,
                'customer_id' => $customer?->id,
                'source' => SaleSource::Pos,
                'status' => SaleStatus::Draft,
                // One currency per sale, snapshotted now: the center's.
                'currency' => Currency::default()->value,
                // Stated rather than left to column defaults, so the model this
                // Action returns describes the row it wrote.
                'subtotal_minor' => 0,
                'discount_total_minor' => 0,
                'surcharge_total_minor' => 0,
                'tax_total_minor' => 0,
                'grand_total_minor' => 0,
                'idempotency_token' => $token,
                'created_by_id' => $actingUser->uuid,
                'created_by_label' => $actingUser->name,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            $existing = $token === null ? null : $this->byToken($token, $branch);

            if (! $existing instanceof Sale) {
                throw $e;
            }

            return $existing;
        }

        $this->audit->record('sale.created', $actingUser, $sale, after: [
            'source' => SaleSource::Pos->value,
            'status' => SaleStatus::Draft->value,
            'has_customer' => $customer !== null,
        ]);

        return $sale;
    }

    private function token(?string $token): ?string
    {
        $token = $token === null ? null : trim($token);

        if ($token === null || $token === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9\-_]{8,64}$/', $token) !== 1) {
            throw SaleFailed::policy('That request token is not valid.');
        }

        return $token;
    }

    private function byToken(string $token, Branch $branch): ?Sale
    {
        /** @var Sale|null $existing */
        $existing = Sale::query()->where('idempotency_token', $token)->first();

        if ($existing instanceof Sale && $existing->branch_id !== $branch->id) {
            // A token reused for a different branch is a client bug, not a retry.
            throw SaleFailed::policy('That request token was already used for another sale.');
        }

        return $existing;
    }

    private function customer(?string $uuid): ?Customer
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        /** @var Customer|null $customer */
        $customer = Customer::query()->where('uuid', $uuid)->whereNull('archived_at')->first();

        if (! $customer instanceof Customer) {
            throw SaleFailed::policy('That customer could not be found.');
        }

        return $customer;
    }
}
