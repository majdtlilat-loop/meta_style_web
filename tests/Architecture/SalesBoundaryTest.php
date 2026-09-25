<?php

declare(strict_types=1);
use App\Modules\Sales\Domain\Concerns\ImmutableDocument;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\Sales\Domain\Models\InvoiceItem;

/*
|--------------------------------------------------------------------------
| The Sales boundary
|--------------------------------------------------------------------------
|
| docs/18-SALES.md §55 · docs/04-MODULE-BOUNDARIES.md §2.
|
|   Appointment     what was reserved
|   ServiceJourney  the operational visit
|   JourneyStage    what was actually performed
|   QueueTicket     the waiting and calling around a stage
|   Sale            what was charged
|   Invoice         what was published — immutable
|   Payment         money collected against an invoice — Payments (Phase 10)
|
| The dependency points ONE WAY. Sales may read the visit, the booking, the
| catalog and the customer. None of them may learn Sales exists: a center with
| no POS completes visits exactly as before.
|
*/

arch('booking does not depend on sales')
    ->expect('App\Modules\Booking')
    ->not->toUse('App\Modules\Sales');

arch('the journey does not depend on sales')
    ->expect('App\Modules\ServiceJourney')
    // `CompleteJourney` never creates a sale. Checkout is a Sales Action that
    // READS the visit; the visit board only links to it (§§32–33).
    ->not->toUse('App\Modules\Sales');

arch('the queue does not depend on sales')
    ->expect('App\Modules\Queue')
    ->not->toUse('App\Modules\Sales');

arch('resources, customers and the catalog do not depend on sales')
    ->expect(['App\Modules\Resources', 'App\Modules\Customers', 'App\Modules\Catalog', 'App\Modules\Branches'])
    ->not->toUse('App\Modules\Sales');

arch('the sales module does not depend on HTTP or Livewire')
    ->expect('App\Modules\Sales')
    ->not->toUse([
        'App\Http\Controllers',
        'App\Livewire',
        'Livewire\Component',
        'Illuminate\Support\Facades\Request',
        'Illuminate\Support\Facades\Auth',
        'Illuminate\Support\Facades\Session',
    ]);

arch('sales enums are string backed, so stored values survive a release')
    ->expect('App\Modules\Sales\Domain\Enums')
    ->toBeStringBackedEnum();

arch('the pricing service is framework-free')
    ->expect('App\Modules\Sales\Domain\Pricing')
    ->not->toUse(['Illuminate', 'App\Kernel\Tenancy', 'App\Modules\Catalog']);

/**
 * Non-comment source lines of the files under `$prefixes`.
 *
 * @param  list<string>  $prefixes
 * @return list<array{path: string, line: int, source: string}>
 */
function salesSourceLines(array $prefixes, bool $includeViews = false): array
{
    $files = $includeViews ? translatableSourceFiles() : appSourceFiles();
    $lines = [];

    foreach ($files as $path => $contents) {
        $matches = false;

        foreach ($prefixes as $prefix) {
            $matches = $matches || str_starts_with($path, $prefix);
        }

        if (! $matches) {
            continue;
        }

        foreach (explode("\n", $contents) as $index => $source) {
            $trimmed = ltrim($source);

            if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '{{--')) {
                continue;
            }

            $lines[] = ['path' => $path, 'line' => $index + 1, 'source' => $source];
        }
    }

    return $lines;
}

it('keeps pricing and finalization rules out of controllers and Livewire', function (): void {
    /*
     * A controller or component that adds, multiplies or assigns a money total
     * is a second pricing engine, and the first one to disagree with
     * `SalePricing`. They may PARSE what a person typed (`fromMajorString`,
     * `basisPoints`); they may not compute a total or write one (§§9, 55).
     */
    $violations = [];

    foreach (salesSourceLines(['app/Http/Controllers/', 'app/Livewire/']) as $line) {
        $source = $line['source'];

        $arithmetic = preg_match('/_minor\b\s*[\+\-\*\/%]|[\+\-\*\/%]\s*\$[\w\->\[\]\'"]*_minor\b/', $source) === 1;
        $assignsTotal = preg_match('/[\'"](subtotal|discount_total|surcharge_total|tax_total|grand_total|line_subtotal|line_total|discount_allocated)_minor[\'"]\s*=>/', $source) === 1
            || preg_match('/->(subtotal|discount_total|surcharge_total|tax_total|grand_total|line_subtotal|line_total)_minor\s*=(?!=)/', $source) === 1;
        $writesStatus = preg_match('/[\'"]status[\'"]\s*=>\s*SaleStatus::|->status\s*=\s*SaleStatus::/', $source) === 1;
        $reachesSequence = str_contains($source, 'invoice_sequences') || str_contains($source, 'InvoiceNumbers');

        if ($arithmetic || $assignsTotal || $writesStatus || $reachesSequence) {
            $violations[] = $line['path'].':'.$line['line'].'  '.trim($source);
        }
    }

    expect($violations)->toBe([], implode("\n", array_merge(['Pricing or lifecycle logic outside the Sales Actions:'], $violations)));
});

