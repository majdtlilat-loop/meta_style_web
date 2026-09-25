<?php

declare(strict_types=1);

use App\Modules\Payments\Domain\Models\GatewayAccount;

/*
|--------------------------------------------------------------------------
| The Payments boundary
|--------------------------------------------------------------------------
|
| docs/19-PAYMENTS.md §§4, 19, 24, 48, 65 · docs/04-MODULE-BOUNDARIES.md §2.
|
|   Sale / Invoice   what was charged / published       — Sales
|   Payment          one attempt to settle one invoice  — Payments
|   Refund           money returned against a payment   — Payments
|   Ledger entry     money that actually moved          — Finance
|
| Payments reads Sales. Sales, and everything operational below it, never
| learns Payments exists; Payments never learns Finance exists — the ledger
| hears about money through `PaymentSucceeded` and `RefundSucceeded`.
|
*/

arch('booking, the journey, the queue and resources do not depend on payments')
    ->expect(['App\Modules\Booking', 'App\Modules\ServiceJourney', 'App\Modules\Queue', 'App\Modules\Resources'])
    ->not->toUse('App\Modules\Payments');

arch('customers, the catalog and branches do not depend on payments')
    ->expect(['App\Modules\Customers', 'App\Modules\Catalog', 'App\Modules\Branches'])
    ->not->toUse('App\Modules\Payments');

arch('payments does not depend on finance')
    // The ledger is Finance's reaction to a domain event, not a call Payments
    // makes. A center without Finance collects money exactly the same way.
    ->expect('App\Modules\Payments')
    ->not->toUse('App\Modules\Finance');

arch('the kernel does not depend on payments')
    ->expect('App\Kernel')
    ->not->toUse('App\Modules\Payments');

arch('the payments module does not depend on HTTP or Livewire')
    ->expect('App\Modules\Payments')
    ->not->toUse([
        'App\Http\Controllers',
        'App\Livewire',
        'Livewire\Component',
        'Illuminate\Support\Facades\Request',
        'Illuminate\Support\Facades\Auth',
        'Illuminate\Support\Facades\Session',
    ]);

arch('payments enums are string backed, so stored values survive a release')
    ->expect('App\Modules\Payments\Domain\Enums')
    ->toBeStringBackedEnum();

arch('payment events carry identifiers, not models')
    ->expect('App\Modules\Payments\Domain\Events')
    ->not->toUse('Illuminate\Database\Eloquent\Model');

/**
 * Non-comment source lines of app files under `$prefixes`.
 *
 * @param  list<string>  $prefixes
 * @return list<array{path: string, line: int, source: string}>
 */
function paymentsSourceLines(array $prefixes): array
{
    $lines = [];

    foreach (appSourceFiles() as $path => $contents) {
        $matches = false;

        foreach ($prefixes as $prefix) {
            $matches = $matches || str_starts_with($path, $prefix);
        }

        if (! $matches) {
            continue;
        }

        foreach (explode("\n", $contents) as $index => $source) {
            $trimmed = ltrim($source);

            if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            $lines[] = ['path' => $path, 'line' => $index + 1, 'source' => $source];
        }
    }

    return $lines;
}

it('stores no card number, CVV, PIN, OTP or track data in any table', function (): void {
    /*
     * Meta Style never touches a card or a wallet PIN: the provider's page does.
     * Every migration, control and tenant, every column name (§§19, 83;
     * docs/08-AUDIT-SECURITY.md).
     */
    $sensitive = '/^([a-z0-9]+_)*(pan|cvv|cvv2|cvc|pin|pin_code|otp|card_number|card_no|track_data|track1|track2|card_expiry|exp_month|exp_year)(_[a-z0-9]+)*$/';

    $violations = [];

    foreach (glob(dirname(__DIR__, 2).'/database/migrations/*/*.php') ?: [] as $file) {
        foreach (explode("\n", (string) file_get_contents($file)) as $index => $source) {
            if (preg_match_all('/->\w+\(\s*[\'"]([a-z0-9_]+)[\'"]/', $source, $found) < 1) {
                continue;
            }

            foreach ($found[1] as $column) {
                if (preg_match($sensitive, $column) === 1) {
                    $violations[] = basename($file).':'.($index + 1).'  '.$column;
                }
            }
        }
    }

    expect($violations)->toBe([]);
});

