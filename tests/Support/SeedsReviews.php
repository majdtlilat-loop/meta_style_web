<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Kernel\Authorization\Models\Role;
use App\Kernel\Authorization\SystemRole;
use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Tenant;
use App\Modules\Booking\Application\Actions\CreateAppointment;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Reviews\Application\Actions\ManageReviewInvitation;
use App\Modules\Reviews\Application\Actions\SubmitReview;
use App\Modules\Reviews\Domain\Data\RatingInput;
use App\Modules\Reviews\Domain\Data\ReviewSubmission;
use App\Modules\Reviews\Domain\Enums\RatingDimension;
use App\Modules\Reviews\Domain\Models\Review;
use App\Modules\Reviews\Domain\Models\ReviewInvitation;
use App\Modules\ServiceJourney\Application\Actions\CheckInAppointment;
use App\Modules\ServiceJourney\Application\Actions\CompleteJourney;
use App\Modules\ServiceJourney\Application\Actions\CreateWalkInVisit;
use App\Modules\ServiceJourney\Application\Actions\ReassignStageEmployee;
use App\Modules\ServiceJourney\Application\Actions\TransitionStage;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Seeding for reviews and notifications.
 *
 * A review needs a REAL completed visit behind it, so these build one through
 * the actual Journey actions rather than inserting rows: a test whose fixture
 * skipped `TransitionStage` would prove nothing about eligibility, which is
 * entirely about what those transitions recorded
 * (docs/22-REVIEWS.md §3, docs/11-TESTING-STRATEGY.md).
 */
trait SeedsReviews
{
    /**
     * `reviews`, plus the `booking` that PERFORMING a visit needs.
     *
     * The two are unrelated in the catalog — `reviews` depends on nothing
     * (docs/22-REVIEWS.md §18). `booking` is granted here because every
     * `ServiceJourney` Action gates on it, so a fixture cannot create the
     * completed visit a review is about without it. `grantReviewsOnly()` is
     * for the tests that prove the two really are independent.
     */
    protected function grantReviews(?string $tenantId = null): void
    {
        $this->grantEntitlement('booking', $tenantId);
        $this->grantEntitlement('reviews', $tenantId);
    }

    protected function grantReviewsOnly(?string $tenantId = null): void
    {
        $this->grantEntitlement('reviews', $tenantId);
    }

    /**
     * A BOOKED visit for a named customer: appointment, check-in, performed,
     * closed. The counterpart of {@see customerVisit()}, which is a walk-in.
     *
     * @param  array<string, mixed>  $seed
     */
    protected function bookedCustomerVisit(array $seed, User $user, Customer $customer): ServiceJourney
    {
        $appointment = $this->bookFor($seed, $user, $customer);
        $journey = app(CheckInAppointment::class)($appointment, $user);

        /** @var JourneyStage $stage */
        $stage = $journey->stages()->orderBy('position')->firstOrFail();

        app(ReassignStageEmployee::class)($stage, $seed['employee']->uuid, $user);
        $stage = $stage->fresh() ?? $stage;

        app(TransitionStage::class)($stage, StageStatus::InService, $user);
        app(TransitionStage::class)($stage->fresh() ?? $stage, StageStatus::Completed, $user);
        app(CompleteJourney::class)($journey->fresh() ?? $journey, $user);

        return $journey->fresh() ?? $journey;
    }

    /**
     * A walk-in visit for a NAMED customer, performed and closed.
     *
     * A walk-in reserves nobody, so its stages start with no employee. The
     * seeded one is assigned before the work is performed — which is what makes
     * an employee rating possible at all, and is exactly the record a rating is
     * read from (docs/22-REVIEWS.md §11). Pass `$performer: false` for the
     * visit where nobody was ever recorded.
     *
     * @param  array<string, mixed>  $seed
     * @param  list<string>  $outcomes  one per stage: completed, skipped, waiting
     * @param  list<string>|null  $serviceUuids
     */
    protected function customerVisit(array $seed, User $user, Customer $customer, array $outcomes = ['completed'], ?array $serviceUuids = null, bool $complete = true, bool $performer = true): ServiceJourney
    {
        $journey = app(CreateWalkInVisit::class)(
            new WalkInRequest(
                branchUuid: $seed['branch']->uuid,
                serviceUuids: $serviceUuids ?? array_fill(0, count($outcomes), $seed['service']->uuid),
                customerUuid: $customer->uuid,
                idempotencyToken: (string) Str::uuid(),
            ),
            $user,
        );

        /** @var list<JourneyStage> $stages */
        $stages = $journey->stages()->orderBy('position')->get()->all();

        foreach ($stages as $index => $stage) {
            $outcome = $outcomes[$index] ?? 'waiting';

            if ($outcome === 'completed') {
                if ($performer) {
                    app(ReassignStageEmployee::class)($stage, $seed['employee']->uuid, $user);
                    $stage = $stage->fresh() ?? $stage;
                }

                app(TransitionStage::class)($stage, StageStatus::InService, $user);
                app(TransitionStage::class)($stage->fresh() ?? $stage, StageStatus::Completed, $user);
            } elseif ($outcome === 'skipped') {
                app(TransitionStage::class)($stage, StageStatus::Skipped, $user, ['reason' => 'Changed their mind']);
            }
        }

        if ($complete) {
            app(CompleteJourney::class)($journey->fresh() ?? $journey, $user);
        }

        return $journey->fresh() ?? $journey;
    }

