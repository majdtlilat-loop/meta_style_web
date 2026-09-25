<?php

declare(strict_types=1);

use App\Kernel\Database\Concerns\AppendOnlyHistory;
use App\Modules\Loyalty\Application\LoyaltyPresenter;
use App\Modules\Loyalty\Application\LoyaltyQuery;
use App\Modules\Loyalty\Application\LoyaltySync;
use App\Modules\Loyalty\Domain\Models\LoyaltyTransaction;
use App\Modules\Memberships\Application\ActivateMemberships;
use App\Modules\Memberships\Application\MembershipsPresenter;
use App\Modules\Memberships\Application\MembershipsQuery;
use App\Modules\Memberships\Domain\Models\MembershipBenefitUsage;
use App\Modules\Packages\Application\ActivatePackages;
use App\Modules\Packages\Application\PackagesPresenter;
use App\Modules\Packages\Application\PackagesQuery;
use App\Modules\Packages\Domain\Models\PackageTransaction;

/*
|--------------------------------------------------------------------------
| The Loyalty, Memberships and Packages boundary
|--------------------------------------------------------------------------
|
| docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§1, 4 · docs/04-MODULE-BOUNDARIES.md.
|
| Benefits sit ABOVE Sales and Payments: they read them and hear their events,
| and plug into the till through Sales' generic seams. Nothing below may depend
| on them — Sales never imports them, Booking never consumes a package. Money
| never waits for them: every reaction to a payment runs after it commits.
| Reads stay reads: repairing state is the reconciler's job, or the job of the
| Action about to change a balance — never a query's.
|
*/

arch('sales, payments, finance and the operational modules do not depend on benefits')
    ->expect([
        'App\Modules\Sales',
        'App\Modules\Payments',
        'App\Modules\Finance',
        'App\Modules\Booking',
        'App\Modules\ServiceJourney',
        'App\Modules\Queue',
        'App\Modules\Resources',
    ])
    ->not->toUse(['App\Modules\Loyalty', 'App\Modules\Memberships', 'App\Modules\Packages']);

arch('customers, the catalog, branches and the kernel do not depend on benefits')
    ->expect(['App\Modules\Customers', 'App\Modules\Catalog', 'App\Modules\Branches', 'App\Kernel'])
    ->not->toUse(['App\Modules\Loyalty', 'App\Modules\Memberships', 'App\Modules\Packages']);

arch('the benefit modules do not depend on HTTP or Livewire')
    ->expect(['App\Modules\Loyalty', 'App\Modules\Memberships', 'App\Modules\Packages'])
    ->not->toUse([
        'App\Http\Controllers',
        'App\Livewire',
        'Livewire\Component',
        'Illuminate\Support\Facades\Request',
        'Illuminate\Support\Facades\Auth',
        'Illuminate\Support\Facades\Session',
    ]);

arch('benefit enums are string backed, so stored values survive a release')
    ->expect(['App\Modules\Loyalty\Domain\Enums', 'App\Modules\Memberships\Domain\Enums', 'App\Modules\Packages\Domain\Enums'])
    ->toBeStringBackedEnum();

arch('the benefit modules never touch the finance ledger')
    ->expect(['App\Modules\Loyalty', 'App\Modules\Memberships', 'App\Modules\Packages'])
    ->not->toUse('App\Modules\Finance');

arch('reading loyalty, memberships and packages never repairs or activates anything')
    ->expect([
        LoyaltyQuery::class,
        LoyaltyPresenter::class,
        MembershipsQuery::class,
        MembershipsPresenter::class,
        PackagesQuery::class,
        PackagesPresenter::class,
    ])
    ->not->toUse([LoyaltySync::class, ActivateMemberships::class, ActivatePackages::class]);

it('keeps points, sessions and membership usage history append-only at the model', function (): void {
    expect(class_uses_recursive(LoyaltyTransaction::class))->toContain(AppendOnlyHistory::class)
        ->and(class_uses_recursive(PackageTransaction::class))->toContain(AppendOnlyHistory::class)
        ->and(class_uses_recursive(MembershipBenefitUsage::class))->toContain(AppendOnlyHistory::class);
});

it('reacts to money and finalization only after the transaction commits', function (): void {
    /*
     * A handler of `PaymentSucceeded`, `RefundSucceeded`, `SaleFinalized` or
     * `JourneyCompleted` in a benefit module must schedule its work through
     * `AfterCommit` — never write inside the payment's transaction, where a
     * benefit failure would roll the money back (§1).
     *
     * It may first CAPTURE what is true at that instant, which is a read and
     * cannot fail the payment: the repair needs the rule and the entitlement as
     * they were when the money moved, not as they are when it runs (§6).
     */
    $handlers = 0;
    $violations = [];

    foreach (appSourceWithoutComments() as $path => $contents) {
        if (preg_match('#^app/Modules/(Loyalty|Memberships|Packages)/#', $path) !== 1) {
            continue;
        }

        $found = preg_match_all(
            '/public function handle(PaymentSucceeded|RefundSucceeded|SaleFinalized|JourneyCompleted)\([^)]*\)\s*:\s*void\s*\{(.*?)\n    \}/s',
            $contents,
            $matches,
            PREG_SET_ORDER,
        );

        $handlers += (int) $found;

        foreach ($matches as $match) {
            $body = preg_replace('/^\s*\$moment = \$this->rules->capture\(\);\s*/', '', (string) $match[2]);

            if (preg_match('/^\s*\$this->afterCommit->run\(/', (string) $body) !== 1) {
                $violations[] = $path.'  handle'.$match[1].' does not schedule its work with AfterCommit::run';
            }
        }
    }

    // The scan must find the handlers it checks, or it checks nothing.
    expect($handlers)->toBeGreaterThanOrEqual(7)
        ->and($violations)->toBe([]);
});
