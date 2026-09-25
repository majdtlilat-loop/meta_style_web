<?php

declare(strict_types=1);

namespace App\Livewire\Center\Reports;

use App\Kernel\Identity\Models\User;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Enums\BookingSource;
use App\Modules\Catalog\Application\DashboardServiceOptions;
use App\Modules\Catalog\Application\StandardReportCategoryOptions;
use App\Modules\Employees\Application\DashboardTeamSnapshot;
use App\View\Label;

/**
 * The optional report filters: what may be chosen, and whether a value that
 * arrived in the URL is one of those choices.
 *
 * Every option is a real value — booking sources and statuses come from the
 * enums themselves (so "Online" is `public_web`, the code the engine
 * writes), employees from the viewer's own branches, services and
 * categories from the catalog. A value that is not an option is dropped
 * rather than passed on; the readers resolve uuids in their own
 * branch-scoped queries anyway, so a filter can only narrow.
 */
final class ReportFilters
{
    /** @var list<array{uuid: string, name: string}>|null */
    private ?array $employees = null;

    /** @var list<array{uuid: string, name: string, archived: bool}>|null */
    private ?array $services = null;

    /** @var list<array{uuid: string, name: string, archived: bool}>|null */
    private ?array $categories = null;

    public function __construct(
        private readonly DashboardTeamSnapshot $team,
        private readonly DashboardServiceOptions $catalog,
        private readonly StandardReportCategoryOptions $categoryCatalog,
    ) {}

    /**
     * @return list<array{value: string, label: string}>
     */
    public function sources(): array
    {
        return array_map(static fn (BookingSource $source): array => [
            'value' => $source->value,
            'label' => __('manager_reports.values.source.'.$source->value),
        ], BookingSource::cases());
    }

    public function source(string $value): ?string
    {
        return BookingSource::tryFrom($value)?->value;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function statuses(): array
    {
        return array_map(static fn (AppointmentStatus $status): array => [
            'value' => $status->value,
            'label' => Label::for('appointment_status', $status->value),
        ], AppointmentStatus::cases());
    }

    public function status(string $value): ?string
    {
        return AppointmentStatus::tryFrom($value)?->value;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function employees(User $viewer): array
    {
        return array_map(static fn (array $employee): array => ['value' => $employee['uuid'], 'label' => $employee['name']], $this->employeeOptions($viewer));
    }

    public function employee(User $viewer, string $uuid): ?string
    {
        return $this->pick($uuid, $this->employeeOptions($viewer));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function services(): array
    {
        return $this->options($this->serviceOptions());
    }

    public function service(string $uuid): ?string
    {
        return $this->pick($uuid, $this->serviceOptions());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function categories(): array
    {
        return $this->options($this->categoryOptions());
    }

    public function category(string $uuid): ?string
    {
        return $this->pick($uuid, $this->categoryOptions());
    }

    /**
     * @param  list<array{uuid: string, name: string, archived: bool}>  $options
     * @return list<array{value: string, label: string}>
     */
    private function options(array $options): array
    {
        return array_map(static fn (array $option): array => [
            'value' => $option['uuid'],
            'label' => $option['archived'] ? __('manager_reports.filters.archived', ['name' => $option['name']]) : $option['name'],
        ], $options);
    }

    /**
     * @param  list<array{uuid: string, name: string}>  $options
     */
    private function pick(string $uuid, array $options): ?string
    {
        if ($uuid === '') {
            return null;
        }

        foreach ($options as $option) {
            if ($option['uuid'] === $uuid) {
                return $uuid;
            }
        }

        return null;
    }

    /** @return list<array{uuid: string, name: string}> */
    private function employeeOptions(User $viewer): array
    {
        return $this->employees ??= $this->team->options($viewer);
    }

    /** @return list<array{uuid: string, name: string, archived: bool}> */
    private function serviceOptions(): array
    {
        return $this->services ??= $this->catalog->all();
    }

    /** @return list<array{uuid: string, name: string, archived: bool}> */
    private function categoryOptions(): array
    {
        return $this->categories ??= $this->categoryCatalog->all();
    }
}
