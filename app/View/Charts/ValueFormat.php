<?php

declare(strict_types=1);

namespace App\View\Charts;

use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Kernel\Platform\Currencies\PlatformCurrencies;
use InvalidArgumentException;
use Throwable;

/**
 * How a chart prints one value — in a tooltip, a data table, an axis tick or
 * a delta.
 *
 *   number    1,284 · 12.5
 *   compact   1,284 · 12.9K · 4.2M
 *   money     integer MINOR units of `currency` (the center's own currency
 *             when none is given). IQD has no decimals; never assume two.
 *   percent   percentage POINTS: 87.5 means 87.5 %, not 0.875
 *   duration  minutes: 95 → "1 h 35 min"
 *   seconds   seconds: 200 → "3 min 20 s"
 *
 * Digits stay Latin in every locale, as Kernel\Money prints them; currency
 * symbols and unit words follow the locale.
 */
final class ValueFormat
{
    public const KINDS = ['number', 'compact', 'money', 'percent', 'duration', 'seconds'];

    private function __construct(
        public readonly string $kind,
        public readonly ?string $currency,
        public readonly string $locale,
    ) {}

    /**
     * @param  string|null  $kind  one of self::KINDS; null means money when a currency is given, number otherwise
     */
    public static function make(?string $kind = null, ?string $currency = null, ?string $locale = null): self
    {
        $currency = $currency !== null && trim($currency) !== '' ? mb_strtoupper(trim($currency)) : null;
        $kind ??= $currency !== null ? 'money' : 'number';

        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Unknown chart value format [{$kind}].");
        }

        if ($kind === 'money' && $currency === null) {
            // Money without a code is the center's own currency — never a
            // guess at two decimals.
            $currency = Currency::default()->value;
        }