it('keeps no raw callback body, headers or signature on a webhook event', function (): void {
    /*
     * A webhook event is facts and codes: which account, which fingerprint, what
     * it did. Keeping the body "for debugging" keeps payer data and a replayable
     * signed message nobody needs (§24).
     */
    $file = glob(dirname(__DIR__, 2).'/database/migrations/tenant/2026_09_14_*_create_payment_webhook_events_table.php') ?: [];

    expect($file)->toHaveCount(1);

    preg_match_all('/->\w+\(\s*[\'"]([a-z0-9_]+)[\'"]/', (string) file_get_contents($file[0]), $found);

    foreach (['body', 'raw_body', 'payload', 'raw_payload', 'headers', 'signature', 'authorization', 'request'] as $column) {
        expect(in_array($column, $found[1], true))->toBeFalse("payment_webhook_events.{$column}");
    }
});

it('stamps every Phase 10 instant as DATETIME, never TIMESTAMP, and money as integers', function (): void {
    $violations = [];

    foreach (glob(dirname(__DIR__, 2).'/database/migrations/tenant/2026_09_14_*.php') ?: [] as $file) {
        foreach (explode("\n", (string) file_get_contents($file)) as $index => $source) {
            if (preg_match('/->timestamp\(|->timestampTz\(|->float\(|->double\(|->decimal\(|->unsignedDecimal\(/', $source) === 1) {
                $violations[] = basename($file).':'.($index + 1).'  '.trim($source);
            }
        }
    }

    expect(glob(dirname(__DIR__, 2).'/database/migrations/tenant/2026_09_14_*.php') ?: [])->toHaveCount(9)
        ->and($violations)->toBe([]);
});

it('writes payments, refunds and gateway accounts only through their models', function (): void {
    /*
     * Settlement, reservation and the ledger event all hang off the Actions that
     * write these rows under locks. `DB::table('payments')->update()` would move
     * money past all of it. Aggregate READS through the builder are fine; the one
     * builder write allowed is the webhook claim's `insertOrIgnore`, which is how
     * an idempotent claim is made atomic (§22).
     */
    $violations = [];

    foreach (appSourceWithoutComments() as $path => $contents) {
        foreach (explode(';', $contents) as $statement) {
            if (preg_match('/table\(\s*[\'"](payments|refunds|payment_gateway_accounts|payment_webhook_events)[\'"]\s*\)/', $statement, $table) !== 1) {
                continue;
            }

            if (preg_match('/->(insert|insertGetId|insertOrIgnore|upsert|update|updateOrInsert|delete|truncate|increment|decrement)\(/', $statement, $verb) !== 1) {
                continue;
            }

            $allowed = $path === 'app/Modules/Payments/Application/GatewaySettlement.php'
                && $table[1] === 'payment_webhook_events'
                && $verb[1] === 'insertOrIgnore';

            if ($allowed) {
                $sawClaim = true;
            } else {
                $violations[] = $path.'  '.$table[1].'->'.$verb[1];
            }
        }
    }

    // The scan must see the one write it allows, or it is not scanning anything.
    expect($sawClaim ?? false)->toBeTrue()
        ->and($violations)->toBe([]);
});

it('never presents, logs or audits gateway credentials', function (): void {
    /*
     * Credentials go in through `ManageGatewayAccount` and out only to the
     * provider adapter. No allow-list may name them, no audit call may carry
     * them, no log line may mention them (§§19, 48, 53).
     */
    $violations = [];

    $presenters = [
        'app/Modules/Payments/Application/PaymentsPresenter.php',
        'app/Modules/Payments/Application/PublicInvoicePayments.php',
        'app/Modules/Payments/Application/PaymentsAudit.php',
        'app/Modules/Finance/Application/FinancePresenter.php',
    ];

    foreach (paymentsSourceLines($presenters) as $line) {
        if (preg_match('/[\'"](credentials|client_secret|webhook_secret|secret|api_key|password)[\'"]/', $line['source']) === 1) {
            $violations[] = $line['path'].':'.$line['line'].'  '.trim($line['source']);
        }
    }

    foreach (paymentsSourceLines(['app/Modules/Payments/', 'app/Http/Controllers/Api/PaymentWebhookController.php', 'app/Http/Controllers/Api/GatewayAccountController.php', 'app/Livewire/Center/PaymentGateways.php']) as $line) {
        if (preg_match('/Log::|logger\(|->log\(|error_log\(|dump\(|var_export\(/', $line['source']) === 1) {
            $violations[] = $line['path'].':'.$line['line'].'  '.trim($line['source']);
        }

        if (preg_match('/->record\(|audit->/', $line['source']) === 1 && preg_match('/credentials|secret/i', $line['source']) === 1) {
            $violations[] = $line['path'].':'.$line['line'].'  '.trim($line['source']);
        }
    }

    expect($violations)->toBe([])
        ->and((new GatewayAccount)->getHidden())->toContain('credentials')
        ->and((new GatewayAccount)->getCasts()['credentials'] ?? null)->toBe('encrypted:array');
});

