<?php

declare(strict_types=1);

namespace App\Kernel\Reconciliation\Contracts;

use Carbon\CarbonImmutable;

/**
 * Repairs derived state from canonical facts, idempotently.
 *
 * A module whose reactions run AFTER a commit (`Kernel\Database\AfterCommit`)
 * implements this and is tagged `ReconcileCommand::TAG`. It re-derives what those
 * reactions should have written from the facts they reacted to — payments,
 * refunds, finalized sales, completed visits since `$since` — and writes only
 * what is missing. Running it twice changes nothing the second time.
 *
 * Called inside a bound tenant context. The Kernel knows none of the modules
 * that register here.
 */
interface Reconciler
{
    /** A short name for the command's report, e.g. "loyalty". */
    public function name(): string;

    /**
     * @return int how many things were repaired
     */
    public function reconcile(CarbonImmutable $since): int;
}
