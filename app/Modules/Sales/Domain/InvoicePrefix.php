<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain;

use App\Modules\Sales\Domain\Exceptions\SaleFailed;

/**
 * The short code a branch's invoice numbers start with, and the number format.
 *
 *     BG-2026-000017
 *     └┘ └──┘ └────┘
 *     │   │     └── sequence within the branch-year, zero-padded to six
 *     │   └──────── branch-local calendar year
 *     └──────────── branch prefix
 *
 * ## Why the main branch may leave it empty
 *
 * A single-branch center should issue its first invoice without configuring
 * anything, so the MAIN branch falls back to `INV`. Every other branch needs a
 * prefix of its own before it can issue invoices — otherwise two branches would
 * both publish `INV-2026-000001` every January. `INV` is reserved for the main
 * branch's fallback, which is what makes a center-wide unique invoice number
 * true by construction (docs/18-SALES.md §17, ADR-055).
 *
 * Uppercase ASCII letters and digits, one to four: it is printed, read out over
 * a phone and typed into a search box. `bg` and `BG` must not be two prefixes.
 */
final class InvoicePrefix
{
    public const MAIN_FALLBACK = 'INV';

    private const PATTERN = '/^[A-Z0-9]{1,4}$/';

    /**
     * Validates a prefix somebody is SETTING. Null or blank clears it.
     */
    public static function normalise(?string $value, bool $isMainBranch): ?string
    {
        $candidate = mb_strtoupper(trim((string) $value));

        if ($candidate === '') {
            return null;
        }

        if (preg_match(self::PATTERN, $candidate) !== 1) {
            throw SaleFailed::policy(
                'An invoice prefix must be one to four letters or digits, with no spaces.',
                ['prefix' => $value],
            );
        }

        if ($candidate === self::MAIN_FALLBACK && ! $isMainBranch) {
            throw SaleFailed::policy(
                'INV is reserved for the main branch. Choose a different prefix for this branch.',
                ['prefix' => $candidate],
            );
        }

        return $candidate;
    }

    /**
     * The prefix a branch's next invoice will use — or a refusal saying what to
     * configure.
     */
    public static function effective(?string $configured, bool $isMainBranch): string
    {
        if ($configured !== null && $configured !== '') {
            return $configured;
        }

        if ($isMainBranch) {
            return self::MAIN_FALLBACK;
        }

        throw SaleFailed::policy(
            'This branch needs an invoice prefix before it can issue invoices. A manager can set one in the POS settings.',
        );
    }

    public static function format(string $prefix, int $sequenceYear, int $sequence): string
    {
        return sprintf('%s-%04d-%s', $prefix, $sequenceYear, str_pad((string) $sequence, 6, '0', STR_PAD_LEFT));
    }
}
