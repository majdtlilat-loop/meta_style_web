<?php

declare(strict_types=1);

namespace App\Livewire\Center\Branches;

use App\Kernel\Contact\PhoneCountries;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Time\BranchClock;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Branches\Domain\Models\BranchHourException;
use App\Modules\Branches\Domain\Models\BranchWorkingHour;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Display values for one branch card: the week as readable intervals, the next
 * date exceptions, contact details in international form.
 *
 * Computed here so the template only loops. "Upcoming" is judged against the
 * BRANCH's calendar date, never the server's (BranchClock).
 */
final class BranchPresenter
{
    /** Saturday first — the working week in Iraq and the Gulf. */
    public const DAY_ORDER = [6, 0, 1, 2, 3, 4, 5];

    /**
     * @return array<int, string> day of week => translated name
     */
    public static function dayNames(): array
    {
        $names = [];

        foreach (range(0, 6) as $day) {
            $names[$day] = (string) __('manager_staff.days.'.$day);
        }

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    public static function card(Branch $branch, bool $canManage): array
    {
        $days = self::dayNames();
        $week = [];

        foreach (self::DAY_ORDER as $day) {
            $slots = $branch->workingHours
                ->filter(static fn (BranchWorkingHour $hour): bool => (int) $hour->day_of_week === $day)
                ->sortBy('opens_at')
                ->map(static fn (BranchWorkingHour $hour): string => mb_substr((string) $hour->opens_at, 0, 5).'–'.mb_substr((string) $hour->closes_at, 0, 5))
                ->values()
                ->all();

            $week[] = ['day' => $days[$day], 'slots' => $slots];
        }

        $today = BranchClock::localDate(CarbonImmutable::now(), $branch->timezone);
        $upcoming = $branch->hourExceptions
            ->filter(static fn (BranchHourException $exception): bool => $exception->date->toDateString() >= $today)
            ->sortBy('date')
            ->values();

        return [
            'uuid' => $branch->uuid,
            'name' => $branch->name->get(),
            'address' => $branch->address?->get(),
            'main' => $branch->is_main,
            'archived' => $branch->isArchived(),
            'active' => $branch->is_active,
            'public' => $branch->is_public,
            'timezone' => $branch->timezone,
            'contact_phone' => self::phone($branch->phone),
            'contact_whatsapp' => self::phone($branch->whatsapp),
            'open_days' => count(array_filter($week, static fn (array $day): bool => $day['slots'] !== [])),
            'week' => $week,
            'exceptions' => $upcoming->take(3)->map(static fn (BranchHourException $exception): array => [
                'date' => Carbon::parse($exception->date)->translatedFormat('D j M'),
                'closed' => $exception->is_closed,
                'hours' => $exception->is_closed ? null : mb_substr((string) $exception->opens_at, 0, 5).'–'.mb_substr((string) $exception->closes_at, 0, 5),
                'note' => $exception->note,
            ])->all(),
            'more_exceptions' => max(0, $upcoming->count() - 3),
            'can_manage' => $canManage,
        ];
    }

    /**
     * A stored phone split into the picker's two parts.
     *
     * @return array{0: string, 1: string} [country, national number]
     */
    public static function phoneParts(?string $stored): array
    {
        $parsed = PhoneNumber::parse($stored);
        $country = $parsed?->country();

        if ($parsed instanceof PhoneNumber && $country !== null) {
            return [$country, $parsed->national()];
        }

        return [PhoneCountries::DEFAULT, (string) $stored];
    }

    /**
     * A branch's date exceptions, split at the branch's own today: the ones
     * the form edits, and past ones carried through a save unchanged.
     *
     * @return array{0: list<array{date: string, is_closed: bool, opens_at: string, closes_at: string, note: string}>, 1: list<array{date: string, is_closed: bool, opens_at: string|null, closes_at: string|null, note: string|null}>}
     */
    public static function exceptionRows(Branch $branch): array
    {
        $today = BranchClock::localDate(CarbonImmutable::now(), $branch->timezone);
        $upcoming = [];
        $past = [];

        foreach ($branch->hourExceptions as $exception) {
            $date = $exception->date->toDateString();
            $opens = $exception->opens_at !== null ? mb_substr($exception->opens_at, 0, 5) : null;
            $closes = $exception->closes_at !== null ? mb_substr($exception->closes_at, 0, 5) : null;

            if ($date < $today) {
                $past[] = ['date' => $date, 'is_closed' => $exception->is_closed, 'opens_at' => $opens, 'closes_at' => $closes, 'note' => $exception->note];
            } else {
                $upcoming[] = ['date' => $date, 'is_closed' => $exception->is_closed, 'opens_at' => (string) $opens, 'closes_at' => (string) $closes, 'note' => (string) $exception->note];
            }
        }

        return [$upcoming, $past];
    }

    /**
     * The hours editor, day by day: which interval rows belong to each day.
     *
     * @param  array<int, array{day_of_week: int|string, opens_at: string, closes_at: string}>  $hours
     * @return list<array{day: int, label: string, indexes: list<int>}>
     */
    public static function editorDays(array $hours): array
    {
        $names = self::dayNames();
        $days = [];

        foreach (self::DAY_ORDER as $day) {
            $indexes = [];

            foreach ($hours as $index => $interval) {
                if ((int) $interval['day_of_week'] === $day) {
                    $indexes[] = (int) $index;
                }
            }

            $days[] = ['day' => $day, 'label' => $names[$day], 'indexes' => $indexes];
        }

        return $days;
    }

    /**
     * Validation attribute names for the branch form.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'name' => __('manager_staff.fields.name'), 'name.*' => __('manager_staff.fields.name'),
            'timezone' => __('manager_staff.branches.timezone'), 'phone' => __('phone_field.label'),
            'whatsapp' => __('manager_staff.branches.whatsapp'), 'email' => __('manager_staff.fields.email'),
            'mapUrl' => __('manager_staff.branches.map'), 'latitude' => __('manager_staff.branches.latitude'),
            'longitude' => __('manager_staff.branches.longitude'), 'sortOrder' => __('manager_staff.branches.sort_order'),
            'hours.*.opens_at' => __('manager_staff.branches.opens'), 'hours.*.closes_at' => __('manager_staff.branches.closes'),
            'exceptions.*.date' => __('manager_staff.branches.exception_date'), 'exceptions.*.opens_at' => __('manager_staff.branches.opens'),
            'exceptions.*.closes_at' => __('manager_staff.branches.closes'), 'exceptions.*.note' => __('manager_staff.branches.exception_note'),
        ];
    }

    private static function phone(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        return PhoneNumber::parse($stored)?->international() ?? $stored;
    }
}