it('never writes the invoice tables through the query builder', function (): void {
    /*
     * The model refuses updates and deletes (`ImmutableDocument`), but model
     * events cannot see `DB::table('invoices')->update()`. So that path is
     * closed here instead — anywhere in the application (ADR-054).
     */
    $violations = [];

    foreach (salesSourceLines(['app/']) as $line) {
        if (preg_match('/table\(\s*[\'"](invoices|invoice_items)[\'"]\s*\)/', $line['source']) === 1) {
            if (! in_array($line['path'], [
                'app/Modules/Sales/Application/SqlSalesReportReader.php',
                'app/Modules/Payments/Application/SqlPaymentReportReader.php',
            ], true)) {
                $violations[] = $line['path'].':'.$line['line'].'  '.trim($line['source']);
            }
        }

        if (preg_match('/Invoice(Item)?::query\(\)[^;]*->(update|delete|forceDelete|increment|decrement)\(/', $line['source']) === 1) {
            $violations[] = $line['path'].':'.$line['line'].'  '.trim($line['source']);
        }
    }

    $sources = appSourceFiles();
    $readers = ($sources['app/Modules/Sales/Application/SqlSalesReportReader.php'] ?? '')
        .($sources['app/Modules/Payments/Application/SqlPaymentReportReader.php'] ?? '');

    foreach (['->insert(', '->update(', '->delete(', '->truncate(', '->increment(', '->decrement('] as $write) {
        if (str_contains($readers, $write)) {
            $violations[] = 'A report reader contains '.$write;
        }
    }

    expect($violations)->toBe([]);
});

it('keeps invoices immutable at the model', function (): void {
    $uses = class_uses_recursive(Invoice::class);
    $itemUses = class_uses_recursive(InvoiceItem::class);

    expect($uses)->toContain(ImmutableDocument::class)
        ->and($itemUses)->toContain(ImmutableDocument::class);
});

arch('sales does not depend on payments or finance')
    // Phase 10 made money real, and it points ONE way: Payments reads the
    // invoice, Finance reads both. A sale voids through the neutral
    // `SaleVoidGuard` contract, never by asking Payments (docs/19-PAYMENTS.md §4).
    ->expect('App\Modules\Sales')
    ->not->toUse(['App\Modules\Payments', 'App\Modules\Finance']);

arch('the till and the sales list collect no money themselves')
    // They EMBED the invoice's money panel (`InvoicePayments`). Taking cash,
    // starting a gateway payment or refunding is that component's, so a second
    // collection path cannot grow inside the till.
    ->expect(['App\Livewire\Center\PointOfSale', 'App\Livewire\Center\Sales', 'App\Livewire\Center\Till', 'App\Livewire\Center\PosSettings'])
    ->not->toUse('App\Modules\Payments');

it('keeps payment, gateway and settlement concepts out of Sales itself', function (): void {
    /*
     * A `payment_status` column on a sale, a gateway client or a refund method
     * added to Sales "while we are here" would be a second, disagreeing record
     * of money beside the one Payments owns (§§21, 58; docs/19-PAYMENTS.md §4).
     */
    $pattern = '/zaincash|fastpay|\bfib\b|\bqi\b|payment|refund|gateway|webhook|settle|payout|tip_minor|deposit/i';

    $violations = [];

    foreach (salesSourceLines(['app/Modules/Sales/', 'app/Http/Controllers/Api/SalesController.php']) as $line) {
        if (preg_match($pattern, $line['source']) === 1) {
            $violations[] = $line['path'].':'.$line['line'].'  '.trim($line['source']);
        }
    }

    $salesMigrations = array_merge(
        glob(dirname(__DIR__, 2).'/database/migrations/tenant/2026_09_10_*.php') ?: [],
        glob(dirname(__DIR__, 2).'/database/migrations/tenant/2026_09_14_100001_*.php') ?: [],
    );

    expect($salesMigrations)->not->toBeEmpty();

    foreach ($salesMigrations as $file) {
        foreach (explode("\n", (string) file_get_contents($file)) as $index => $source) {
            $trimmed = ltrim($source);

            if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            if (preg_match($pattern, $source) === 1) {
                $violations[] = basename($file).':'.($index + 1).'  '.trim($source);
            }
        }
    }

    expect($violations)->toBe([]);
});

it('stamps every Phase 9 instant as DATETIME, never TIMESTAMP', function (): void {
    $violations = [];

    foreach (glob(dirname(__DIR__, 2).'/database/migrations/tenant/2026_09_10_*.php') ?: [] as $file) {
        foreach (explode("\n", (string) file_get_contents($file)) as $index => $source) {
            if (preg_match('/->timestamp\(|->timestampTz\(/', $source) === 1) {
                $violations[] = basename($file).':'.($index + 1).'  '.trim($source);
            }
        }
    }

    expect($violations)->toBe([]);
});

it('renders invoices without unescaped output or center-authored markup', function (): void {
    /*
     * An invoice is shown to customers on a public URL. `{!! !!}` in any of its
     * templates would be the first step to a center-authored script running on
     * its own customers' phones (ADR-038, §26).
     */
    $violations = [];

    foreach (salesSourceLines(['resources/views/sales/', 'resources/views/livewire/center/pointOfSale', 'resources/views/livewire/center/sales', 'resources/views/livewire/center/pos-settings'], includeViews: true) as $line) {
        if (str_contains($line['source'], '{!!') || str_contains($line['source'], '@php')) {
            $violations[] = $line['path'].':'.$line['line'].'  '.trim($line['source']);
        }
    }

    expect($violations)->toBe([]);
});
