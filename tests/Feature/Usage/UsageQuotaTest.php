<?php

declare(strict_types=1);

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Enums\ActorType;
use App\Kernel\Audit\Enums\AuditSource;
use App\Kernel\SaaS\Models\Subscription;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Usage\Actions\SetTenantLimitOverride;
use App\Kernel\Usage\AllowanceSync;
use App\Kernel\Usage\Exceptions\QuotaExceeded;
use App\Kernel\Usage\Exceptions\UnknownResource;
use App\Kernel\Usage\Models\UsageCounter;
use App\Kernel\Usage\Models\UsageEvent;
use App\Kernel\Usage\Usage;
use App\Kernel\Usage\UsagePeriod;
use App\Kernel\Usage\UsageStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Commercial usage: counted once, spent atomically, never overspent
|--------------------------------------------------------------------------
|
| docs/26-USAGE-QUOTAS.md.
|
| Two mechanisms carry everything here, and both are DATABASE guarantees rather
| than timing hopes:
|
|     unique(resource, source_type, source_uuid)   counted exactly once
|     one conditional UPDATE                        spent atomically
|
| Every test below is an attempt to break one of them.
|
*/

it('creates the period counter from the plan and the system default', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $usage = app(Usage::class);

        $counter = $usage->counter('ai_runs');

        // `config/usage.php`: no plan says anything, so the system default.
        expect($counter->allowance_snapshot)->toBe(500)
            ->and($counter->used)->toBe(0)
            ->and($counter->allowance_version)->toBeNull();

        // Unlimited is NULL, everywhere, with no sentinel number (§4).
        expect($usage->counter('ai_input_tokens')->allowance_snapshot)->toBeNull()
            ->and($usage->counter('ai_input_tokens')->isUnlimited())->toBeTrue();
    });
});

it('counts one thing once, however many times it is reported', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $usage = app(Usage::class);

        // The same fact, reported four times — a replayed webhook, a retried
        // listener, two reconciler passes.
        for ($i = 0; $i < 4; $i++) {
            $usage->consume('ai_runs', 'ai_run', 'run-uuid-0001');
        }

        expect($usage->counter('ai_runs')->used)->toBe(1)
            ->and(UsageEvent::query()->where('source_uuid', 'run-uuid-0001')->count())->toBe(1);

        // A DIFFERENT fact is a different row.
        $usage->consume('ai_runs', 'ai_run', 'run-uuid-0002');

        expect($usage->counter('ai_runs')->used)->toBe(2);
    });
});

it('refuses the request that would exceed the allowance, and records nothing for it', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $usage = app(Usage::class);

        $this->setAllowance('ai_runs', 3);

        foreach (['a', 'b', 'c'] as $id) {
            $usage->consume('ai_runs', 'ai_run', 'run-'.$id);
        }

        expect($usage->counter('ai_runs')->used)->toBe(3)
            ->and($usage->allows('ai_runs'))->toBeFalse();

        expect(fn () => $usage->consume('ai_runs', 'ai_run', 'run-d'))
            ->toThrow(QuotaExceeded::class);

        /*
         * The refusal rolled the EVENT back with it. The evidence must never
         * record usage that was denied — otherwise a reconciler rebuilding the
         * counter from events would resurrect it (§5).
         */
        expect($usage->counter('ai_runs')->used)->toBe(3)
            ->and(UsageEvent::query()->where('source_uuid', 'run-d')->count())->toBe(0);
    });
});

