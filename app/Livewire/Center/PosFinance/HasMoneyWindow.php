<?php

declare(strict_types=1);

namespace App\Livewire\Center\PosFinance;

use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;

/**
 * The date window of a money screen: a preset or two dates, in whole
 * BRANCH-LOCAL days, kept in the URL so a filtered view can be shared.
 *
 * It only chooses calendar dates. The queries turn them into a half-open UTC
 * window in the branch's own timezone and refuse a range that is too long or
 * malformed (docs/20-FINANCE.md §42) — the refusal reaches the page as a
 * message, never as an exception from a typed `?from=`.
 *
 * The using component names its default preset in `defaultRange()`.
 */
trait HasMoneyWindow
{
    #[Url(as: 'range')]
    public string $range = '';

    #[Url(as: 'from')]
    public string $from = '';

    #[Url(as: 'until')]
    public string $until = '';

    private const RANGE_PRESETS = ['today', 'yesterday', 'last_7_days', 'this_month', 'last_month', 'custom'];

    abstract protected function defaultRange(): string;

    /** The timezone whose calendar the window is counted in. */
    abstract protected function windowTimezone(): string;

    public function setRange(string $preset): void
    {
        if (! in_array($preset, self::RANGE_PRESETS, true)) {
            return;
        }

        if ($preset === 'custom') {
            // Start the custom dates from the window already on screen.
            [$this->from, $this->until] = $this->windowDates($this->windowTimezone());
        } else {
            $this->from = '';
            $this->until = '';
        }

        $this->range = $preset;

        $this->windowChanged();
    }

    /** Typing a date is choosing a custom window. */
    public function updatedFrom(): void
    {
        $this->range = 'custom';
        $this->windowChanged();
    }

    public function updatedUntil(): void
    {
        $this->range = 'custom';
        $this->windowChanged();
    }

    /** A new window starts its list at the first page. */
    protected function windowChanged(): void
    {
        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    /**
     * The window's first and last local dates, `Y-m-d`.
     *
     * @return array{0: string, 1: string}
     */
    protected function windowDates(string $timezone): array
    {
        $today = BranchTime::today($timezone);
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $today, 'UTC');
        $day = $day instanceof CarbonImmutable ? $day : CarbonImmutable::now('UTC')->startOfDay();
        $range = in_array($this->range, self::RANGE_PRESETS, true) ? $this->range : $this->defaultRange();

        return match ($range) {
            'today' => [$today, $today],
            'yesterday' => [$day->subDay()->format('Y-m-d'), $day->subDay()->format('Y-m-d')],
            'last_7_days' => [$day->subDays(6)->format('Y-m-d'), $today],
            'last_month' => [$day->subMonthNoOverflow()->startOfMonth()->format('Y-m-d'), $day->subMonthNoOverflow()->endOfMonth()->format('Y-m-d')],
            'custom' => [
                self::isWindowDate($this->from) ? $this->from : $today,
                self::isWindowDate($this->until) ? $this->until : (self::isWindowDate($this->from) ? $this->from : $today),
            ],
            default => [$day->startOfMonth()->format('Y-m-d'), $today],
        };
    }

    /**
     * What the range control shows: the presets, which is on, and the window
     * in words.
     *
     * @return array{presets: list<array{key: string, label: string, active: bool}>, custom: bool, from: string, until: string, summary: string, max: string}
     */
    protected function windowControl(string $timezone): array
    {
        [$from, $until] = $this->windowDates($timezone);
        $active = in_array($this->range, self::RANGE_PRESETS, true) ? $this->range : $this->defaultRange();

        return [
            'presets' => array_map(static fn (string $preset): array => [
                'key' => $preset,
                'label' => (string) __('manager_finance.range.'.$preset),
                'active' => $preset === $active,
            ], self::RANGE_PRESETS),
            'custom' => $active === 'custom',
            'from' => $from,
            'until' => $until,
            'summary' => $from === $until ? BranchTime::day($from) : BranchTime::day($from).' – '.BranchTime::day($until),
            // The branch's today, not the server's: the date picker must allow
            // it from local midnight on.
            'max' => BranchTime::today($timezone),
        ];
    }

    private static function isWindowDate(string $date): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
