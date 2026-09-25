<?php

declare(strict_types=1);

use App\Kernel\SaaS\TrialPolicy;
use App\Modules\PlatformOperations\Application\ApplyScheduledSubscriptionChanges;
use App\Modules\PlatformOperations\Application\RefreshOperationalProjections;
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

/*
 * Repairs after-commit reactions from canonical facts: loyalty earned on a
 * payment, a membership or package activated by one, a refund's reversal.
 *
 * Those reactions run only AFTER the payment commits, so a failure in them can
 * never undo real money — and this is what stops such a failure, or a process
 * that died between the commit and the callback, from losing them for good.
 * Every reconciler is idempotent: an hour with nothing lost writes nothing
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §1). Benefit screens and checkout
 * also reconcile the one customer in front of them before use, so correctness
 * never waits for this.
 */
Schedule::command('metastyle:reconcile --days=3')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Appointment reminders, benefit expiry warnings and notification retention.
 *
 * HOURLY, and bounded on both sides. The reminder lead time is a day by
 * default, so an hourly pass writes a reminder within an hour of the
 * appointment entering the window — and a pass that overlaps the previous one,
 * or catches up after an outage, writes nothing twice: every notification is
 * keyed on the fact it is about (docs/23-NOTIFICATIONS.md §§12–13).
 *
 * Correctness never depends on this. A missed hour means a reminder arrives an
 * hour later, not a lost appointment — the appointment, the invoice and the
 * benefit are the record, and none of them is written here.
 */
Schedule::command('metastyle:notifications:sweep')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Refreshes the control-plane usage projection Super Admin reads.
 *
 * REPORTING ONLY. Every quota decision is made against the center's own
 * `usage_counters` row, inside the center's own database, and never against
 * this copy — so a missed run makes the platform's dashboard an hour stale and
 * changes nothing about what any center is allowed to do
 * (docs/26-USAGE-QUOTAS.md §9).
 *
 * Hourly for the same reason the sweeps above are: it bounds how wrong the
 * report can be without paying for a pass nobody reads. Each center is
 * projected independently, so one unreachable database does not stop the rest.
 */
Schedule::command('metastyle:usage:project')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::call(fn (): int => app(ApplyScheduledSubscriptionChanges::class)())
    ->everyFifteenMinutes()
    ->name('metastyle:subscriptions:apply-scheduled')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * The Super Admin's Center Users directory: a read model of every center's
 * user accounts. A report, never a decision; changes made from the platform
 * re-project their center immediately, and this catches the rest.
 */
Schedule::command('metastyle:center-users:project')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::call(fn (): int => app(RefreshOperationalProjections::class)())
    ->everyFifteenMinutes()
    ->name('metastyle:operations:project')
    ->withoutOverlapping()
    ->onOneServer();
