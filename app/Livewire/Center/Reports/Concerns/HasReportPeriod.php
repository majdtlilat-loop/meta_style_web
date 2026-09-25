<?php

declare(strict_types=1);

namespace App\Livewire\Center\Reports\Concerns;

use App\Kernel\Time\DateRange;
use App\Modules\Reports\Application\ReportPeriod;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;

/**
 * The Standard Reports period: six presets (today, last 7 days, this month,
 * last month, this year, custom) kept in the URL so a view can be shared or
 * reloaded, and resolved — with its like-for-like comparison — by
 * ReportPeriod. The custom inputs apply only on "Apply".
 */
trait HasReportPeriod
{
    #[Url(as: 'range', history: true)]
    public string $range = ReportPeriod::DEFAULT;

    #[Url(as: 'from', history: true)]
    public ?string $from = null;

    #[Url(as: 'to', history: true)]
    public ?string $to = null;

    public ?string $customFrom = null;

    public ?string $customTo = null;

    public bool $customOpen = false;

    abstract protected function rangeTimezone(): string;

    public function setRange(string $preset): void
    {
        if ($preset === 'custom') {
            $current = $this->period()->current;
            $this->customFrom ??= $current->from->toDateString();
            $this->customTo ??= $current->to->toDateString();
            $this->customOpen = true;

            return;
        }

        if (! in_array($preset, ReportPeriod::PRESETS, true)) {
            return;
        }

        $this->range = $preset;
        $this->from = null;
        $this->to = null;
        $this->customOpen = false;
        $this->resetErrorBag(['customFrom', 'customTo']);
    }

    public function applyCustomRange(): void
    {
        $today = CarbonImmutable::now($this->rangeTimezone())->toDateString();

        $this->validate([
            'customFrom' => ['required', 'date_format:Y-m-d', 'before_or_equal:customTo', 'before_or_equal:'.$today],
            'customTo' => ['required', 'date_format:Y-m-d', 'after_or_equal:customFrom', 'before_or_equal:'.$today],
        ], [], [
            'customFrom' => __('ui.fields.from'),
            'customTo' => __('ui.fields.to'),
        ]);

        $from = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->customFrom);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->customTo);

        if ($from instanceof CarbonImmutable && $to instanceof CarbonImmutable && $from->diffInDays($to) + 1 > DateRange::MAX_DAYS) {
            $this->addError('customFrom', __('manager_reports.errors.invalid_range'));

            return;
        }

        $this->range = 'custom';
        $this->from = $this->customFrom;
        $this->to = $this->customTo;
        $this->customOpen = false;
    }

    public function cancelCustomRange(): void
    {
        $this->customOpen = false;
        $this->resetErrorBag(['customFrom', 'customTo']);
    }

    protected function period(): ReportPeriod
    {
        return ReportPeriod::resolve($this->range, $this->from, $this->to, $this->rangeTimezone());
    }
}
