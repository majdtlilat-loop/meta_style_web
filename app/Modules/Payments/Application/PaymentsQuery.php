<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Time\BranchClock;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\Enums\RefundStatus;
use App\Modules\Payments\Domain\Exceptions\PaymentFailed;
use App\Modules\Payments\Domain\Models\GatewayAccount;
use App\Modules\Payments\Domain\Models\Payment;
use App\Modules\Payments\Domain\Models\Refund;
use App\Modules\Sales\Domain\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reads payments for staff — bounded, eager-loaded, branch-scoped, and needing
 * no entitlement: payment history stays readable after a downgrade
 * (docs/19-PAYMENTS.md §3).
 */
final class PaymentsQuery
{
    public const MAX_LIST = 100;

    /** A receipts window is a working period, not a report: at most this many days. */
    public const MAX_DAYS = 92;

    public const PER_PAGE = 25;

    public function __construct(private readonly PaymentsAccess $access) {}

    /**
     * Whether this center ever took a payment — the history a downgrade keeps
     * readable. A center with none, and neither `pos` nor `payments`, has
     * nothing to read on the receipts screen.
     */
    public function hasHistory(): bool
    {
        return Payment::query()->exists();
    }

    /**
     * @throws AuthorizationException
     */
    public function invoice(string $invoiceUuid, User $user): Invoice
    {
        /** @var Invoice|null $invoice */
        $invoice = Invoice::query()->where('uuid', $invoiceUuid)->first();

        if (! $invoice instanceof Invoice) {
            throw new NotFoundHttpException;
        }

        $this->access->authorize($user, Permission::PaymentView, $invoice->branch_id, 'You may not view payments.');

        return $invoice;
    }

    /**
     * An invoice's payments, oldest first, with their refunds — two queries.
     *
     * @return list<Payment>
     */
    public function forInvoice(Invoice $invoice): array
    {
        /** @var list<Payment> $payments */
        $payments = Payment::query()
            ->where('invoice_id', $invoice->getKey())
            ->with('refunds')
            ->orderBy('initiated_at')
            ->orderBy('id')
            ->limit(self::MAX_LIST)
            ->get()
            ->all();

        return $payments;
    }

    /**
     * @throws AuthorizationException
     */
    public function payment(string $uuid, User $user): Payment
    {
        /** @var Payment|null $payment */
        $payment = Payment::query()->where('uuid', $uuid)->with(['refunds', 'invoice'])->first();

        if (! $payment instanceof Payment) {
            throw new NotFoundHttpException;
        }

        $this->access->authorize($user, Permission::PaymentView, $payment->branch_id, 'You may not view payments.');

        return $payment;
    }

    /**
     * @throws AuthorizationException
     */
    public function refund(string $uuid, User $user): Refund
    {
        /** @var Refund|null $refund */
        $refund = Refund::query()->where('uuid', $uuid)->first();

        if (! $refund instanceof Refund) {
            throw new NotFoundHttpException;
        }

        $this->access->authorize($user, Permission::PaymentView, $refund->branch_id, 'You may not view payments.');

        return $refund;
    }

    /**
     * A branch's gateway accounts — the allow-listed presenter decides what of
     * them is shown; credentials never are.
     *
     * @return list<GatewayAccount>
     *
     * @throws AuthorizationException
     */
    public function gatewayAccounts(string $branchUuid, User $user, Permission $permission = Permission::PaymentGatewayManage): array
    {
        /** @var Branch|null $branch */
        $branch = Branch::query()->where('uuid', $branchUuid)->first();

        if (! $branch instanceof Branch) {
            throw new NotFoundHttpException;
        }

        $this->access->authorize($user, $permission, $branch->id, 'You may not view payment gateways.');

        /** @var list<GatewayAccount> $accounts */
        $accounts = GatewayAccount::query()
            ->where('branch_id', $branch->id)
            ->orderBy('provider')
            ->get()
            ->all();

        return $accounts;
    }

    /**
     * @throws AuthorizationException
     */
    public function gatewayAccount(string $uuid, User $user, Permission $permission = Permission::PaymentCollect): GatewayAccount
    {
        /** @var GatewayAccount|null $account */
        $account = GatewayAccount::query()->where('uuid', $uuid)->first();

        if (! $account instanceof GatewayAccount) {
            throw new NotFoundHttpException;
        }

        $this->access->authorize($user, $permission, $account->branch_id, 'You may not use that payment gateway.');

        return $account;
    }

