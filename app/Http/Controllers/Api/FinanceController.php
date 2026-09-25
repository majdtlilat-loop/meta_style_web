<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Modules\Finance\Application\Actions\CloseShiftWithCount;
use App\Modules\Finance\Application\Actions\ManageExpenseCategory;
use App\Modules\Finance\Application\Actions\RecordExpense;
use App\Modules\Finance\Application\ExpectedCash;
use App\Modules\Finance\Application\FinanceDashboard;
use App\Modules\Finance\Application\FinancePresenter;
use App\Modules\Finance\Application\FinanceQuery;
use App\Modules\Finance\Domain\Models\Expense;
use App\Modules\Finance\Domain\Models\ExpenseCategory;
use App\Modules\Finance\Domain\Models\FinanceEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Center finance: the dashboard, the ledger, expenses and their categories, and
 * the counted-cash shift close (docs/20-FINANCE.md §57).
 *
 * Validate → one Action or query → present. Amounts are integer minor units.
 */
final class FinanceController extends Controller
{
    public function dashboard(Request $request, FinanceDashboard $dashboard, FinancePresenter $presenter): JsonResponse
    {
        $validated = $request->validate([
            'branch' => ['nullable', 'uuid'],
            'from' => ['required', 'date_format:Y-m-d'],
            'until' => ['required', 'date_format:Y-m-d'],
        ]);

        return ApiResponse::data(['summary' => $dashboard->summary(
            $this->user($request),
            (string) $validated['from'],
            (string) $validated['until'],
            $validated['branch'] ?? null,
        )]);
    }

    public function ledger(Request $request, FinanceQuery $query, FinancePresenter $presenter): JsonResponse
    {
        $validated = $this->range($request);

        $entries = $query->ledger($this->user($request), $validated['branch'], $validated['from'], $validated['until']);

        return ApiResponse::data(['entries' => array_map(fn (FinanceEntry $entry): array => $presenter->entry($entry), $entries)]);
    }

    public function categories(Request $request, FinanceQuery $query, FinancePresenter $presenter): JsonResponse
    {
        $categories = $query->categories($this->user($request), true);

        return ApiResponse::data(['categories' => array_map(fn (ExpenseCategory $category): array => $presenter->category($category), $categories)]);
    }

    public function storeCategory(Request $request, ManageExpenseCategory $manage, FinancePresenter $presenter): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'array'],
            'name.*' => ['nullable', 'string', 'max:80'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        /** @var array<string, string|null> $name */
        $name = $validated['name'];

        $category = $manage->save($name, $this->user($request), null, (int) ($validated['sort_order'] ?? 0));

        return ApiResponse::data(['category' => $presenter->category($category)], 201);
    }

    public function updateCategory(Request $request, string $uuid, FinanceQuery $query, ManageExpenseCategory $manage, FinancePresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'name' => ['required', 'array'],
            'name.*' => ['nullable', 'string', 'max:80'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        /** @var array<string, string|null> $name */
        $name = $validated['name'];

        $category = $manage->save($name, $user, $query->category($uuid, $user), (int) ($validated['sort_order'] ?? 0));

        return ApiResponse::data(['category' => $presenter->category($category)]);
    }

    public function archiveCategory(Request $request, string $uuid, FinanceQuery $query, ManageExpenseCategory $manage, FinancePresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        return ApiResponse::data(['category' => $presenter->category($manage->archive($query->category($uuid, $user), $user))]);
    }

    public function expenses(Request $request, FinanceQuery $query, FinancePresenter $presenter): JsonResponse
    {
        $validated = $this->range($request);

        $expenses = $query->expenses($this->user($request), $validated['branch'], $validated['from'], $validated['until']);

        return ApiResponse::data(['expenses' => array_map(fn (Expense $expense): array => $presenter->expense($expense), $expenses)]);
    }

    public function storeExpense(Request $request, RecordExpense $record, FinanceQuery $query, FinancePresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'branch' => ['required', 'uuid'],
            'category' => ['required', 'uuid'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'string', 'in:cash,manual_electronic'],
            'description' => ['required', 'string', 'min:3', 'max:500'],
            'occurred_at' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'payee' => ['nullable', 'string', 'max:120'],
            'from_drawer' => ['nullable', 'boolean'],
            'idempotency_token' => ['nullable', 'string', 'max:64'],
        ]);

        $expense = $record->post([
            'branch' => (string) $validated['branch'],
            'category' => (string) $validated['category'],
            'amount_minor' => (int) $validated['amount_minor'],
            'method' => (string) $validated['method'],
            'description' => (string) $validated['description'],
            'occurred_at' => isset($validated['occurred_at']) ? CarbonImmutable::parse((string) $validated['occurred_at']) : null,
            'reference' => $validated['reference'] ?? null,
            'payee' => $validated['payee'] ?? null,
            'from_drawer' => (bool) ($validated['from_drawer'] ?? false),
            'idempotency_token' => $validated['idempotency_token'] ?? null,
        ], $user);

        return ApiResponse::data(['expense' => $presenter->expense($query->expense($expense->uuid, $user))], 201);
    }

    public function voidExpense(Request $request, string $uuid, FinanceQuery $query, RecordExpense $record, FinancePresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:190']]);

        $record->void($query->expense($uuid, $user), $user, (string) $validated['reason']);

        return ApiResponse::data(['expense' => $presenter->expense($query->expense($uuid, $user))]);
    }

    public function expectedCash(Request $request, string $uuid, FinanceQuery $query, ExpectedCash $expected, FinancePresenter $presenter): JsonResponse
    {
        $figures = $expected->forShift($query->shift($uuid, $this->user($request)));

        return ApiResponse::data(['expected' => array_map(fn (int $minor): array => $presenter->money($minor), $figures)]);
    }

    public function closeShift(Request $request, string $uuid, FinanceQuery $query, CloseShiftWithCount $close, FinancePresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'counted_cash_minor' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:190'],
        ]);

        $reconciliation = $close($query->shift($uuid, $user), $user, (int) $validated['counted_cash_minor'], $validated['note'] ?? null);

        return ApiResponse::data(['reconciliation' => $presenter->reconciliation($reconciliation)]);
    }

    /**
     * @return array{branch: string, from: string, until: string}
     */
    private function range(Request $request): array
    {
        $validated = $request->validate([
            'branch' => ['required', 'uuid'],
            'from' => ['required', 'date_format:Y-m-d'],
            'until' => ['required', 'date_format:Y-m-d'],
        ]);

        return ['branch' => (string) $validated['branch'], 'from' => (string) $validated['from'], 'until' => (string) $validated['until']];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
