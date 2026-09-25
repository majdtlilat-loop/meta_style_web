<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application\Analytics;

/**
 * Finance movements: the authoritative ledger — collections, refunds,
 * expenses and their reversals — and the net movement they make. Invoicing
 * alone is not a movement; no figure here is revenue or profit.
 */
final class FinanceFacts
{
    /** Ledger kinds in the order a center reads them. */
    public const KINDS = ['collection', 'refund', 'expense', 'expense_reversal'];

    /** @return array<string, mixed> */
    public function build(AnalyticsReads $reads): array
    {
        [$now, $then] = [$reads->finance(), $reads->finance(true)];
        $lead = Facts::leadCurrency($now['net'], $then['net'], ...array_values($now['kinds']));
        $kind = static fn (array $data, string $key): int => (int) ($data['kinds'][$key][$lead] ?? 0);
        $methods = [];

        foreach ($now['methods'] as $method => $currencies) {
            if (isset($currencies[$lead])) {
                $methods[] = ['method' => (string) $method, 'current' => (int) $currencies[$lead], 'previous' => (int) ($then['methods'][$method][$lead] ?? 0)];
            }
        }

        usort($methods, static fn (array $a, array $b): int => abs($b['current']) <=> abs($a['current']));
        $kinds = [];

        foreach (self::KINDS as $key) {
            $kinds[$key] = ['current' => $kind($now, $key), 'previous' => $kind($then, $key)];
        }

        $moved = array_sum(array_map(static fn (array $row): int => $row['current'], $kinds));

        return [
            'empty' => $moved === 0 && (int) ($now['net'][$lead] ?? 0) === 0,
            'currency' => $lead,
            'other_currencies' => Facts::others($now['net'], $lead),
            'metrics' => [
                'collections' => Facts::metric($kind($now, 'collection'), $kind($then, 'collection'), 'money'),
                'refunds' => Facts::metric($kind($now, 'refund'), $kind($then, 'refund'), 'money', false),
                'expenses' => Facts::metric($kind($now, 'expense'), $kind($then, 'expense'), 'money', null),
                'expense_reversals' => Facts::metric($kind($now, 'expense_reversal'), $kind($then, 'expense_reversal'), 'money', null),
                'net_movement' => Facts::metric((int) ($now['net'][$lead] ?? 0), (int) ($then['net'][$lead] ?? 0), 'money'),
                'reconciliation_variance' => Facts::metric((int) ($now['variance'][$lead] ?? 0), (int) ($then['variance'][$lead] ?? 0), 'money', null),
            ],
            'series' => [
                'net_movement' => Facts::pair(
                    Facts::series($now['daily'][$lead] ?? [], null, $reads->buckets),
                    Facts::aligned($then['daily'][$lead] ?? [], null, $reads->previousBuckets, count($reads->buckets)),
                    'money',
                ),
            ],
            'parts' => [
                'kinds' => $kinds,
                'methods' => $methods,
            ],
        ];
    }
}