it('never lets two simultaneous requests take the same last unit', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->setAllowance('ai_runs', 100);

        $usage = app(Usage::class);

        for ($i = 0; $i < 99; $i++) {
            $usage->consume('ai_runs', 'ai_run', 'warmup-'.$i);
        }

        expect($usage->counter('ai_runs')->used)->toBe(99);

        /*
         * 99 of 100, and two requests for the last unit — from two GENUINELY
         * separate connections, which is the only way to prove this. One
         * consumes; the other must be refused. 101 has to be unreachable (§5).
         */
        [$second, $close] = secondTenantConnection('usage_race');

        $counter = UsageCounter::query()->where('resource', 'ai_runs')->firstOrFail();

        // The same conditional UPDATE the Kernel issues, from the competing
        // connection — no read, no comparison in PHP, no window.
        $affected = $second->table('usage_counters')
            ->where('resource', 'ai_runs')
            ->where('period_start', $counter->period_start)
            ->whereRaw('used + 1 <= allowance_snapshot')
            ->update(['used' => DB::raw('used + 1')]);

        expect($affected)->toBe(1);

        // Now the application's own path finds the allowance spent.
        expect(fn () => $usage->consume('ai_runs', 'ai_run', 'the-last-one'))
            ->toThrow(QuotaExceeded::class);

        $close();

        expect((int) DB::connection('tenant')->table('usage_counters')->where('resource', 'ai_runs')->value('used'))
            ->toBe(100);
    });
});

it('converges when two first requests of a period arrive together', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $tenantId = app(TenantContext::class)->require()->id;
        $period = UsagePeriod::resolve(config(), $tenantId);

        [$second, $close] = secondTenantConnection('usage_first_row');

        $now = CarbonImmutable::now()->utc();

        /*
         * The competing request wins the race to create the row. A
         * check-then-insert would now give the application a duplicate-key
         * error — surfaced to a customer as a 500 on the first message of the
         * month (§5).
         */
        $second->table('usage_counters')->insert([
            'resource' => 'ai_runs',
            'period_start' => $period->start,
            'period_end' => $period->end,
            'allowance_snapshot' => 500,
            'used' => 7,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // `insertOrIgnore` cannot fail on a duplicate; the re-read finds
        // whichever row won.
        $counter = app(Usage::class)->counter('ai_runs');

        expect($counter->used)->toBe(7)
            ->and(UsageCounter::query()->where('resource', 'ai_runs')->count())->toBe(1);

        $close();
    });
});

it('meters without ever refusing, because a token count is only known afterwards', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $usage = app(Usage::class);

        // An allowance on a METERED resource states an expectation; it refuses
        // nothing. Nothing in the product claims a hard token cap (§3).
        $this->setAllowance('ai_output_tokens', 100);

        $usage->meter('ai_output_tokens', 'ai_run_turn', 'turn-1', 90);
        $usage->meter('ai_output_tokens', 'ai_run_turn', 'turn-2', 90);

        $counter = $usage->counter('ai_output_tokens');

        expect($counter->used)->toBe(180)
            // Over the stated allowance, recorded honestly, and `remaining` is
            // clamped so no dashboard shows a negative number.
            ->and($counter->remaining())->toBe(0)
            ->and($usage->summary('ai_output_tokens')->status)->toBe(UsageStatus::Exhausted);

        // And `consume()` on a metered resource behaves like `meter()`: a
        // caller cannot accidentally create a hard limit the product does not
        // claim to have.
        $usage->consume('ai_output_tokens', 'ai_run_turn', 'turn-3', 50);

        expect($usage->counter('ai_output_tokens')->used)->toBe(230);
    });
});

it('reports a percentage only where there is a denominator', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $usage = app(Usage::class);

        $this->setAllowance('ai_runs', 10);

        for ($i = 0; $i < 7; $i++) {
            $usage->consume('ai_runs', 'ai_run', 'run-'.$i);
        }

        $ai = $usage->summary('ai_runs');

        expect($ai->percent)->toBe(70)
            ->and($ai->remaining)->toBe(3)
            ->and($ai->status)->toBe(UsageStatus::Warning)
            ->and($ai->enforced)->toBeTrue();

        /*
         * Unlimited has NO percentage. It is not 0 and not 100 — the fraction
         * has no denominator — and rendering either would state a limit the
         * center does not have (§10).
         */
        $unlimited = $usage->summary('wa_inbound');

        expect($unlimited->allowance)->toBeNull()
            ->and($unlimited->percent)->toBeNull()
            ->and($unlimited->remaining)->toBeNull()
            ->and($unlimited->status)->toBe(UsageStatus::Normal)
            ->and($unlimited->enforced)->toBeFalse();
    });
});

