<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\AdjustSale;
use App\Modules\Sales\Application\Actions\ChangeSaleLine;
use App\Modules\Sales\Application\Actions\CheckoutJourney;
use App\Modules\Sales\Application\Actions\CloseSale;
use App\Modules\Sales\Application\Actions\CreateDraftSale;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Application\Actions\RotateInvoiceLink;
use App\Modules\Sales\Application\Actions\SetBranchInvoicePrefix;
use App\Modules\Sales\Application\InvoiceRenderer;
use App\Modules\Sales\Application\SalesAccess;
use App\Modules\Sales\Application\SalesPresenter;
use App\Modules\Sales\Application\SalesQuery;
use App\Modules\Sales\Domain\Enums\AdjustmentType;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The staff sales surface: carts, checkout, finalization, invoices.
 *
 * Every method validates, calls ONE Action or query, and returns a presented
 * array. There is no pricing and no lifecycle rule here — the Livewire till
 * calls the same Actions, and an architecture test scans this directory for
 * total arithmetic (docs/18-SALES.md §42).
 *
 * NO PAYMENT ENDPOINT. Money settlement is Phase 10.
 */
final class SalesController extends Controller
{
    public function index(Request $request, SalesQuery $query, SalesPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'branch' => ['required', 'string'],
            'status' => ['nullable', 'string', 'in:draft,finalized,voided'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.SalesQuery::MAX_LIST],
        ]);

        $sales = $query->forBranch(
            $user,
            $validated['branch'],
            $validated['status'] ?? null,
            $validated['date'] ?? null,
            (int) ($validated['limit'] ?? 50),
        );

        return ApiResponse::data([
            'sales' => array_map(fn (Sale $sale): array => $presenter->sale($sale, $user, withLines: false), $sales),
        ]);
    }

    public function show(Request $request, string $uuid, SalesQuery $query, SalesPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        return ApiResponse::data(['sale' => $presenter->sale($query->find($uuid, $user), $user)]);
    }

    public function store(Request $request, CreateDraftSale $create, SalesQuery $query, SalesPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'branch' => ['required', 'string'],
            'customer' => ['nullable', 'string'],
            'idempotency_token' => ['nullable', 'string', 'max:64'],
        ]);

        $sale = $create($validated['branch'], $user, $validated['customer'] ?? null, $validated['idempotency_token'] ?? null);

        return ApiResponse::data(['sale' => $presenter->sale($query->find($sale->uuid, $user), $user)], 201);
    }

    public function destroy(Request $request, string $uuid, SalesQuery $query, CloseSale $close): JsonResponse
    {
        $user = $this->user($request);

        $close->discard($query->find($uuid, $user), $user);

        return ApiResponse::data(['discarded' => true]);
    }

    public function checkout(Request $request, string $journeyUuid, CheckoutJourney $checkout, SalesQuery $query, SalesPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        /** @var ServiceJourney|null $journey */
        $journey = ServiceJourney::query()->with('appointment')->where('uuid', $journeyUuid)->first();

        if (! $journey instanceof ServiceJourney || ! $user->canAccessBranch($journey->branchId())) {
            throw new NotFoundHttpException;
        }

        $sale = $checkout($journey, $user);

        return ApiResponse::data(['sale' => $presenter->sale($query->find($sale->uuid, $user), $user)]);
    }

    public function addLine(Request $request, string $uuid, AddSaleLine $add, SalesQuery $query, SalesPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'kind' => ['required', 'string', 'in:service,product,custom,journey_stage'],
            'service' => ['nullable', 'string'],
            'variation' => ['nullable', 'string'],
            'addons' => ['nullable', 'array', 'max:20'],
            'addons.*' => ['string'],
            'product' => ['nullable', 'string'],
            'stage' => ['nullable', 'string'],
            'name' => ['nullable', 'string', 'max:120'],
            // Minor units, as an integer. A custom line is the only request that
            // carries a price at all, and it needs `sale.adjust` plus a reason.
            'unit_price_minor' => ['nullable', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:190'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'note' => ['nullable', 'string', 'max:190'],
        ]);

        /** @var array{kind: string, service?: string|null, variation?: string|null, addons?: list<string>, product?: string|null, stage?: string|null, name?: string|null, unit_price_minor?: int|null, reason?: string|null, quantity?: int|null, note?: string|null} $validated */
        $add($query->find($uuid, $user), $user, $validated);

        return ApiResponse::data(['sale' => $presenter->sale($query->find($uuid, $user), $user)], 201);
    }

    public function updateLine(Request $request, string $uuid, string $lineUuid, ChangeSaleLine $change, SalesQuery $query, SalesPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'note' => ['nullable', 'string', 'max:190'],
        ]);

        /** @var array{quantity?: int|null, note?: string|null} $validated */
        $change->update($query->find($uuid, $user), $lineUuid, $user, $validated);

        return ApiResponse::data(['sale' => $presenter->sale($query->find($uuid, $user), $user)]);
    }

    public function removeLine(Request $request, string $uuid, string $lineUuid, ChangeSaleLine $change, SalesQuery $query, SalesPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $change->remove($query->find($uuid, $user), $lineUuid, $user);

        return ApiResponse::data(['sale' => $presenter->sale($query->find($uuid, $user), $user)]);
    }

    public function overridePrice(Request $request, string $uuid, string $lineUuid, ChangeSaleLine $change, SalesQuery $query, SalesPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'unit_price_minor' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'min:3', 'max:190'],
        ]);

        $change->overridePrice($query->find($uuid, $user), $lineUuid, $user, (int) $validated['unit_price_minor'], $validated['reason']);

        return ApiResponse::data(['sale' => $presenter->sale($query->find($uuid, $user), $user)]);
    }

    public function clearPriceOverride(Request $request, string $uuid, string $lineUuid, ChangeSaleLine $change, SalesQuery $query, SalesPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $change->overridePrice($query->find($uuid, $user), $lineUuid, $user, null, null);

        return ApiResponse::data(['sale' => $presenter->sale($query->find($uuid, $user), $user)]);
    }

    public function customer(Request $request, string $uuid, AdjustSale $adjust, SalesQuery $query, SalesPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate(['customer' => ['nullable', 'string']]);

        $adjust->customer($query->find($uuid, $user), $user, $validated['customer'] ?? null);

        return ApiResponse::data(['sale' => $presenter->sale($query->find($uuid, $user), $user)]);
    }

    public function addAdjustment(Request $request, string $uuid, AdjustSale $adjust, SalesQuery $query, SalesPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:discount_fixed,discount_percent,surcharge_fixed'],
            // Basis points for a percentage, minor units otherwise.
            'value' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:190'],
        ]);

        $adjust->add(
            $query->find($uuid, $user),
            $user,
            AdjustmentType::from($validated['type']),
            (int) $validated['value'],
            $validated['reason'],
        );

        return ApiResponse::data(['sale' => $presenter->sale($query->find($uuid, $user), $user)], 201);
    }

    public function removeAdjustment(Request $request, string $uuid, string $adjustmentUuid, AdjustSale $adjust, SalesQuery $query, SalesPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $adjust->remove($query->find($uuid, $user), $adjustmentUuid, $user);

        return ApiResponse::data(['sale' => $presenter->sale($query->find($uuid, $user), $user)]);
    }

    public function finalize(Request $request, string $uuid, FinalizeSale $finalize, SalesQuery $query, SalesPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $issued = $finalize($query->find($uuid, $user), $user);

        [$fresh, $sale] = $query->invoice($issued->invoice->uuid, $user);

        // The customer link's URL is in THIS response only; a replay has none.
        return ApiResponse::data(['invoice' => $presenter->invoice($fresh, $sale, $user, $issued->shareToken)]);
    }

    public function void(Request $request, string $uuid, CloseSale $close, SalesQuery $query, SalesPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:190']]);

        $close->void($query->find($uuid, $user), $user, $validated['reason']);

        return ApiResponse::data(['sale' => $presenter->sale($query->find($uuid, $user), $user)]);
    }

    public function invoice(Request $request, string $invoiceUuid, SalesQuery $query, SalesPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        [$invoice, $sale] = $query->invoice($invoiceUuid, $user);

        return ApiResponse::data(['invoice' => $presenter->invoice($invoice, $sale, $user)]);
    }

    /**
     * The render payload for a PAPER layout. `printing` + `invoice.print`: a
     * center with POS but without Printing still has `invoice` above.
     */
    public function printable(Request $request, string $invoiceUuid, SalesQuery $query, SalesAccess $access, InvoiceRenderer $renderer): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate(['format' => ['nullable', 'string', 'in:80mm,a4']]);

        [$invoice, $sale] = $query->invoice($invoiceUuid, $user);

        $access->ensurePrinting($user, $invoice->branch_id);

        return ApiResponse::data([
            'format' => $validated['format'] ?? '80mm',
            'document' => $renderer->document($invoice, null, $sale->status, $sale->voided_at?->toImmutable()),
        ]);
    }

    public function rotateLink(Request $request, string $invoiceUuid, RotateInvoiceLink $rotate, SalesQuery $query, SalesPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        [$invoice] = $query->invoice($invoiceUuid, $user);

        $secret = $rotate($invoice, $user);

        [$fresh, $sale] = $query->invoice($invoiceUuid, $user);

        return ApiResponse::data(['invoice' => $presenter->invoice($fresh, $sale, $user, $secret)]);
    }

    public function invoicePrefix(Request $request, string $branchUuid, SetBranchInvoicePrefix $set): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate(['prefix' => ['nullable', 'string', 'max:8']]);

        $branch = $set($branchUuid, $user, $validated['prefix'] ?? null);

        return ApiResponse::data(['branch' => ['uuid' => $branch->uuid, 'invoice_prefix' => $branch->invoice_prefix]]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
