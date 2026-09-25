<?php

declare(strict_types=1);

namespace App\View\Charts;

use App\View\StatusTone;

/**
 * Which colour ROLE a mark wears. Components emit a `data-series` value and
 * resources/css/platform/charts.css maps it to a token — never a hex here.
 *
 *   '1' … '8'   categorical slots, assigned in order, never cycled
 *   'other'     the folded tail
 *   'previous'  the previous period (muted, dashed on lines)
 *   'good' · 'info' · 'neutral' · 'warning' · 'critical'   status (reserved)
 *   '<tone>-2'  a second status of the same tone in one chart (lighter step)
 */
final class Palette
{
    public const SLOTS = 8;

    /** Circular order the status steps were validated in (docs/30). */
    public const STATUS_ORDER = ['good', 'info', 'neutral', 'warning', 'critical'];

    /**
     * Statuses whose generic tone would collide inside one booking or payment
     * chart (confirmed and completed are both "success" elsewhere).
     */
    private const STATUS = [
        // Appointments
        'completed' => 'good',
        'confirmed' => 'info',
        'booked' => 'neutral',
        'no_show' => 'warning',
        'cancelled' => 'critical',
        'canceled' => 'critical',
        // Payments and invoices
        'succeeded' => 'good',
        'paid' => 'good',
        'settled' => 'good',
        'issued' => 'info',
        'pending' => 'warning',
        'partially_paid' => 'warning',
        'failed' => 'critical',
        'void' => 'critical',
        'voided' => 'critical',
        'refunded' => 'neutral',
    ];

    /** The categorical slot for a zero-based position; past the last slot is Other. */
    public static function slot(int $index): string
    {
        return $index >= 0 && $index < self::SLOTS ? (string) ($index + 1) : 'other';
    }

    /** good | info | neutral | warning | critical for a status code. */
    public static function statusTone(string $status): string
    {
        $status = strtolower(trim($status));

        if (in_array($status, self::STATUS_ORDER, true)) {
            return $status;
        }

        if (isset(self::STATUS[$status])) {
            return self::STATUS[$status];
        }

        return match (StatusTone::for($status)) {
            'success' => 'good',
            'warning' => 'warning',
            'danger' => 'critical',
            'info' => 'info',
            default => 'neutral',
        };
    }

    /**
     * Series ids for one status chart: each item's tone, and `<tone>-2` for a
     * second item that lands on a tone already used, so no two marks in one
     * chart share a colour. A third of the same tone falls back to neutral.
     *
     * @param  list<string>  $tones
     * @return list<string>
     */
    public static function statusSeries(array $tones): array
    {
        $seen = [];
        $ids = [];

        foreach ($tones as $tone) {
            $tone = in_array($tone, self::STATUS_ORDER, true) ? $tone : 'neutral';
            $seen[$tone] = ($seen[$tone] ?? 0) + 1;
            $ids[] = match ($seen[$tone]) {
                1 => $tone,
                2 => $tone.'-2',
                default => 'neutral-2',
            };
        }

        return $ids;
    }

    /** Position of a tone in the validated order, for sorting status marks. */
    public static function statusRank(string $tone): int
    {
        $rank = array_search($tone, self::STATUS_ORDER, true);

        return $rank === false ? count(self::STATUS_ORDER) : $rank;
    }

    /**
     * The series id a caller asked for (`color` key), or the default.
     * Accepts 1–8, 'other', 'previous' and the status tones.
     */
    public static function resolve(mixed $requested, string $default): string
    {
        if (is_int($requested) && $requested >= 1 && $requested <= self::SLOTS) {
            return (string) $requested;
        }

        if (is_string($requested)) {
            $requested = strtolower(trim($requested));
            if (ctype_digit($requested) && (int) $requested >= 1 && (int) $requested <= self::SLOTS) {
                return (string) (int) $requested;
            }
            if (in_array($requested, ['other', 'previous'], true) || in_array($requested, self::STATUS_ORDER, true)) {
                return $requested;
            }
        }

        return $default;
    }
}