    /**
     * A branch's payment attempts started in a branch-local window, newest
     * first, each with its invoice number — the receipts list. Paginated, a
     * fixed number of queries a page whatever its size.
     *
     * @param  array{method?: string|null, status?: string|null, term?: string|null}  $filters
     * @return LengthAwarePaginator<int, Payment>
     *
     * @throws AuthorizationException
     * @throws PaymentFailed
     */
    public function receipts(User $user, string $branchUuid, string $fromDate, string $untilDate, array $filters = [], int $page = 1): LengthAwarePaginator
    {
        $branch = $this->readableBranch($branchUuid, $user);
        [$from, $until] = $this->window($branch, $fromDate, $untilDate);

        // payments(branch_id, initiated_at)
        $query = Payment::query()
            ->where('branch_id', $branch->id)
            ->where('initiated_at', '>=', $from)
            ->where('initiated_at', '<', $until)
            ->with(['invoice.sale', 'refunds'])
            ->orderByDesc('initiated_at')
            ->orderByDesc('id');

        $method = PaymentMethod::tryFrom((string) ($filters['method'] ?? ''));

        if ($method !== null) {
            $query->where('method', $method->value);
        }

        $status = PaymentStatus::tryFrom((string) ($filters['status'] ?? ''));

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        $this->matchInvoice($query, (string) ($filters['term'] ?? ''));

        return $query->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));
    }

    /**
     * A branch's refunds requested in a branch-local window, newest first,
     * each with its payment and that payment's invoice.
     *
     * @param  array{method?: string|null, status?: string|null, term?: string|null}  $filters
     * @return LengthAwarePaginator<int, Refund>
     *
     * @throws AuthorizationException
     * @throws PaymentFailed
     */
    public function refundsIn(User $user, string $branchUuid, string $fromDate, string $untilDate, array $filters = [], int $page = 1): LengthAwarePaginator
    {
        $branch = $this->readableBranch($branchUuid, $user);
        [$from, $until] = $this->window($branch, $fromDate, $untilDate);

        // refunds(branch_id, requested_at)
        $query = Refund::query()
            ->where('branch_id', $branch->id)
            ->where('requested_at', '>=', $from)
            ->where('requested_at', '<', $until)
            ->with('payment.invoice.sale')
            ->orderByDesc('requested_at')
            ->orderByDesc('id');

        $method = PaymentMethod::tryFrom((string) ($filters['method'] ?? ''));

        if ($method !== null) {
            $query->where('method', $method->value);
        }

        $status = RefundStatus::tryFrom((string) ($filters['status'] ?? ''));

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        $term = trim((string) ($filters['term'] ?? ''));

        if ($term !== '') {
            $query->whereHas('payment', function (Builder $payment) use ($term): void {
                /** @var Builder<Payment> $payment */
                $this->matchInvoice($payment, $term);
            });
        }

        return $query->paginate(self::PER_PAGE, ['*'], 'refundsPage', max(1, $page));
    }

    /**
     * Money that actually moved at a branch in a branch-local window, by method
     * and currency: payments that SUCCEEDED in it, refunds that SUCCEEDED in it,
     * and — apart — online payments started in it that still wait for their
     * provider, which are not money received. Three grouped queries on indexes.
     *
     * @return array{received: array{by_method: list<array{method: string, currency: string, total_minor: int, count: int}>, total: list<array{currency: string, total_minor: int, count: int}>}, refunded: array{by_method: list<array{method: string, currency: string, total_minor: int, count: int}>, total: list<array{currency: string, total_minor: int, count: int}>}, pending: array{by_method: list<array{method: string, currency: string, total_minor: int, count: int}>, total: list<array{currency: string, total_minor: int, count: int}>}}
     *
     * @throws AuthorizationException
     * @throws PaymentFailed
     */
    public function receiptTotals(User $user, string $branchUuid, string $fromDate, string $untilDate): array
    {
        $branch = $this->readableBranch($branchUuid, $user);
        [$from, $until] = $this->window($branch, $fromDate, $untilDate);

        // payments(branch_id, status, succeeded_at)
        $received = Payment::query()->toBase()
            ->where('branch_id', $branch->id)
            ->where('status', PaymentStatus::Succeeded->value)
            ->where('succeeded_at', '>=', $from)
            ->where('succeeded_at', '<', $until)
            ->groupBy('method', 'currency')
            ->selectRaw('method, currency, SUM(amount_minor) AS total, COUNT(*) AS attempts')
            ->get()
            ->all();

        // refunds(branch_id, status, succeeded_at)
        $refunded = Refund::query()->toBase()
            ->where('branch_id', $branch->id)
            ->where('status', RefundStatus::Succeeded->value)
            ->where('succeeded_at', '>=', $from)
            ->where('succeeded_at', '<', $until)
            ->groupBy('method', 'currency')
            ->selectRaw('method, currency, SUM(amount_minor) AS total, COUNT(*) AS attempts')
            ->get()
            ->all();

        // payments(branch_id, initiated_at)
        $pending = Payment::query()->toBase()
            ->where('branch_id', $branch->id)
            ->where('status', PaymentStatus::Pending->value)
            ->where('initiated_at', '>=', $from)
            ->where('initiated_at', '<', $until)
            ->groupBy('method', 'currency')
            ->selectRaw('method, currency, SUM(amount_minor) AS total, COUNT(*) AS attempts')
            ->get()
            ->all();

        return [
            'received' => $this->totalRows($received),
            'refunded' => $this->totalRows($refunded),
            'pending' => $this->totalRows($pending),
        ];
    }

    /**
     * Grouped rows by method, and their sum per currency — added up HERE, in
     * the module, so a screen only formats. Currencies are never mixed.
     *
     * @param  array<int, mixed>  $found
     * @return array{by_method: list<array{method: string, currency: string, total_minor: int, count: int}>, total: list<array{currency: string, total_minor: int, count: int}>}
     */
    private function totalRows(array $found): array
    {
        $rows = [];
        $totals = [];

        foreach ($found as $row) {
            /** @var object{method: string, currency: string, total: int|string|null, attempts: int|string} $row */
            $currency = (string) $row->currency;
            $rows[] = [
                'method' => (string) $row->method,
                'currency' => $currency,
                'total_minor' => (int) $row->total,
                'count' => (int) $row->attempts,
            ];

            $totals[$currency] ??= ['currency' => $currency, 'total_minor' => 0, 'count' => 0];
            $totals[$currency]['total_minor'] += (int) $row->total;
            $totals[$currency]['count'] += (int) $row->attempts;
        }

        return ['by_method' => $rows, 'total' => array_values($totals)];
    }

    /**
     * @throws AuthorizationException
     */
    private function readableBranch(string $branchUuid, User $user): Branch
    {
        /** @var Branch|null $branch */
        $branch = Branch::query()->where('uuid', $branchUuid)->first();

        if (! $branch instanceof Branch) {
            throw new NotFoundHttpException;
        }

        $this->access->authorize($user, Permission::PaymentView, $branch->id, 'You may not view payments.');

        return $branch;
    }

    /**
     * An invoice number, typed in full or in part ("0042", "INV-2026").
     *
     * @param  Builder<Payment>  $query
     */
    private function matchInvoice(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $query->whereHas('invoice', fn (Builder $invoice) => $invoice->where('number', 'like', '%'.addcslashes($term, '%_\\').'%'));
    }

    /**
     * A half-open window of whole branch-local days.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     *
     * @throws PaymentFailed
     */
    private function window(Branch $branch, string $fromDate, string $untilDate): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $untilDate) !== 1) {
            throw PaymentFailed::policy('Dates are YYYY-MM-DD.');
        }

        $first = CarbonImmutable::parse($fromDate, 'UTC');
        $last = CarbonImmutable::parse($untilDate, 'UTC');

        if ($last->lessThan($first)) {
            throw PaymentFailed::policy('The range ends before it starts.');
        }

        if ($first->diffInDays($last) + 1 > self::MAX_DAYS) {
            throw PaymentFailed::policy('Choose a range of at most '.self::MAX_DAYS.' days.');
        }

        $timezone = $branch->timezone !== '' ? $branch->timezone : 'UTC';

        return [
            BranchClock::toUtcOrShift($fromDate, 0, $timezone),
            BranchClock::toUtcOrShift($last->addDay()->format('Y-m-d'), 0, $timezone),
        ];
    }
}
