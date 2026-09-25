<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

use App\Kernel\Money\Currency;
use InvalidArgumentException;

/**
 * Amounts crossing the provider boundary, in both directions, without a float.
 *
 * Providers speak major units: FIB's documented request carries `"500.00"` for
 * five hundred dinars, and its status response `500`. Meta Style speaks integer
 * minor units in the currency's exponent — IQD has none. This converts between
 * them exactly, and REFUSES anything it cannot convert exactly: a response of
 * `500.5` IQD, or a JSON float, is not rounded into something that would settle
 * a payment; it is a mismatch (docs/19-PAYMENTS.md §§81–82).
 */
final class ProviderAmount
{
    /**
     * Minor units from what a provider sent.
     *
     * @throws InvalidArgumentException when the value is not an exact amount
     */
    public static function toMinor(mixed $amount, Currency $currency): int
    {
        $exponent = $currency->exponent();

        if (is_int($amount)) {
            if ($amount < 0 || $amount > intdiv(PHP_INT_MAX, $currency->subunits())) {
                throw new InvalidArgumentException('The provider amount is out of range.');
            }

            return $amount * $currency->subunits();
        }

        if (! is_string($amount) || preg_match('/^(\d{1,15})(?:\.(\d{1,6}))?$/', $amount, $parts) !== 1) {
            // A JSON float arrives here as a PHP float: never trusted.
            throw new InvalidArgumentException('The provider amount is not an exact decimal.');
        }

        $whole = $parts[1];
        $fraction = $parts[2] ?? '';

        // Digits past the currency's exponent are acceptable only as zeros:
        // "500.00" is exactly 500 IQD; "500.50" is not an IQD amount at all.
        $significant = mb_substr($fraction, 0, $exponent);
        $excess = mb_substr($fraction, $exponent);

        if ($excess !== '' && trim($excess, '0') !== '') {
            throw new InvalidArgumentException('The provider amount has more precision than the currency.');
        }

        return (int) ($whole.str_pad($significant, $exponent, '0'));
    }

    /**
     * A major-unit decimal string for a provider request, with at least
     * `$decimals` places — `500.00` for 500 IQD, as FIB documents.
     */
    public static function toDecimalString(int $minor, Currency $currency, int $decimals = 2): string
    {
        if ($minor < 0) {
            throw new InvalidArgumentException('A provider amount is never negative.');
        }

        $exponent = $currency->exponent();
        $digits = str_pad((string) $minor, $exponent + 1, '0', STR_PAD_LEFT);

        $whole = $exponent === 0 ? $digits : mb_substr($digits, 0, -$exponent);
        $fraction = $exponent === 0 ? '' : mb_substr($digits, -$exponent);

        $places = max($decimals, $exponent);

        return $places === 0 ? $whole : $whole.'.'.str_pad($fraction, $places, '0');
    }
}