it('applies an increase at once and defers a decrease to the next period', function (): void {
    $center = $this->registerCenter();
    $tenantId = $center['tenant']->id;

    $this->asCenter($center['tenant'], function (): void {
        app(Usage::class)->consume('ai_runs', 'ai_run', 'first');
    });

    $actor = new Actor(ActorType::Platform, AuditSource::Console, null, 'support');

    // A RAISE. The center has just bought more and must not wait for a billing
    // boundary (§6).
    app(SetTenantLimitOverride::class)($tenantId, 'ai_runs', 2000, $actor, 'Upgraded mid-month.');

    $this->asCenter($center['tenant'], function (): void {
        expect(app(AllowanceSync::class)->reconcile(CarbonImmutable::now()->utc()->subDay()))->toBe(1)
            ->and(app(Usage::class)->counter('ai_runs')->allowance_snapshot)->toBe(2000);

        // Idempotent: running it again changes nothing.
        expect(app(AllowanceSync::class)->reconcile(CarbonImmutable::now()->utc()->subDay()))->toBe(0);
    });

    // A CUT. A center part-way through a month they have paid for does not get
    // cut off because of a pricing change.
    app(SetTenantLimitOverride::class)($tenantId, 'ai_runs', 50, $actor, 'Plan downgraded.');

    $this->asCenter($center['tenant'], function (): void {
        expect(app(AllowanceSync::class)->reconcile(CarbonImmutable::now()->utc()->subDay()))->toBe(0)
            // UNCHANGED, and deliberately not marked applied — recording the
            // version would cancel the decrease instead of deferring it (§8).
            ->and(app(Usage::class)->counter('ai_runs')->allowance_snapshot)->toBe(2000);
    });

    // The audited escape hatch, for abuse or a compromised account.
    app(SetTenantLimitOverride::class)($tenantId, 'ai_runs', 50, $actor, 'Abuse: capped now.', enforceImmediately: true);

    $this->asCenter($center['tenant'], function (): void {
        expect(app(AllowanceSync::class)->reconcile(CarbonImmutable::now()->utc()->subDay()))->toBe(1)
            ->and(app(Usage::class)->counter('ai_runs')->allowance_snapshot)->toBe(50);
    });

    // Flagged on the row, so the exception is visible in the control plane and
    // not only in an audit line — it can stop a feature a center is using right
    // now, in front of their own customers (§6).
    $override = DB::connection('control')->table('tenant_limit_overrides')
        ->where('tenant_id', $tenantId)
        ->where('resource', 'ai_runs')
        ->first();

    expect($override)->not->toBeNull()
        ->and((bool) $override->enforce_immediately)->toBeTrue()
        ->and($override->reason)->toBe('Abuse: capped now.')
        // Every write bumps the version, including one that re-states the same
        // number — the tenant's copy is "from this state of this row" (§8).
        ->and((int) $override->version)->toBe(3);
});

it('treats an unlimited grant as an increase, and a finite one after it as a cut', function (): void {
    $center = $this->registerCenter();
    $tenantId = $center['tenant']->id;

    $this->asCenter($center['tenant'], function (): void {
        app(Usage::class)->consume('ai_runs', 'ai_run', 'first');
    });

    $actor = new Actor(ActorType::Platform, AuditSource::Console, null, 'support');

    app(SetTenantLimitOverride::class)($tenantId, 'ai_runs', null, $actor, 'Unlimited for the pilot.');

    $this->asCenter($center['tenant'], function (): void {
        app(AllowanceSync::class)->reconcile(CarbonImmutable::now()->utc()->subDay());

        // Unlimited is MORE than any finite number, so it applies at once.
        expect(app(Usage::class)->counter('ai_runs')->allowance_snapshot)->toBeNull();
    });

    app(SetTenantLimitOverride::class)($tenantId, 'ai_runs', 900, $actor, 'Pilot over.');

    $this->asCenter($center['tenant'], function (): void {
        app(AllowanceSync::class)->reconcile(CarbonImmutable::now()->utc()->subDay());

        // And any finite number is LESS than unlimited, so it waits.
        expect(app(Usage::class)->counter('ai_runs')->allowance_snapshot)->toBeNull();
    });
});

