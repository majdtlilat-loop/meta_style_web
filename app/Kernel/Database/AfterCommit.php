<?php

declare(strict_types=1);

namespace App\Kernel\Database;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs SECONDARY work after the tenant transaction it was triggered from has
 * committed — and never lets that work fail the transaction or its caller.
 *
 * For reactions that must not be able to undo or misreport the fact that
 * triggered them: loyalty earned on a payment, a membership or package
 * activated by one. The payment commits first, on its own; then, and only
 * then, the reaction runs. If the reaction fails it is REPORTED and dropped —
 * never rethrown, because the payment it follows is already true, and a 500 on
 * a request that took somebody's money would be a lie about it.
 *
 * What makes dropping it safe is the other half of the contract: every
 * reaction run through here must be IDEMPOTENT and REPLAYABLE from canonical
 * facts, with a `Reconciler` that finds and repairs what a failure — or a
 * process that died between the commit and the callback — left undone
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §1).
 *
 * Outside a transaction the work runs immediately, with the same guard.
 */
final class AfterCommit
{
    /**
     * @param  callable(): mixed  $work  its result is not used
     */
    public function run(string $label, callable $work): void
    {
        DB::connection('tenant')->afterCommit(static function () use ($label, $work): void {
            try {
                $work();
            } catch (Throwable $e) {
                // Reported with its label, never rethrown. Reconciliation
                // repairs whatever this left undone.
                report(new AfterCommitFailed($label, $e));
            }
        });
    }
}
