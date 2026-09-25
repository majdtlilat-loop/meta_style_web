<?php

declare(strict_types=1);

use App\Kernel\Audit\Actor;
use App\Modules\Finance\Application\Actions\ManageExpenseCategory;
use App\Modules\Finance\Application\Actions\RecordExpense;
use App\Modules\Finance\Domain\Enums\EntryDirection;
use App\Modules\Finance\Domain\Enums\EntryKind;
use App\Modules\Finance\Domain\Models\FinanceEntry;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Application\Actions\InitiateGatewayPayment;
use App\Modules\Payments\Application\Actions\RequestRefund;
use App\Modules\Payments\Application\GatewaySettlement;
use App\Modules\Payments\Domain\Data\ProviderPaymentStatus;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Enums\ProviderState;
use App\Modules\Payments\Domain\Models\Payment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The center ledger: real money, once
|--------------------------------------------------------------------------
|
| docs/20-FINANCE.md §§35–37, 71.
|
| A collection when a payment succeeds, a debit when a refund does, an expense
| and its reversal — and nothing for an invoice being issued, a payment pending
| or a payment failing. Append-only. Committed with the money, or not at all.
|
*/

it('records nothing when an invoice is merely issued, or a payment is pending or fails', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPayments();
        $this->fakeGateway();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $invoice = $this->issuedInvoice($seed, $owner);

        // Billed, not received.
        expect(FinanceEntry::query()->count())->toBe(0);

        $pending = app(InitiateGatewayPayment::class)->fromDesk($invoice, $this->gatewayAccount($seed['branch']), $owner, 20000);

        expect(FinanceEntry::query()->count())->toBe(0);

        app(GatewaySettlement::class)->apply($pending, new ProviderPaymentStatus((string) $pending->provider_payment_reference, ProviderState::Declined, null, null), Actor::system('test'));

        expect(FinanceEntry::query()->count())->toBe(0);
    });
});

it('writes one entry per movement, in the right direction, and one only per source', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantFinance();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $invoice = $this->issuedInvoice($seed, $owner);

        $payment = app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 20000);
        $refund = app(RequestRefund::class)($payment, $owner, PaymentMethod::Cash, 4000, 'Partly redone');

        $category = app(ManageExpenseCategory::class)->save(['en' => 'Supplies'], $owner);
        $expense = app(RecordExpense::class)->post([
            'branch' => $seed['branch']->uuid,
            'category' => $category->uuid,
            'amount_minor' => 7000,
            'method' => 'manual_electronic',
            'description' => 'Towels',
        ], $owner);
        app(RecordExpense::class)->void($expense, $owner, 'Entered twice');

        $kinds = FinanceEntry::query()->orderBy('id')->get()->map(fn (FinanceEntry $entry): array => [$entry->kind, $entry->direction, $entry->amount_minor]);

        expect($kinds->all())->toBe([
            [EntryKind::Collection, EntryDirection::In, 20000],
            [EntryKind::Refund, EntryDirection::Out, 4000],
            [EntryKind::Expense, EntryDirection::Out, 7000],
            [EntryKind::ExpenseReversal, EntryDirection::In, 7000],
        ]);

        expect(FinanceEntry::query()->where('source_uuid', $payment->uuid)->count())->toBe(1)
            ->and(FinanceEntry::query()->where('source_uuid', $refund->uuid)->count())->toBe(1)
            ->and(FinanceEntry::query()->where('source_uuid', $expense->uuid)->count())->toBe(2);

        // The database itself refuses a second collection for the same payment.
        expect(fn () => DB::connection('tenant')->table('finance_entries')->insert([
            'uuid' => (string) Str::uuid(),
            'branch_id' => $payment->branch_id,
            'direction' => 'in',
            'kind' => 'collection',
            'amount_minor' => 20000,
            'currency' => 'IQD',
            'method' => 'cash',
            'source_type' => 'payment',
            'source_uuid' => $payment->uuid,
            'label' => 'duplicate',
            'occurred_at' => now(),
            'created_at' => now(),
        ]))->toThrow(UniqueConstraintViolationException::class);
    });
});

it('refuses to edit or delete a ledger entry', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        app(CollectDeskPayment::class)($this->issuedInvoice($seed, $owner), $owner, PaymentMethod::Cash, 20000);

        $entry = FinanceEntry::query()->firstOrFail();

        expect(fn () => $entry->forceFill(['amount_minor' => 1])->save())->toThrow(LogicException::class, 'append-only')
            ->and(fn () => $entry->delete())->toThrow(LogicException::class, 'never deleted');

        expect(FinanceEntry::query()->firstOrFail()->amount_minor)->toBe(20000);
    });
});

it('never reports a collection whose ledger entry could not be written', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $invoice = $this->issuedInvoice($seed, $owner);

        // The ledger store fails, where a real insert failure would surface.
        Event::listen('eloquent.creating: '.FinanceEntry::class, function (): never {
            throw new RuntimeException('The ledger is unavailable.');
        });

        expect(fn () => app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 20000))
            ->toThrow(RuntimeException::class, 'ledger is unavailable');

        // The payment rolled back with it: no money reported, nothing to collect twice.
        expect(Payment::query()->count())->toBe(0)
            ->and(FinanceEntry::query()->count())->toBe(0);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
