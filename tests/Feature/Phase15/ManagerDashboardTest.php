<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Authorization\SystemRole;
use App\Kernel\Identity\Models\User;
use App\Kernel\SaaS\CurrentSubscription;
use App\Livewire\Center\Dashboard;
use App\Modules\Booking\Application\Actions\CreateAppointment;
use App\Modules\Booking\Application\DashboardAppointments;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Employees\Domain\Models\Employee;
use App\Modules\Queue\Application\Actions\CreateWalkInTicket;
use App\Modules\Queue\Application\DashboardQueueSnapshot;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The Manager overview
|--------------------------------------------------------------------------
|
| One date range drives every figure; the period comes from the same read
| contracts the Standard Reports use, and nothing untranslated or generic
| ("Needs attention") reaches the page.
|
*/

it('renders the overview with the shared date range in every interface language', function (string $locale, string $direction, string $title, string $preset): void {
    $center = $this->registerCenter('Overview Center', 'owner@overview.test');
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug, $locale, $direction, $title, $preset): void {
        $this->actingAs($owner);

        $html = $this->get("http://{$slug}.localhost:8000/manager?locale={$locale}&range=last_7_days")
            ->assertOk()
            ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'"', false)
            ->assertSee('<h1>'.$title.'</h1>', false)
            ->assertSee('class="date-range"', false)
            ->assertSee('aria-pressed="true">'.$preset.'</button>', false)
            ->getContent();

        // No raw translation key, and no generic "needs attention" block.
        expect(preg_match('/\b(manager_dashboard|ui|labels)\.[a-z_]+\.[a-z_]+/', strip_tags($html)))->toBe(0)
            ->and(str_contains($html, 'Needs attention'))->toBeFalse();
    });
})->with([
    'English' => ['en', 'ltr', 'Overview', 'Last 7 days'],
    'Arabic' => ['ar', 'rtl', 'نظرة عامة', 'آخر 7 أيام'],
    'Kurdish Sorani' => ['ckb', 'rtl', 'پوختە', '٧ ڕۆژی ڕابردوو'],
]);

it('validates a custom range and applies it only when confirmed', function (): void {
    $center = $this->registerCenter('Range Center', 'owner@range.test');
    $owner = $this->ownerOf($center['tenant']);
    // The host normally supplies {center}; an in-process component has none.
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        Livewire::actingAs($owner)
            ->test(Dashboard::class)
            ->assertSet('range', 'this_month')
            ->call('setRange', 'custom')
            ->assertSet('customOpen', true)
            // Still this month until applied.
            ->assertSet('range', 'this_month')
            ->set('customFrom', now()->toDateString())
            ->set('customTo', now()->subDays(5)->toDateString())
            ->call('applyCustomRange')
            ->assertHasErrors(['customFrom', 'customTo'])
            ->assertSet('range', 'this_month')
            ->set('customFrom', now()->subDays(5)->toDateString())
            ->set('customTo', now()->toDateString())
            ->call('applyCustomRange')
            ->assertHasNoErrors()
            ->assertSet('range', 'custom')
            ->assertSet('from', now()->subDays(5)->toDateString())
            ->assertSet('customOpen', false)
            ->call('setRange', 'today')
            ->assertSet('range', 'today')
            ->assertSet('from', null);
    });
});

/*
| The plan chip: the center's own status in the reader's language — the raw
| `trialing` never reaches the page.
*/
it('shows the plan and trial status translated in every interface language', function (string $locale): void {
    $center = $this->registerCenter('Plan Center', 'owner@plan.test');
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug, $locale): void {
        $summary = app(CurrentSubscription::class)->summary();
        expect($summary?->trialDaysLeft)->toBeInt();
        $days = (int) $summary?->trialDaysLeft;

        $this->actingAs($owner);
        $html = $this->get("http://{$slug}.localhost:8000/manager?locale={$locale}")
            ->assertOk()
            ->assertSee(trans('platform_labels.subscription_status.trialing', [], $locale))
            ->assertSee(trans_choice('manager_dashboard.plan.trial_days_left', $days, ['count' => $days], $locale))
            ->getContent();

        expect(str_contains(strip_tags((string) $html), 'trialing'))->toBeFalse();
    });
})->with(['en', 'ar', 'ckb']);