it('never reads a destination from a center\'s credentials', function (): void {
    /*
     * A provider's host is platform configuration. An adapter that took a URL or
     * host from the stored credentials would let a center point outbound calls —
     * carrying its secrets — at any address it likes (§20). The registered
     * providers' field lists are checked at runtime in ProviderRegistryTest.
     */
    $violations = [];

    foreach (paymentsSourceLines(['app/Modules/Payments/']) as $line) {
        if (preg_match('/credentials->get\(\s*[\'"][a-z_]*(url|uri|host|endpoint|base|domain)[a-z_]*[\'"]/i', $line['source']) === 1) {
            $violations[] = $line['path'].':'.$line['line'].'  '.trim($line['source']);
        }
    }

    expect($violations)->toBe([]);
});

it('hides no financial side effect in a model observer', function (): void {
    /*
     * A ledger entry, a settlement or a void refusal that happens because a model
     * was saved is invisible at the call site and silently skipped by a builder
     * write. They are explicit: a guard contract, a domain event (§§25, 36).
     */
    $violations = scanAppSource('/::observe\(|#\[ObservedBy|static::(created|saved|updated|deleted)\(/', []);

    expect($violations)->toBe([]);
});

it('introduces no platform billing, loyalty, promotion, commission or payout concept', function (): void {
    /*
     * The center is the merchant; Meta Style holds no money, pays nobody out and
     * takes no cut. Loyalty, memberships and promotions are later phases (§§1, 82).
     */
    $pattern = '/loyalty|membership|promotion|coupon|voucher|commission|payout|wallet_balance|platform_fee|merchant_of_record|subscription|reward_points/i';

    $violations = [];

    foreach (paymentsSourceLines(['app/Modules/Payments/', 'app/Modules/Finance/', 'app/Http/Controllers/Api/PaymentController.php', 'app/Http/Controllers/Api/FinanceController.php', 'app/Livewire/Center/InvoicePayments.php', 'app/Livewire/Center/FinanceOverview.php', 'app/Livewire/Center/Expenses.php']) as $line) {
        if (preg_match($pattern, $line['source']) === 1) {
            $violations[] = $line['path'].':'.$line['line'].'  '.trim($line['source']);
        }
    }

    foreach (glob(dirname(__DIR__, 2).'/database/migrations/tenant/2026_09_14_*.php') ?: [] as $file) {
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

it('renders payment screens without unescaped output', function (): void {
    $violations = [];

    foreach (translatableSourceFiles() as $path => $contents) {
        $scanned = in_array($path, [
            'resources/views/livewire/center/invoice-payments.blade.php',
            'resources/views/livewire/center/payment-gateways.blade.php',
            'resources/views/livewire/center/finance-overview.blade.php',
            'resources/views/livewire/center/expenses.blade.php',
            'resources/views/livewire/center/receipts.blade.php',
            'resources/views/livewire/center/shifts.blade.php',
            'resources/views/livewire/center/till-benefits.blade.php',
            'resources/views/sales/invoice-public.blade.php',
        ], true)
            // The money workspace's shared partials (tabs, window, expense forms).
            || str_starts_with($path, 'resources/views/livewire/center/finance/');

        if (! $scanned) {
            continue;
        }

        foreach (explode("\n", $contents) as $index => $source) {
            if (str_contains($source, '{!!') || str_contains($source, '@php')) {
                $violations[] = $path.':'.($index + 1).'  '.trim($source);
            }
        }
    }

    expect($violations)->toBe([]);
});
