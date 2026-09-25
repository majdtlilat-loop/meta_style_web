<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Kernel\Time\DateRange;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;

/**
 * One date-range state for a whole dashboard: every card and chart below the
 * control reads the same slice, so the numbers always agree. Kept in the URL,
 * so a filtered view can be shared or reloaded.
 */
trait HasDateRange
{
    #[Url(as: 'range', history: true)]
    public string $range = 'this_month';

    #[Url(as: 'from', history: true)]
    public ?string $from = null;

    #[Url(as: 'to', history: true)]
    public ?string $to = null;

    /** The custom inputs, applied only on "Apply". */
    public ?string $customFrom = null;

    public ?string $customTo = null;

    public bool $customOpen = false;

    abstract protected function rangeTimezone(): string;

    public function setRange(string $preset): void
    {
        if ($preset === 'custom') {
            $current = $this->dateRange();
            $this->customFrom ??= $current->from->toDateString();
            $this->customTo ??= $current->to->toDateString();
            $this->customOpen = true;

            return;
        }

        if (! in_array($preset, DateRange::PRESETS, true)) {
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

    protected function dateRange(): DateRange
    {
        return DateRange::resolve($this->range, $this->from, $this->to, $this->rangeTimezone());
    }
}
