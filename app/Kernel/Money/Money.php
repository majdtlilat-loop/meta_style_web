<?php

declare(strict_types=1);

namespace App\Kernel\Money;

use InvalidArgumentException;
use Stringable;

/**
 * An amount of money. Integer minor units, always.
 *
 * There is no float anywhere in this class, and no `float` ever crosses its
 * boundary, because 0.1 + 0.2 is not 0.3 and a price list that drifts by a fils
 * per operation is a support conversation nobody wins
 * (docs/10-API-FOUNDATION.md §9).
 *
 * The currency travels WITH the amount. An `int $price` on its own is
 * ambiguous the moment a second currency exists — and it always does
 * eventually.
 */
final readonly class Money implements Stringable
{
    private function __construct(
        public int $minor,
        public Currency $currency,
    ) {}

    /**
     * The primary constructor: an amount already in minor units.
     *
     * 25000 IQD is twenty-five thousand dinars. 2500 USD is twenty-five
     * dollars. The difference is the currency's exponent, never a guess.
     */
    public static function fromMinor(int $minor, Currency $currency): self
    {
        return new self($minor, $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    /**
     * Builds from a major-unit value as a STRING, for form input.
     *
     * A string, not a float: `"25.10"` parsed as a float and multiplied by 100
     * is 2509.9999999999995, and `(int)` truncates that to 2509. The value
     * arrives from an HTML input as a string already, so there is no reason to
     * ever make it a float.
     *
     * @throws InvalidArgumentException on anything that is not a plain decimal
     */
    public static function fromMajorString(string $value, Currency $currency): self
    {
        $trimmed = trim($value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $trimmed)) {
            throw new InvalidArgumentException("[{$value}] is not a valid amount.");
        }

        $negative = str_starts_with($trimmed, '-');
        $digits = ltrim($trimmed, '-');

        [$whole, $fraction] = str_contains($digits, '.')
            ? explode('.', $digits, 2)
            : [$digits, ''];

        $exponent = $currency->exponent();

        if (mb_strlen($fraction) > $exponent) {
            throw new InvalidArgumentException(sprintf(
                '%s accepts at most %d decimal place(s); [%s] has %d.',
                $currency->value,
                $exponent,
                $value,
                mb_strlen($fraction),
            ));
        }

        $padded = str_pad($fraction, $exponent, '0');
        $minor = (int) ($whole.$padded);

        return new self($negative ? -$minor : $minor, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    /**
     * Multiplies by a whole quantity. Not by a rate — percentage maths belongs
     * with the feature that needs it, where the rounding rule can be stated.
     */
    public function times(int $quantity): self
    {
        return new self($this->minor * $quantity, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }

    /**
     * The major-unit representation, as a string. Never a float.
     */
    public function toMajorString(): string
    {
        $exponent = $this->currency->exponent();
        $sign = $this->minor < 0 ? '-' : '';
        $absolute = (string) abs($this->minor);

        if ($exponent === 0) {
            return $sign.$absolute;
        }

        $padded = str_pad($absolute, $exponent + 1, '0', STR_PAD_LEFT);

        return $sign.mb_substr($padded, 0, -$exponent).'.'.mb_substr($padded, -$exponent);
    }

    /**
     * For display only. Clients must never parse this back into an amount —
     * that is what `minor` and `currency` are for.
     */
    public function formatted(?string $locale = null): string
    {
        $exponent = $this->currency->exponent();
        $sign = $this->minor < 0 ? '-' : '';
        $absolute = abs($this->minor);

        $whole = intdiv($absolute, $this->currency->subunits());
        $grouped = number_format((float) $whole, 0, '.', ',');

        $number = $exponent === 0
            ? $grouped
            : $grouped.'.'.str_pad((string) ($absolute % $this->currency->subunits()), $exponent, '0', STR_PAD_LEFT);

        return $sign.$number.' '.$this->currency->symbol($locale);
    }

    /**
     * The API shape (docs/10-API-FOUNDATION.md §9).
     *
     * @return array{amount: int, currency: string, formatted: string}
     */
    public function toArray(?string $locale = null): array
    {
        return [
            'amount' => $this->minor,
            'currency' => $this->currency->value,
            'formatted' => $this->formatted($locale),
        ];
    }

    public function __toString(): string
    {
        return $this->formatted();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(sprintf(
                'Cannot combine %s with %s. Convert explicitly, with a rate somebody chose.',
                $this->currency->value,
                $other->currency->value,
            ));
        }
    }
}