it('counts only the current business day in the queue snapshot', function (): void {
    $center = $this->registerCenter('Queue Snapshot Center', 'owner@queue-snapshot.test');

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $this->grantQueueEntitlements();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = static fn (string $name) => app(CreateWalkInTicket::class)(
            new WalkInRequest(branchUuid: $seed['branch']->uuid, serviceUuids: [$seed['service']->uuid], name: $name, idempotencyToken: (string) Str::uuid()),
            $owner,
        )['ticket'];

        $ticket('Today Tara');
        $stale = $ticket('Yesterday Yara');
        // Left open from an earlier day: an operational problem, not somebody waiting now.
        $stale->forceFill(['business_date' => CarbonImmutable::parse($stale->business_date)->subDay()->toDateString()])->save();

        $snapshot = app(DashboardQueueSnapshot::class)->now($owner);

        expect($snapshot['states']['waiting'] ?? 0)->toBe(1)
            ->and($snapshot['open'])->toBe(1);
    });
});

it('shows a view-own employee only their own bookings, and a branch manager only their branch', function (): void {
    $center = $this->registerCenter('Scope Center', 'owner@scope.test');

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $other = $this->seedBookableEmployee($seed['service'], $seed['branch'], 'Other Stylist');
        $second = $this->seedBranch();
        $this->openEveryDay($second);

        $book = function (Employee $employee, string $name, string $time) use ($seed, $owner): void {
            app(CreateAppointment::class)(
                new BookingRequest(
                    branchUuid: $seed['branch']->uuid,
                    startsAt: $this->localTime($seed['branch'], CarbonImmutable::now($seed['branch']->timezone)->addDays(2)->format('Y-m-d'), $time),
                    lines: [new BookingLine(serviceUuid: $seed['service']->uuid, employeeUuid: $employee->uuid)],
                    customer: CustomerRef::details($name, '+96475'.random_int(10000000, 99999999)),
                ),
                BookingActor::staff($owner),
            );
        };

        $book($seed['employee'], 'Mine Maha', '10:00');
        $book($other, 'Theirs Tala', '11:00');

        $stylist = $this->staffWith([Permission::AppointmentViewOwn], 'stylist@scope.test');
        $seed['employee']->forceFill(['user_id' => $stylist->getKey()])->save();
        $elsewhere = $this->seedStaffMember(SystemRole::Manager, [(int) $second->getKey()]);
        $reads = app(DashboardAppointments::class);

        expect(array_column($reads->upcoming($owner), 'customer'))->toBe(['Mine Maha', 'Theirs Tala'])
            ->and(array_column($reads->upcoming($stylist), 'customer'))->toBe(['Mine Maha'])
            ->and($reads->upcoming($elsewhere))->toBe([])
            // Other people's schedules are not a view-own question.
            ->and($reads->bookedEmployeesToday($stylist))->toBe([]);
    });
});

it('tells a role without any overview figure so, instead of an empty page', function (): void {
    $center = $this->registerCenter('Empty Role Center', 'owner@empty-role.test');
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function (): void {
        /** @var User $plain */
        $plain = $this->staffWith([], 'plain@empty-role.test');

        Livewire::actingAs($plain)
            ->test(Dashboard::class)
            ->assertOk()
            ->assertSee(__('manager_dashboard.empty.title'))
            ->assertDontSee(__('manager_dashboard.sections.commerce'));
    });
});

it('renders the Today preset with hourly charts and the live strip', function (): void {
    $center = $this->registerCenter('Today Center', 'owner@today.test');
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug): void {
        $seed = $this->seedBookableCenter();
        $this->issuedInvoice($seed, $this->ownerWithCatalogAccess());
        $this->actingAs($owner);

        $this->get("http://{$slug}.localhost:8000/manager?range=today")
            ->assertOk()
            ->assertSee('aria-pressed="true">Today</button>', false)
            ->assertSee(__('manager_dashboard.live.label'))
            ->assertSee(__('manager_dashboard.kpi.completed_visits'))
            ->assertSee('23:00');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
