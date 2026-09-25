<?php

declare(strict_types=1);

namespace App\Kernel\Contact;

use Stringable;

/**
 * A phone number, normalised to E.164 so one person is one customer.
 *
 * The problem this solves is mundane and expensive: `0750 123 4567`,
 * `+964 750 123 4567` and `964-750-123-4567` are the same human being, and a
 * CRM that treats them as three customers loses their history three ways
 * (docs/13-ROADMAP.md Phase 5 §3).
 *
 * DELIBERATELY NOT libphonenumber. That library is mature and correct, and it
 * carries roughly ten megabytes of metadata for 200+ countries to solve a
 * problem Meta Style currently has in one. Its real value is validating and
 * formatting numbers worldwide; what is needed here is "collapse the spellings
 * of one national number to one canonical string". If the product reaches a
 * market where that stops being enough, THIS CLASS is the single thing to
 * replace — nothing else parses a phone number (ADR-039).
 *
 * What it deliberately does NOT do:
 *
 *  - It does not claim a number exists or is reachable. Only a verification
 *    provider can say that, and Phase 5 has none.
 *  - It does not guess a country for a bare national number beyond the center's
 *    configured default. Guessing would silently file a customer under the
 *    wrong country code.
 */
final readonly class PhoneNumber implements Stringable
{
    private function __construct(
        /** Canonical form: `+` followed by digits only. The identity. */
        public string $e164,
        /** What the person actually typed. Shown back to them unchanged. */
        public string $display,
    ) {}

    /**
     * Parses user input, or returns null if it cannot be made canonical.
     *
     * Null rather than an exception because a blank or unusable phone is an
     * ordinary case — a walk-in placeholder has no number at all — and callers
     * decide what that means for them.
     */
    public static function parse(?string $input, ?string $country = null): ?self
    {
        if ($input === null) {
            return null;
        }

        $display = trim($input);

        if ($display === '') {
            return null;
        }

        $country ??= self::defaultCountry();
        $dialing = self::dialingCodeFor($country);

        // Everything that is not a digit or a leading plus is punctuation:
        // spaces, dashes, brackets, dots. `00` is the international prefix in
        // most of the world and means the same thing as `+`.
        $hasPlus = str_starts_with($display, '+');
        $digits = preg_replace('/\D+/', '', $display) ?? '';

        if ($digits === '') {
            return null;
        }

        if (! $hasPlus && str_starts_with($digits, '00')) {
            $hasPlus = true;
            $digits = mb_substr($digits, 2);
        }

        $e164 = $hasPlus
            ? '+'.$digits
            : '+'.$dialing.self::stripTrunkPrefix($digits, $dialing, $country);

        // Shortest plausible international number is 8 digits; E.164 caps at 15.
        $length = mb_strlen($e164) - 1;

        if ($length < 8 || $length > 15) {
            return null;
        }

        return new self($e164, $display);
    }

    /**
     * A number entered as a country plus the national number — the way the
     * phone field asks for it ([Iraq +964] [750 123 4567]).
     *
     * A typed `+…` or `00…` is taken as the full international number and
     * the country is ignored. Otherwise the country's trunk prefix is removed
     * (`0750…` → `750…`), and the national number must fit that country's
     * length where one is known, and E.164 everywhere.
     */
    public static function fromParts(string $country, ?string $number): ?self
    {
        $country = mb_strtoupper(trim($country));
        $display = trim((string) $number);
        if ($display === '' || ! PhoneCountries::exists($country)) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $display) ?? '';
        if (str_starts_with($display, '+') || str_starts_with($digits, '00')) {
            return self::parse($display, $country);
        }
        $code = (string) PhoneCountries::callingCode($country);
        [$min, $max] = PhoneCountries::lengths($country);
        $trunk = PhoneCountries::trunkPrefix($country);
        if ($trunk !== '' && str_starts_with($digits, $trunk) && mb_strlen($digits) - mb_strlen($trunk) >= $min) {
            $digits = mb_substr($digits, mb_strlen($trunk));
        }
        // People also type the calling code without a plus: 9647501234567.
        if (str_starts_with($digits, $code) && mb_strlen($digits) > $max) {
            $digits = mb_substr($digits, mb_strlen($code));
        }
        $length = mb_strlen($digits);
        if ($digits === '' || $length < $min || $length > $max || mb_strlen($code.$digits) > 15 || mb_strlen($code.$digits) < 8) {
            return null;
        }

        return new self('+'.$code.$digits, $display);
    }

    /** The country this number belongs to, by its calling code. */
    public function country(): ?string
    {
        return PhoneCountries::countryOf($this->e164);
    }

    /** The national significant number: the digits after the calling code. */
    public function national(): string
    {
        $code = PhoneCountries::callingCode((string) $this->country()) ?? '';

        return mb_substr($this->e164, 1 + mb_strlen($code));
    }

    /** "+964 7501234567": the calling code set apart, for reading. Never a query value. */
    public function international(): string
    {
        $code = PhoneCountries::callingCode((string) $this->country());

        return $code === null ? $this->e164 : '+'.$code.' '.$this->national();
    }

    /**
     * The last digits, for a masked display. Never the whole number.
     */
    public function last(int $count = 2): string
    {
        return mb_substr($this->e164, -$count);
    }

    public function equals(self $other): bool
    {
        return $this->e164 === $other->e164;
    }

    public function __toString(): string
    {
        return $this->e164;
    }

    /**
     * Removes a national trunk prefix before prepending the dialing code.
     *
     * `0750…` in Iraq is the national way to write `+964 750…`; keeping the
     * zero would produce `+9640750…`, which is a different number and matches
     * nothing. A number that already begins with its own dialing code is left
     * alone — people write `964750…` too.
     */
    private static function stripTrunkPrefix(string $digits, string $dialing, string $country): string
    {
        if (str_starts_with($digits, $dialing)) {
            return mb_substr($digits, mb_strlen($dialing));
        }

        $trunk = self::trunkPrefixFor($country);

        if ($trunk !== '' && str_starts_with($digits, $trunk)) {
            return mb_substr($digits, mb_strlen($trunk));
        }

        return $digits;
    }

    private static function defaultCountry(): string
    {
        $country = config('metastyle.contact.default_country');

        return is_string($country) && $country !== '' ? mb_strtoupper($country) : 'IQ';
    }

    private static function dialingCodeFor(string $country): string
    {
        $code = config('metastyle.contact.countries.'.mb_strtoupper($country).'.dialing_code');

        return is_string($code) ? $code : (PhoneCountries::callingCode($country) ?? '964');
    }

    private static function trunkPrefixFor(string $country): string
    {
        $trunk = config('metastyle.contact.countries.'.mb_strtoupper($country).'.trunk_prefix');

        return is_string($trunk) ? $trunk : PhoneCountries::trunkPrefix($country);
    }
}
