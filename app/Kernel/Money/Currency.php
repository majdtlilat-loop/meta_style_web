<?php

declare(strict_types=1);

namespace App\Kernel\Money;

/**
 * The currencies Meta Style can express an amount in.
 *
 * The exponent is the whole point of this enum. **IQD has no minor unit**:
 * 25,000 IQD is the integer `25000`, not `2500000`. Assuming two decimal places
 * — which every money library, every tutorial and every instinct does — makes
 * every price in the product a hundred times wrong, in the launch market
 * (docs/10-API-FOUNDATION.md §9).
 *
 * Only currencies a center can actually be configured with are listed. A
 * catalog of every ISO code would be a list of promises.
 */
enum Currency: string
{
    /** Iraqi dinar. Exponent 0 — there are no fils in practice. */
    case IQD = 'IQD';

    /** US dollar. Widely used alongside IQD in Iraq. */
    case USD = 'USD';

    /**
     * Digits after the decimal separator.
     */
    public function exponent(): int
    {
        return match ($this) {
            self::IQD => 0,
            self::USD => 2,
        };
    }

    /**
     * How many minor units make one major unit. 1 for IQD, 100 for USD.
     */
    public function subunits(): int
    {
        return 10 ** $this->exponent();
    }

    /**
     * The symbol shown to a customer, per locale where it differs.
     */
    public function symbol(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return match ($this) {
            self::IQD => in_array($locale, ['ar', 'ckb'], true) ? 'د.ع' : 'IQD',
            self::USD => '$',
        };
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    public static function default(): self
    {
        $configured = config('metastyle.money.default_currency');

        return is_string($configured) ? (self::tryFrom($configured) ?? self::IQD) : self::IQD;
    }
}
