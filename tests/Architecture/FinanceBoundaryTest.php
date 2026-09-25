<?php

declare(strict_types=1);

use App\Modules\Finance\Domain\Concerns\AppendOnlyRecord;
use App\Modules\Finance\Domain\Models\FinanceEntry;
use App\Modules\Finance\Domain\Models\ShiftReconciliation;

/*
|--------------------------------------------------------------------------
| The Finance boundary
|--------------------------------------------------------------------------
|
| docs/20-FINANCE.md §§34–37, 65 · docs/04-MODULE-BOUNDARIES.md §2.
|
| Finance reads Sales and Payments and hears their events. Nothing below it
| — Sales, Payments, the operational modules — may depend on it. The ledger has
| exactly one writer, is append-only, and is never "fixed" by editing a row.
|
*/

arch('sales, payments and the operational modules do not depend on finance')
    ->expect(['App\Modules\Sales', 'App\Modules\Payments', 'App\Modules\Booking', 'App\Modules\ServiceJourney', 'App\Modules\Queue', 'App\Modules\Resources'])
    ->not->toUse('App\Modules\Finance');

arch('customers, the catalog, branches and the kernel do not depend on finance')
    ->expect(['App\Modules\Customers', 'App\Modules\Catalog', 'App\Modules\Branches', 'App\Kernel'])
    ->not->toUse('App\Modules\Finance');

arch('the finance module does not depend on HTTP or Livewire')
    ->expect('App\Modules\Finance')
    ->not->toUse([
        'App\Http\Controllers',
        'App\Livewire',
        'Livewire\Component',
        'Illuminate\Support\Facades\Request',
        'Illuminate\Support\Facades\Auth',
        'Illuminate\Support\Facades\Session',
    ]);

arch('finance enums are string backed, so stored values survive a release')
    ->expect('App\Modules\Finance\Domain\Enums')
    ->toBeStringBackedEnum();

it('keeps the ledger and drawer counts append-only at the model', function (): void {
    expect(class_uses_recursive(FinanceEntry::class))->toContain(AppendOnlyRecord::class)
        ->and(class_uses_recursive(ShiftReconciliation::class))->toContain(AppendOnlyRecord::class);
});

it('gives the ledger exactly one writer', function (): void {
    /*
     * `Ledger::append()` refuses to run outside the money's transaction and is
     * idempotent per source. Any other writer — a model create, a builder insert,
     * an update "to correct" a row — skips both (§37).
     */
    $violations = [];

    foreach (appSourceWithoutComments() as $path => $contents) {
        foreach (explode(';', $contents) as $statement) {
            $modelWrite = preg_match('/FinanceEntry::(query\(\)\s*->\s*)?(create|forceCreate|insert|upsert|updateOrCreate|firstOrCreate)\(|new\s+FinanceEntry\b/', $statement) === 1;
            $builderWrite = preg_match('/table\(\s*[\'"](finance_entries|cashier_shift_reconciliations)[\'"]\s*\)/', $statement) === 1
                && preg_match('/->(insert|insertGetId|insertOrIgnore|upsert|update|updateOrInsert|delete|truncate|increment|decrement)\(/', $statement) === 1;

            if ($modelWrite && $path === 'app/Modules/Finance/Application/Ledger.php') {
                $sawLedger = true;
            } elseif ($modelWrite) {
                $violations[] = $path.'  model write to finance_entries';
            }

            if ($builderWrite) {
                $violations[] = $path.'  builder write to the ledger or a drawer count';
            }
        }
    }

    // The scan must find the one writer it allows, or it is not scanning anything.
    expect($sawLedger ?? false)->toBeTrue()
        ->and($violations)->toBe([]);
});

it('never calls a total revenue or profit', function (): void {
    /*
     * INVOICED is billed; COLLECTED, REFUNDED and EXPENSES moved. None of them is
     * revenue, and without cost of goods nothing here is profit (§§42–44).
     */
    $violations = [];

    foreach (translatableSourceFiles() as $path => $contents) {
        $scanned = str_starts_with($path, 'app/Modules/Finance/')
            || in_array($path, [
                'app/Http/Controllers/Api/FinanceController.php',
                'app/Livewire/Center/FinanceOverview.php',
                'resources/views/livewire/center/finance-overview.blade.php',
                'resources/views/livewire/center/expenses.blade.php',
            ], true);

        if (! $scanned) {
            continue;
        }

        foreach (explode("\n", $contents) as $index => $source) {
            $trimmed = ltrim($source);

            if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '{{--')) {
                continue;
            }

            if (preg_match('/revenue|profit|earnings|income_minor/i', $source) === 1) {
                $violations[] = $path.':'.($index + 1).'  '.trim($source);
            }
        }
    }

    expect($violations)->toBe([]);
});

it('reads the ledger for money, never the audit log', function (): void {
    /*
     * Audit answers who did what. A dashboard that sums audit rows counts retried
     * requests and reads JSON it does not own (§43).
     */
    $violations = [];

    foreach (appSourceFiles() as $path => $contents) {
        if (! str_starts_with($path, 'app/Modules/Finance/') && ! str_starts_with($path, 'app/Modules/Payments/')) {
            continue;
        }

        if (preg_match('/TenantAuditLog|PlatformAuditLog|table\(\s*[\'"](tenant_)?audit/', $contents) === 1) {
            $violations[] = $path;
        }
    }

    expect($violations)->toBe([]);
});
