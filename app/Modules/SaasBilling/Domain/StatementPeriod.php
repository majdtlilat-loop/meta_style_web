<?php

declare(strict_types=1);

namespace App\Modules\SaasBilling\Domain;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * The date range of an account statement, from a preset or two dates, in the
 * platform's timezone. A bad or reversed range falls back to this month rather
 * than to "everything": a statement is always a bounded period.
 */
final readonly class StatementPeriod
{
    public const PRESETS = ['this_month', 'last_month', 'this_year', 'custom'];

    /** A custom range longer than this is refused: five years of lines is not a statement. */
    private const MAX_DAYS = 1830;

    public function __construct(public string $preset, public Carbon $from, public Carbon $to) {}

    public static function resolve(?string $preset, ?string $from, ?string $to, string $timezone): self
    {
        $now = Carbon::now($timezone);
        $preset = in_array($preset, self::PRESETS, true) ? $preset : 'this_month';

        if ($preset === 'custom') {
            try {
                $start = Carbon::createFromFormat('!Y-m-d', (string) $from, $timezone);
                $end = Carbon::createFromFormat('!Y-m-d', (string) $to, $timezone);
            } catch (Throwable) {
                $start = $end = null;
            }
            if ($start instanceof Carbon && $end instanceof Carbon && $start->lte($end) && $start->diffInDays($end) <= self::MAX_DAYS) {
                return new self('custom', $start->startOfDay(), $end->endOfDay());
            }
            $preset = 'this_month';
        }

        return match ($preset) {
            'last_month' => new self($preset, $now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()),
            'this_year' => new self($preset, $now->copy()->startOfYear(), $now->copy()->endOfDay()),
            default => new self('this_month', $now->copy()->startOfMonth(), $now->copy()->endOfDay()),
        };
    }

    /** @return array{preset: string, from: string, to: string} */
    public function query(): array
    {
        return ['preset' => $this->preset, 'from' => $this->from->format('Y-m-d'), 'to' => $this->to->format('Y-m-d')];
    }
}
