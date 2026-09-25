<?php

declare(strict_types=1);

namespace App\Kernel\Money;

use App\Kernel\Tenancy\Contracts\TenantContext;

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
 * catalog of every ISO code would be a list of promises. Which of these are
 * offered is the platform currency catalog's decision (Super Admin).
 */
enum Currency: string
{
    /** Iraqi dinar. Exponent 0 — there are no fils in practice. */
    case IQD = 'IQD';

    /** US dollar. Widely used alongside IQD in Iraq. */
    case USD = 'USD';

    /*
     * The region's other trading currencies a center may be set up in. The
     * Super Admin enables which of these a center can choose (platform
     * currency catalog); a code not listed here cannot hold a center's money.
     */
    case EUR = 'EUR';
    case GBP = 'GBP';
    case TRY = 'TRY';
    case AED = 'AED';
    case SAR = 'SAR';
    /** Jordanian dinar. Exponent 3 — 1,000 fils to the dinar. */
    case JOD = 'JOD';
    /** Kuwaiti dinar. Exponent 3. */
    case KWD = 'KWD';

    /**
     * Digits after the decimal separator.
     */
    public function exponent(): int
    {
        return match ($this) {
            self::IQD => 0,
            self::JOD, self::KWD => 3,
            default => 2,
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

        $arabicScript = in_array($locale, ['ar', 'ckb'], true);

        return match ($this) {
            self::IQD => $arabicScript ? 'د.ع' : 'IQD',
            self::USD => '$',
            self::EUR => '€',
            self::GBP => '£',
            self::TRY => '₺',
            self::AED => $arabicScript ? 'د.إ' : 'AED',
            self::SAR => $arabicScript ? 'ر.س' : 'SAR',
            self::JOD => $arabicScript ? 'د.أ' : 'JOD',
            self::KWD => $arabicScript ? 'د.ك' : 'KWD',
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
        // Inside a center, that center's own operational currency wins. It is
        // read from the already-resolved tenant, so this costs no query.
        if (app()->bound(TenantContext::class)) {
            $center = app(TenantContext::class)->tenant()?->currency;
            if (is_string($center) && self::tryFrom($center) instanceof self) {
                return self::from($center);
            }
        }

        $configured = config('metastyle.money.default_currency');

        return is_string($configured) ? (self::tryFrom($configured) ?? self::IQD) : self::IQD;
    }
}
