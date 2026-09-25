<?php

declare(strict_types=1);

namespace App\Livewire\Center\Catalog;

use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use InvalidArgumentException;

/**
 * A price as a person typed it, into integer minor units.
 *
 * Arabic-Indic and Persian digits, the Arabic decimal and thousands marks,
 * commas and spaces are normalised first; the amount itself is then parsed by
 * {@see Money::fromMajorString()} as a STRING, so "25.10" never passes through
 * a float, and IQD refuses any decimal place (docs/10-API-FOUNDATION.md §9).
 */
final class PriceInput
{
    private const MAX_DIGITS = 13;

    /**
     * @throws InvalidArgumentException with a translated message
     */
    public static function parse(string $typed, Currency $currency): int
    {
        $normalized = strtr(trim($typed), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٫' => '.', '٬' => '', ',' => '', ' ' => '', "\u{00A0}" => '', "\u{202F}" => '',
        ]);

        if (preg_match('/^\d{1,'.self::MAX_DIGITS.'}(\.\d+)?$/', $normalized) !== 1) {
            throw new InvalidArgumentException(__('ui.errors.amount'));
        }

        try {
            return Money::fromMajorString($normalized, $currency)->minor;
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException(__('ui.errors.amount_decimals', [
                'currency' => $currency->value,
                'places' => $currency->exponent(),
            ]));
        }
    }

    /**
     * Minor units back into what the form shows.
     */
    public static function format(int $minor, Currency $currency): string
    {
        return Money::fromMinor($minor, $currency)->toMajorString();
    }
}