it('starts a new period with a fresh counter and the current allowance', function (): void {
    $center = $this->registerCenter();
    $tenantId = $center['tenant']->id;

    // An authoritative subscription period, so the reset is on the center's own
    // billing boundary rather than on the first of the month (§11).
    $start = CarbonImmutable::now()->utc()->startOfDay()->subDays(10);

    Subscription::query()->where('tenant_id', $tenantId)->update([
        'current_period_start' => $start,
        'current_period_end' => $start->addDays(30),
    ]);

    $this->asCenter($center['tenant'], function (): void {
        $usage = app(Usage::class);

        for ($i = 0; $i < 5; $i++) {
            $usage->consume('ai_runs', 'ai_run', 'this-period-'.$i);
        }

        expect($usage->counter('ai_runs')->used)->toBe(5);
    });

    // The center is billed; the period rolls forward.
    Subscription::query()->where('tenant_id', $tenantId)->update([
        'current_period_start' => $start->addDays(30),
        'current_period_end' => $start->addDays(60),
    ]);

    $this->asCenter($center['tenant'], function (): void {
        $usage = app(Usage::class);

        expect($usage->counter('ai_runs')->used)->toBe(0)
            ->and($usage->counter('ai_runs')->allowance_snapshot)->toBe(500)
            // The old period's row is untouched history, not overwritten.
            ->and(UsageCounter::query()->where('resource', 'ai_runs')->count())->toBe(2);

        // And the same source id in the new period is a NEW fact: the unique
        // key includes the resource and the source, and the old row belongs to
        // a closed period.
        $usage->consume('ai_runs', 'ai_run', 'next-period-1');

        expect($usage->counter('ai_runs')->used)->toBe(1);
    });
});

it('falls back to a deterministic UTC month when there is no subscription period', function (): void {
    $center = $this->registerCenter();

    Subscription::query()->where('tenant_id', $center['tenant']->id)->update([
        'current_period_start' => null,
        'current_period_end' => null,
    ]);

    $this->asCenter($center['tenant'], function (): void {
        $period = UsagePeriod::resolve(config(), app(TenantContext::class)->require()->id);

        $expected = CarbonImmutable::now()->utc()->startOfMonth()->startOfDay();

        /*
         * UTC, explicitly. `startOfMonth()` on a host set to `Asia/Baghdad` and
         * on one set to `UTC` are three hours apart — and the counter's unique
         * key is the period start, so the two would be different ROWS (§11).
         */
        expect($period->start->toDateTimeString())->toBe($expected->toDateTimeString())
            ->and($period->start->timezoneName)->toBe('UTC')
            ->and($period->end->toDateTimeString())->toBe($expected->addMonth()->toDateTimeString());
    });
});

it('refuses to meter a resource that is not in the catalog', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        /*
         * A silently accepted typo would write rows nothing can interpret and
         * hide the fact that a feature is not metered at all — a revenue gap
         * that surfaces months later rather than as an error today (§2).
         */
        expect(fn () => app(Usage::class)->meter('ai_runss', 'ai_run', 'typo'))
            ->toThrow(UnknownResource::class);

        expect(fn () => app(Usage::class)->allows('whatever'))
            ->toThrow(UnknownResource::class);
    });
});

it('keeps one center usage entirely out of another', function (): void {
    $first = $this->registerCenter('Center One', 'one@alpha.test');
    $second = $this->registerCenter('Center Two', 'two@alpha.test');

    $this->asCenter($first['tenant'], function (): void {
        $usage = app(Usage::class);

        for ($i = 0; $i < 12; $i++) {
            $usage->consume('ai_runs', 'ai_run', 'one-'.$i);
        }

        expect($usage->counter('ai_runs')->used)->toBe(12);
    });

    $this->asCenter($second['tenant'], function (): void {
        // The counters are in each center's OWN database, so this is isolation
        // by construction rather than by a filter somebody has to remember.
        expect(app(Usage::class)->counter('ai_runs')->used)->toBe(0)
            ->and(UsageEvent::query()->count())->toBe(0);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
