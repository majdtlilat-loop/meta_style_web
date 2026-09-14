<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Kernel\Localization\TranslatedText;
use App\Modules\Booking\Domain\BookingSettings;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceAddon;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Customers\Domain\Models\CustomerAccount;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use App\Modules\Employees\Domain\Models\Employee;
use Carbon\CarbonImmutable;

/**
 * A center that can actually take a booking.
 *
 * Every method assumes a tenant is already bound — call them inside
 * `asCenter()`. A helper that bound its own tenant would hide exactly the
 * context mistakes the isolation tests exist to catch.
 *
 * Fixtures are created directly rather than through the Actions. Tests that
 * care about authorization, availability, audit or concurrency call the engine
 * themselves; these are the givens.
 */
trait SeedsBooking
{
    /**
     * A branch open 09:00–17:00 every day, one active stylist eligible for the
     * seeded haircut, and a 30-minute service at 20,000 IQD.
     *
     * @return array{branch: Branch, service: Service, employee: Employee, addon: ServiceAddon}
     */
    protected function seedBookableCenter(string $timezone = 'Asia/Baghdad'): array
    {
        $catalog = $this->seedCatalog();

        /** @var Branch $branch */
        $branch = Branch::query()->where('is_main', true)->firstOrFail();

        $branch->forceFill(['timezone' => $timezone])->save();

        $this->openEveryDay($branch);

        $employee = $this->seedBookableEmployee($catalog['service'], $branch);

        return [
            'branch' => $branch,
            'service' => $catalog['service'],
            'employee' => $employee,
            'addon' => $catalog['addon'],
        ];
    }

    /**
     * Replaces a branch's weekly hours with one interval on every day.
     */
    protected function openEveryDay(Branch $branch, string $opens = '09:00', string $closes = '17:00'): void
    {
        $branch->workingHours()->delete();

        for ($day = 0; $day <= 6; $day++) {
            $branch->workingHours()->create([
                'day_of_week' => $day,
                'opens_at' => $opens,
                'closes_at' => $closes,
                'sort_order' => 0,
            ]);
        }

        $branch->unsetRelation('workingHours');
    }

    /**
     * Split hours: 09:00–13:00, closed, 16:00–22:00 — the normal shape in this
     * market, and the case a single open/close pair would get wrong.
     */
    protected function openSplit(Branch $branch): void
    {
        $branch->workingHours()->delete();

        for ($day = 0; $day <= 6; $day++) {
            $branch->workingHours()->createMany([
                ['day_of_week' => $day, 'opens_at' => '09:00', 'closes_at' => '13:00', 'sort_order' => 0],
                ['day_of_week' => $day, 'opens_at' => '16:00', 'closes_at' => '22:00', 'sort_order' => 1],
            ]);
        }

        $branch->unsetRelation('workingHours');
    }

    /**
     * An overnight interval: 20:00 until 02:00 the following morning.
     */
    protected function openOvernight(Branch $branch): void
    {
        $branch->workingHours()->delete();

        for ($day = 0; $day <= 6; $day++) {
            $branch->workingHours()->create([
                'day_of_week' => $day,
                'opens_at' => '20:00',
                'closes_at' => '02:00',
                'sort_order' => 0,
            ]);
        }

        $branch->unsetRelation('workingHours');
    }

    protected function closeOn(Branch $branch, string $date, string $note = 'Eid'): void
    {
        $branch->hourExceptions()->create([
            'date' => $date,
            'is_closed' => true,
            'note' => $note,
        ]);

        $branch->unsetRelation('hourExceptions');
    }

    protected function specialHoursOn(Branch $branch, string $date, string $opens, string $closes): void
    {
        $branch->hourExceptions()->create([
            'date' => $date,
            'is_closed' => false,
            'opens_at' => $opens,
            'closes_at' => $closes,
        ]);

        $branch->unsetRelation('hourExceptions');
    }

    /**
     * An active employee, assigned to the branch and eligible for the service.
     *
     * All three are required for availability, and forgetting any one of them
     * is the most common way a "why is nothing available" bug happens.
     */
    protected function seedBookableEmployee(Service $service, Branch $branch, string $name = 'Ahmed'): Employee
    {
        /** @var Employee $employee */
        $employee = Employee::query()->create([
            'name' => TranslatedText::fromArray(['en' => $name]),
            'status' => EmployeeStatus::Active,
        ]);

        $employee->branches()->attach($branch->id);
        $service->eligibleEmployees()->attach($employee->id);

        return $employee;
    }

    /**
     * A second service, so multi-service bookings have something to sequence.
     */
    protected function seedService(
        string $name,
        int $minutes,
        int $priceMinor,
        ?Employee $employee = null,
    ): Service {
        /** @var Service $service */
        $service = Service::query()->create([
            'name' => TranslatedText::fromArray(['en' => $name]),
            'duration_minutes' => $minutes,
            'price_minor' => $priceMinor,
            'is_active' => true,
            'is_public' => true,
            'is_online_bookable' => true,
            'available_at_all_branches' => true,
            'sort_order' => 0,
        ]);

        if ($employee instanceof Employee) {
            $service->eligibleEmployees()->attach($employee->id);
        }

        return $service;
    }

    /**
     * A UTC instant for a branch-local wall-clock time.
     *
     * Tests say "10:00 at the branch" and mean it; converting by hand in each
     * test is how a timezone test ends up asserting the bug it was written to
     * catch.
     */
    protected function localTime(Branch $branch, string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($date.' '.$time, $branch->timezone)->utc();
    }

    /**
     * A customer with a working login.
     *
     * Refreshed before it is returned: a model created from an array carries
     * only the attributes that were assigned, and
     * `preventAccessingMissingAttributes` (on outside production) turns the
     * first read of a defaulted column into an exception. That guard is doing
     * its job — the fixture is what was incomplete.
     */
    protected function seedCustomerAccount(
        Customer $customer,
        string $password = 'correct-horse-battery-staple',
    ): CustomerAccount {
        /** @var CustomerAccount $account */
        $account = $customer->account()->create([
            'password' => $password,
            'is_active' => true,
        ]);

        return $account->refresh();
    }

    /**
     * Overrides this center's booking settings.
     *
     * @param  array<string, int>  $values
     */
    protected function bookingSettings(array $values): void
    {
        $settings = app(BookingSettings::class);

        $settings->save($values);
        $settings->forget();
    }
}