        return new self($kind, $kind === 'money' ? $currency : null, $locale ?? self::appLocale());
    }

    /** The full value: tooltips, data tables, KPI figures. */
    public function full(int|float|null $value): string
    {
        if ($value === null) {
            return '—';
        }

        return match ($this->kind) {
            'money' => $this->money((int) round($value)),
            'percent' => $this->percent((float) $value),
            'duration' => $this->minutes((float) $value),
            'seconds' => $this->seconds((float) $value),
            'compact' => Scale::compact((float) $value),
            default => self::number((float) $value),
        };
    }

    /**
     * A figure for a tight spot (a donut centre, a bar-end label): the full
     * value while it is short, compacted with its unit when it is not —
     * "1.3M IQD" rather than an overflowing "1,250,000 IQD".
     */
    public function short(int|float|null $value): string
    {
        $full = $this->full($value);

        if ($value === null || mb_strlen($full) <= 10 || ! in_array($this->kind, ['money', 'number', 'compact'], true)) {
            return $full;
        }

        if ($this->kind === 'money') {
            $known = Currency::tryFrom((string) $this->currency);
            $symbol = $known instanceof Currency ? $known->symbol($this->locale) : (string) $this->currency;

            return Scale::compact($value / $this->subunits()).' '.$symbol;
        }

        return Scale::compact((float) $value);
    }

    /** An axis tick: short, no currency symbol (the card names the currency). */
    public function tick(int|float $value): string
    {
        return match ($this->kind) {
            'money' => Scale::compact($value / $this->subunits()),
            'percent' => self::trim((float) $value, 1).'%',
            'duration' => $this->tickMinutes((float) $value),
            'seconds' => $this->tickMinutes((float) $value / 60),
            default => Scale::compact((float) $value),
        };
    }

    /**
     * A signed difference: "+3", "−2,500 IQD", "+4.5 pts", "+12 min".
     * A percent metric moves in percentage POINTS, never in "percent of a percent".
     */
    public function difference(int|float $delta): string
    {
        if ((float) $delta === 0.0) {
            return $this->kind === 'percent' ? $this->points(0.0) : $this->full(0);
        }

        $sign = $delta > 0 ? '+' : '−';
        $magnitude = abs($delta);

        return $sign.($this->kind === 'percent' ? $this->points($magnitude) : $this->full($magnitude));
    }

    /** "+12.5%" — the relative change label; one decimal under 100 %, none above. */
    public static function signedPercent(float $percent): string
    {
        if ($percent === 0.0) {
            return '0%';
        }

        $sign = $percent > 0 ? '+' : '−';
        $magnitude = abs($percent);

        return $sign.self::trim($magnitude, $magnitude >= 100 ? 0 : 1).'%';
    }

    /** Minor units per major unit: 1 for IQD, 100 for USD, 1,000 for KWD. */
    public function subunits(): int
    {
        if ($this->currency === null) {
            return 1;
        }

        $known = Currency::tryFrom($this->currency);
        if ($known instanceof Currency) {
            return $known->subunits();
        }

        try {
            return 10 ** app(PlatformCurrencies::class)->decimals($this->currency);
        } catch (Throwable) {
            return 1;
        }
    }

    /** 1,284 · 12.5 · −3 — grouped, at most one decimal, no trailing ".0". */
    public static function number(float $value): string
    {
        $sign = $value < 0 ? '-' : '';

        return $sign.self::trim(abs($value), floor($value) === $value ? 0 : 1);
    }

    private function money(int $minor): string
    {
        $known = Currency::tryFrom((string) $this->currency);
        if ($known instanceof Currency) {
            return Money::fromMinor($minor, $known)->formatted($this->locale);
        }

        try {
            return app(PlatformCurrencies::class)->format($minor, (string) $this->currency, $this->locale);
        } catch (Throwable) {
            return number_format($minor).' '.$this->currency;
        }
    }

    private function percent(float $value): string
    {
        $sign = $value < 0 ? '-' : '';

        return $sign.self::trim(abs($value), 1).'%';
    }

    private function points(float $value): string
    {
        return self::translate('manager_charts.units.points', ['value' => self::trim(abs($value), 1)], ':value pts');
    }

    private function minutes(float $value): string
    {
        $sign = $value < 0 ? '-' : '';
        $total = (int) round(abs($value));
        $hours = intdiv($total, 60);
        $minutes = $total % 60;

        if ($hours === 0) {
            return $sign.self::translate('manager_charts.units.minutes', ['count' => number_format($minutes)], ':count min');
        }

        $text = self::translate('manager_charts.units.hours', ['count' => number_format($hours)], ':count h');
        if ($minutes > 0) {
            $text .= ' '.self::translate('manager_charts.units.minutes', ['count' => $minutes], ':count min');
        }

        return $sign.$text;
    }

    private function seconds(float $value): string
    {
        $sign = $value < 0 ? '-' : '';
        $total = (int) round(abs($value));

        if ($total < 60) {
            return $sign.self::translate('manager_charts.units.seconds', ['count' => $total], ':count s');
        }

        if ($total >= 3600) {
            return $sign.$this->minutes($total / 60);
        }

        $text = self::translate('manager_charts.units.minutes', ['count' => intdiv($total, 60)], ':count min');
        if ($total % 60 > 0) {
            $text .= ' '.self::translate('manager_charts.units.seconds', ['count' => $total % 60], ':count s');
        }

        return $sign.$text;
    }

    private function tickMinutes(float $value): string
    {
        if (abs($value) < 60) {
            return self::translate('manager_charts.units.minutes', ['count' => self::trim($value, 0)], ':count min');
        }

        return self::translate('manager_charts.units.hours', ['count' => self::trim($value / 60, 1)], ':count h');
    }

    private static function trim(float $value, int $decimals): string
    {
        $text = number_format($value, $decimals);

        return $decimals > 0 && str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /**
     * @param  array<string, int|string>  $replace
     */
    private static function translate(string $key, array $replace, string $fallback): string
    {
        // Pure unit tests run without a container: fall back to the English shape.
        if (app()->bound('translator')) {
            $line = __($key, $replace);
            if (is_string($line) && $line !== $key) {
                return $line;
            }
        }

        foreach ($replace as $name => $value) {
            $fallback = str_replace(':'.$name, (string) $value, $fallback);
        }

        return $fallback;
    }

    private static function appLocale(): string
    {
        return app()->bound('translator') ? app()->getLocale() : 'en';
    }
}