    /**
     * A staff member who holds one system role and works in given branches.
     *
     * Phase 12 targets notifications by PERMISSION and BRANCH, so a test that
     * only ever had the owner — who holds everything and works everywhere —
     * would prove nothing about either (docs/23-NOTIFICATIONS.md §5).
     *
     * @param  list<int>  $branchIds  empty means every branch
     */
    protected function seedStaffMember(SystemRole $role, array $branchIds = [], string $name = 'Branch Manager'): User
    {
        /** @var User $user */
        $user = User::query()->create([
            'name' => $name,
            'email' => Str::slug($name).'-'.Str::random(6).'@center.test',
            'is_active' => true,
            'is_owner' => false,
            'all_branches' => $branchIds === [],
        ]);

        /** @var Role $stored */
        $stored = Role::query()->where('key', $role->value)->firstOrFail();

        $user->roles()->sync([$stored->getKey()]);

        if ($branchIds !== []) {
            $user->syncBranchScope($branchIds);
        }

        $user->forgetPermissionCache();

        return $user;
    }

    /**
     * An appointment for a named customer, at a local time the branch is open.
     *
     * Reminders are about the branch's own clock, so the fixture books a wall
     * time rather than an offset from now — and the test then stands wherever
     * it needs to relative to it (docs/23-NOTIFICATIONS.md §13).
     *
     * @param  array<string, mixed>  $seed
     */
    protected function bookFor(array $seed, User $user, Customer $customer, int $daysAhead = 1, string $time = '10:00'): Appointment
    {
        return app(CreateAppointment::class)(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                // The BRANCH's day, never the UTC one: between 21:00 and midnight UTC
                // Baghdad is already on the next date (see SeedsPayments::branchToday()).
                startsAt: $this->localTime($seed['branch'], CarbonImmutable::now($seed['branch']->timezone)->addDays($daysAhead)->format('Y-m-d'), $time),
                lines: [new BookingLine(serviceUuid: $seed['service']->uuid)],
                customer: CustomerRef::existing($customer->uuid),
            ),
            BookingActor::staff($user),
        )->appointment;
    }

    /**
     * A whole review, end to end: a fresh visit, its link, and a submission.
     *
     * Through the real actions every time. A summary test whose fixture wrote
     * `reviews` rows directly would be asserting arithmetic over data no
     * customer could have produced (docs/11-TESTING-STRATEGY.md).
     *
     * @param  array<string, mixed>  $seed
     */
    protected function leaveReview(array $seed, User $user, int $overall, ?int $service = null, ?int $employee = null, string $phone = '0750 123 4500'): Review
    {
        $journey = $this->customerVisit($seed, $user, $this->seedCustomer('Reviewer '.$phone, $phone));
        $token = $this->reviewToken($journey, $user);

        /** @var JourneyStage $stage */
        $stage = JourneyStage::query()
            ->where('service_journey_id', $journey->getKey())
            ->where('status', StageStatus::Completed->value)
            ->sole();

        $ratings = [];

        if ($service !== null) {
            $ratings[] = new RatingInput($stage->uuid, RatingDimension::Service, $service);
        }

        if ($employee !== null) {
            $ratings[] = new RatingInput($stage->uuid, RatingDimension::Employee, $employee);
        }

        return app(SubmitReview::class)->byToken($token, new ReviewSubmission($overall, null, $ratings));
    }

    /**
     * A real customer API token, through the public sign-in route.
     *
     * Issued the way a customer's phone would get one, so the guard under test
     * is the one production uses. `forgetGuards()` afterwards because a single
     * application instance caches the guard that answered — the harness detail
     * that has already produced one false positive in this suite
     * (docs/11-TESTING-STRATEGY.md).
     */
    protected function customerTokenFor(Tenant $tenant, string $phone = '0750 123 4567', string $password = 'correct-horse-battery-staple'): string
    {
        $token = (string) $this->postJson('/api/v1/public/customer/auth/token', [
            'center_key' => $this->publicKeyOf($tenant),
            'phone' => $phone,
            'password' => $password,
        ])->assertOk()->json('data.token.token');

        $this->app['auth']->forgetGuards();

        return $token;
    }

    protected function invitationFor(ServiceJourney $journey): ?ReviewInvitation
    {
        /** @var ReviewInvitation|null $invitation */
        $invitation = ReviewInvitation::query()->where('service_journey_id', $journey->getKey())->first();

        return $invitation;
    }

    /**
     * A working review link for a visit.
     *
     * Through the real reissue action, because that is the ONLY way a plaintext
     * secret exists at all — nothing can read one back out of the database
     * (docs/22-REVIEWS.md §5).
     */
    protected function reviewToken(ServiceJourney $journey, User $user): string
    {
        return app(ManageReviewInvitation::class)->reissue($journey, $user);
    }
}
