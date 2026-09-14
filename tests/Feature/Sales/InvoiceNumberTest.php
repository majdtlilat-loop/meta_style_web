<?php

declare(strict_types=1);

use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Application\Actions\SetBranchInvoicePrefix;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\InvoiceNumbers;
use App\Modules\Sales\Domain\InvoicePrefix;
use App\Modules\Sales\Domain\Models\Invoice;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Invoice numbers
|--------------------------------------------------------------------------
|
| docs/18-SALES.md §17, ADR-055.
|
| Per branch, per BRANCH-LOCAL calendar year, from a locked sequence row — the
| pattern Queue proved. Not daily: an invoice number is a document identity, not
| a place in a line. Unique and gapless per branch-year for as long as invoices
| are never deleted and nobody edits the sequence by hand, and no stronger claim.
|
*/

function inSeed(): array
{
    return test()->seedBookableCenter();
}

function inFinalize(array $seed, $user, $branch = null): Invoice
{
    $branch ??= $seed['branch'];

    test()->openShift($branch, $user);

    $sale = test()->draftSale($branch, $user);
    app(AddSaleLine::class)($sale, $user, ['kind' => 'product', 'product' => test()->seedProduct('Item '.uniqid(), 1000)->uuid]);

    return app(FinalizeSale::class)($sale, $user)->invoice;
}

it('numbers a branch consecutively within its year', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = inSeed();
        $owner = $this->ownerWithCatalogAccess();
        $year = now()->year;

        $numbers = [];

        for ($i = 0; $i < 3; $i++) {
            $numbers[] = inFinalize($seed, $owner)->number;
        }

        expect($numbers)->toBe([
            "INV-{$year}-000001",
            "INV-{$year}-000002",
            "INV-{$year}-000003",
        ]);
    });
});

it('gives each branch its own sequence and requires a second branch to have a prefix', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = inSeed();
        $owner = $this->ownerWithCatalogAccess();
        $year = now()->year;

        $seed['branch']->forceFill(['is_main' => true])->save();
        $second = $this->seedBranch('Mansour');

        inFinalize($seed, $owner);

        // No prefix: refused plainly, and nothing consumed.
        expect(fn () => inFinalize($seed, $owner, $second))
            ->toThrow(SaleFailed::class, 'needs an invoice prefix');

        expect(DB::connection('tenant')->table('invoice_sequences')->where('branch_id', $second->id)->value('last_number'))->toBeNull();

        app(SetBranchInvoicePrefix::class)($second->uuid, $owner, ' mn ');

        expect(inFinalize($seed, $owner, $second)->number)->toBe("MN-{$year}-000001")
            ->and(inFinalize($seed, $owner)->number)->toBe("INV-{$year}-000002");
    });
});

it('keeps prefixes unique, uppercase, short, and INV for the main branch only', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = inSeed();
        $owner = $this->ownerWithCatalogAccess();

        $seed['branch']->forceFill(['is_main' => true])->save();
        $second = $this->seedBranch('Mansour');
        $third = $this->seedBranch('Karrada');

        expect(fn () => app(SetBranchInvoicePrefix::class)($second->uuid, $owner, 'INV'))
            ->toThrow(SaleFailed::class, 'reserved for the main branch');

        foreach (['TOOLONG', 'A B', 'Ä1', '-'] as $bad) {
            expect(fn () => app(SetBranchInvoicePrefix::class)($second->uuid, $owner, $bad))->toThrow(SaleFailed::class);
        }

        app(SetBranchInvoicePrefix::class)($second->uuid, $owner, 'MN');

        expect(fn () => app(SetBranchInvoicePrefix::class)($third->uuid, $owner, 'mn'))
            ->toThrow(SaleFailed::class, 'already uses');

        expect(InvoicePrefix::effective(null, true))->toBe('INV')
            ->and(InvoicePrefix::format('MN', 2026, 17))->toBe('MN-2026-000017')
            ->and(InvoicePrefix::format('MN', 2026, 1234567))->toBe('MN-2026-1234567');
    });
});

it('refuses a prefix already printed on another branch\'s invoices', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = inSeed();
        $owner = $this->ownerWithCatalogAccess();

        $seed['branch']->forceFill(['is_main' => false])->save();
        $other = $this->seedBranch('Mansour');

        app(SetBranchInvoicePrefix::class)($seed['branch']->uuid, $owner, 'BG');
        inFinalize($seed, $owner);

        // The branch moves to a new prefix; "BG" is still on paper somewhere.
        app(SetBranchInvoicePrefix::class)($seed['branch']->uuid, $owner, 'BA');

        expect(fn () => app(SetBranchInvoicePrefix::class)($other->uuid, $owner, 'BG'))
            ->toThrow(SaleFailed::class, 'already uses');
    });
});

it('releases a number when the transaction that allocated it rolls back', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = inSeed();
        $numbers = app(InvoiceNumbers::class);

        DB::connection('tenant')->beginTransaction();
        $first = $numbers->next($seed['branch']->id, 2026);
        DB::connection('tenant')->rollBack();

        $again = DB::connection('tenant')->transaction(fn (): int => $numbers->next($seed['branch']->id, 2026));
        $next = DB::connection('tenant')->transaction(fn (): int => $numbers->next($seed['branch']->id, 2026));

        // The rolled-back 1 was never consumed: no gap from a failure.
        expect($first)->toBe(1)
            ->and($again)->toBe(1)
            ->and($next)->toBe(2);
    });
});

it('refuses to allocate a number outside a transaction', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = inSeed();

        expect(fn () => app(InvoiceNumbers::class)->next($seed['branch']->id, 2026))
            ->toThrow(RuntimeException::class, 'inside a transaction');
    });
});

it('makes a duplicate number impossible at the database, whatever the code does', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = inSeed();
        $owner = $this->ownerWithCatalogAccess();

        $invoice = inFinalize($seed, $owner);
        $otherSale = $this->draftSale($seed['branch'], $owner);

        $row = Invoice::query()->whereKey($invoice->id)->firstOrFail()->getAttributes();
        unset($row['id']);

        // Same branch-year-sequence under a different printed number.
        expect(fn () => DB::connection('tenant')->table('invoices')->insert(array_merge($row, [
            'uuid' => (string) Str::uuid(),
            'sale_id' => $otherSale->id,
            'number' => 'XX-0000-000001',
        ])))->toThrow(UniqueConstraintViolationException::class);

        // Same printed number under a different sequence.
        expect(fn () => DB::connection('tenant')->table('invoices')->insert(array_merge($row, [
            'uuid' => (string) Str::uuid(),
            'sale_id' => $otherSale->id,
            'sequence_number' => 999,
        ])))->toThrow(UniqueConstraintViolationException::class);

        // A second invoice for the same sale.
        expect(fn () => DB::connection('tenant')->table('invoices')->insert(array_merge($row, [
            'uuid' => (string) Str::uuid(),
            'number' => 'XX-0000-000002',
            'sequence_number' => 998,
        ])))->toThrow(UniqueConstraintViolationException::class);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
