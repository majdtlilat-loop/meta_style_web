<?php

declare(strict_types=1);

use App\Kernel\SaaS\TrialPolicy;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| One center's failure must never stall the platform (docs/02-TENANCY.md §7).
| The rule that expresses it is per-tenant FAULT ISOLATION, not the queue: an
| entry that walks every center has to attempt each one independently and step
| over the ones that fail. Where the work per center is substantial it earns a
| dispatched job; a single indexed DELETE does not, and paying a queue round
| trip per center to run one would be machinery for its own sake.
|
*/

// Destroys bootstrap credentials whose retry window has closed. Hourly is
// ample for a 24-hour window and keeps the exposure bounded (ADR-031).
Schedule::command('metastyle:registration:sweep')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// Keeps stored subscription status honest for reporting. Enforcement does NOT
// depend on this — expiry is computed on read, so a lapsed trial is refused
// whether or not this has run (docs/05-ENTITLEMENTS.md §7).
Schedule::call(fn () => app(TrialPolicy::class)->expireLapsedTrials())
    ->hourly()
    ->name('metastyle:trials:expire')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Deletes expired idempotency keys from every center.
 *
 * HOURLY, not daily, because a completed key holds the response body it
 * replays. Keys live 24 hours (`Idempotency::TTL_HOURS`), so hourly bounds the
 * oldest surviving row at about 25 hours where a daily pass would allow nearly
 * 48 — the difference between a retention rule and a rounding error. The work
 * is one indexed DELETE per center, so the extra runs cost close to nothing.
 *
 * Correctness never depends on this. An expired row is also reclaimed by the
 * next request that reuses its key; this is what stops the table growing
 * forever at a center whose clients never retry.
 *
 * `withoutOverlapping` because the pass walks every center and a slow one must
 * not be joined by the next hour's run; `onOneServer` because every worker
 * would otherwise sweep the same centers simultaneously.
 */
Schedule::command('metastyle:idempotency:sweep')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
