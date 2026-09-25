<?php

declare(strict_types=1);

namespace App\Kernel\Contact;

use Locale;

/**
 * Countries a phone number can belong to: ISO 3166-1 alpha-2 code, ITU
 * calling code, national trunk prefix and — where it is fixed and well known —
 * the length of the national significant number.
 *
 * Names are not stored here: they come from ICU (`intl`) in the reader's
 * language, including Kurdish Sorani. Flags are local files in
 * public/icons/flags (flag-icons, MIT; docs/DECISIONS.md ADR-081).
 *
 * Length rules are deliberately few. A country without one only has to fit
 * E.164 (at most 15 digits in all); guessing a length would refuse real
 * numbers.
 */
final class PhoneCountries
{
    public const DEFAULT = 'IQ';

    /** @var array<string, string> ISO => calling code */
    private const CODES = [
        'AD' => '376', 'AE' => '971', 'AF' => '93', 'AG' => '1', 'AI' => '1', 'AL' => '355', 'AM' => '374', 'AO' => '244', 'AR' => '54', 'AS' => '1',
        'AT' => '43', 'AU' => '61', 'AW' => '297', 'AX' => '358', 'AZ' => '994', 'BA' => '387', 'BB' => '1', 'BD' => '880', 'BE' => '32', 'BF' => '226',
        'BG' => '359', 'BH' => '973', 'BI' => '257', 'BJ' => '229', 'BL' => '590', 'BM' => '1', 'BN' => '673', 'BO' => '591', 'BQ' => '599', 'BR' => '55',
        'BS' => '1', 'BT' => '975', 'BW' => '267', 'BY' => '375', 'BZ' => '501', 'CA' => '1', 'CC' => '61', 'CD' => '243', 'CF' => '236', 'CG' => '242',
        'CH' => '41', 'CI' => '225', 'CK' => '682', 'CL' => '56', 'CM' => '237', 'CN' => '86', 'CO' => '57', 'CR' => '506', 'CU' => '53', 'CV' => '238',
        'CW' => '599', 'CX' => '61', 'CY' => '357', 'CZ' => '420', 'DE' => '49', 'DJ' => '253', 'DK' => '45', 'DM' => '1', 'DO' => '1', 'DZ' => '213',
        'EC' => '593', 'EE' => '372', 'EG' => '20', 'EH' => '212', 'ER' => '291', 'ES' => '34', 'ET' => '251', 'FI' => '358', 'FJ' => '679', 'FK' => '500',
        'FM' => '691', 'FO' => '298', 'FR' => '33', 'GA' => '241', 'GB' => '44', 'GD' => '1', 'GE' => '995', 'GF' => '594', 'GG' => '44', 'GH' => '233',
        'GI' => '350', 'GL' => '299', 'GM' => '220', 'GN' => '224', 'GP' => '590', 'GQ' => '240', 'GR' => '30', 'GT' => '502', 'GU' => '1', 'GW' => '245',
        'GY' => '592', 'HK' => '852', 'HN' => '504', 'HR' => '385', 'HT' => '509', 'HU' => '36', 'ID' => '62', 'IE' => '353', 'IL' => '972', 'IM' => '44',
        'IN' => '91', 'IO' => '246', 'IQ' => '964', 'IR' => '98', 'IS' => '354', 'IT' => '39', 'JE' => '44', 'JM' => '1', 'JO' => '962', 'JP' => '81',
        'KE' => '254', 'KG' => '996', 'KH' => '855', 'KI' => '686', 'KM' => '269', 'KN' => '1', 'KP' => '850', 'KR' => '82', 'KW' => '965', 'KY' => '1',
        'KZ' => '7', 'LA' => '856', 'LB' => '961', 'LC' => '1', 'LI' => '423', 'LK' => '94', 'LR' => '231', 'LS' => '266', 'LT' => '370', 'LU' => '352',
        'LV' => '371', 'LY' => '218', 'MA' => '212', 'MC' => '377', 'MD' => '373', 'ME' => '382', 'MF' => '590', 'MG' => '261', 'MH' => '692', 'MK' => '389',
        'ML' => '223', 'MM' => '95', 'MN' => '976', 'MO' => '853', 'MP' => '1', 'MQ' => '596', 'MR' => '222', 'MS' => '1', 'MT' => '356', 'MU' => '230',
        'MV' => '960', 'MW' => '265', 'MX' => '52', 'MY' => '60', 'MZ' => '258', 'NA' => '264', 'NC' => '687', 'NE' => '227', 'NF' => '672', 'NG' => '234',
        'NI' => '505', 'NL' => '31', 'NO' => '47', 'NP' => '977', 'NR' => '674', 'NU' => '683', 'NZ' => '64', 'OM' => '968', 'PA' => '507', 'PE' => '51',
        'PF' => '689', 'PG' => '675', 'PH' => '63', 'PK' => '92', 'PL' => '48', 'PM' => '508', 'PR' => '1', 'PS' => '970', 'PT' => '351', 'PW' => '680',
        'PY' => '595', 'QA' => '974', 'RE' => '262', 'RO' => '40', 'RS' => '381', 'RU' => '7', 'RW' => '250', 'SA' => '966', 'SB' => '677', 'SC' => '248',
        'SD' => '249', 'SE' => '46', 'SG' => '65', 'SH' => '290', 'SI' => '386', 'SJ' => '47', 'SK' => '421', 'SL' => '232', 'SM' => '378', 'SN' => '221',
        'SO' => '252', 'SR' => '597', 'SS' => '211', 'ST' => '239', 'SV' => '503', 'SX' => '1', 'SY' => '963', 'SZ' => '268', 'TC' => '1', 'TD' => '235',
        'TG' => '228', 'TH' => '66', 'TJ' => '992', 'TK' => '690', 'TL' => '670', 'TM' => '993', 'TN' => '216', 'TO' => '676', 'TR' => '90', 'TT' => '1',
        'TV' => '688', 'TW' => '886', 'TZ' => '255', 'UA' => '380', 'UG' => '256', 'US' => '1', 'UY' => '598', 'UZ' => '998', 'VA' => '39', 'VC' => '1',
        'VE' => '58', 'VG' => '1', 'VI' => '1', 'VN' => '84', 'VU' => '678', 'WF' => '681', 'WS' => '685', 'XK' => '383', 'YE' => '967', 'YT' => '262',
        'ZA' => '27', 'ZM' => '260', 'ZW' => '263',
    ];

    /**
     * Trunk prefixes that are not the common `0`. An empty string means the
     * national number keeps any leading zero (Italy's `06…` is part of it).
     *
     * @var array<string, string>
     */
    private const TRUNKS = [
        'AD' => '', 'BH' => '', 'CR' => '', 'CY' => '', 'DK' => '', 'EE' => '', 'ES' => '', 'FO' => '', 'GL' => '', 'GR' => '', 'GT' => '', 'HK' => '',
        'HN' => '', 'IS' => '', 'IT' => '', 'KW' => '', 'LI' => '', 'LU' => '', 'LV' => '', 'MC' => '', 'MO' => '', 'MT' => '', 'MX' => '', 'NI' => '',
        'NO' => '', 'OM' => '', 'PA' => '', 'PT' => '', 'QA' => '', 'SG' => '', 'SJ' => '', 'SM' => '', 'SV' => '', 'VA' => '',
        'BY' => '8', 'KZ' => '8', 'LT' => '8', 'RU' => '8', 'TM' => '8', 'HU' => '06',
        // North American Numbering Plan: people also write a leading 1.
        'AG' => '1', 'AI' => '1', 'AS' => '1', 'BB' => '1', 'BM' => '1', 'BS' => '1', 'CA' => '1', 'DM' => '1', 'DO' => '1', 'GD' => '1', 'GU' => '1',
        'JM' => '1', 'KN' => '1', 'KY' => '1', 'LC' => '1', 'MP' => '1', 'MS' => '1', 'PR' => '1', 'SX' => '1', 'TC' => '1', 'TT' => '1', 'US' => '1',
        'VC' => '1', 'VG' => '1', 'VI' => '1',
    ];

    /**
     * National significant number lengths, only where they are fixed and well
     * established. Everything else is bounded by E.164 alone.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private const LENGTHS = [
        'IQ' => [8, 10], 'AE' => [8, 9], 'SA' => [8, 9], 'KW' => [8, 8], 'QA' => [8, 8], 'BH' => [8, 8], 'OM' => [8, 8], 'JO' => [8, 9],
        'TR' => [10, 10], 'IR' => [10, 10], 'SY' => [8, 9], 'LB' => [7, 8], 'EG' => [8, 10], 'GB' => [9, 10], 'FR' => [9, 9], 'DE' => [6, 13],
        'US' => [10, 10], 'CA' => [10, 10],
    ];

    /**
     * Where several countries share a calling code, the one a bare number
     * with that code is shown as.
     *
     * @var array<int, string>
     */
    private const PRIMARY = ['1' => 'US', '7' => 'RU', '44' => 'GB', '47' => 'NO', '61' => 'AU', '212' => 'MA', '262' => 'RE', '358' => 'FI', '39' => 'IT', '590' => 'GP', '599' => 'CW'];

    public static function exists(string $iso): bool
    {
        return isset(self::CODES[mb_strtoupper($iso)]);
    }

    public static function callingCode(string $iso): ?string
    {
        return self::CODES[mb_strtoupper($iso)] ?? null;
    }

    public static function trunkPrefix(string $iso): string
    {
        return self::TRUNKS[mb_strtoupper($iso)] ?? '0';
    }

    /** @return array{0: int, 1: int} */
    public static function lengths(string $iso): array
    {
        return self::LENGTHS[mb_strtoupper($iso)] ?? [4, 14];
    }

    /**
     * The country an E.164 number belongs to: longest calling code first, the
     * primary country for a shared code.
     */
    public static function countryOf(string $e164): ?string
    {
        $digits = ltrim($e164, '+');
        for ($length = 3; $length >= 1; $length--) {
            $code = mb_substr($digits, 0, $length);
            if (isset(self::PRIMARY[$code])) {
                return self::PRIMARY[$code];
            }
            $match = array_search($code, self::CODES, true);
            if ($match !== false) {
                return $match;
            }
        }

        return null;
    }

    /**
     * Every country, named in `$locale`, sorted by that name — for a picker.
     *
     * @return list<array{iso: string, name: string, code: string, flag: string}>
     */
    public static function options(string $locale): array
    {
        $options = [];
        foreach (self::CODES as $iso => $code) {
            $options[] = ['iso' => $iso, 'name' => self::name($iso, $locale), 'code' => $code, 'flag' => self::flag($iso)];
        }
        $collator = class_exists(\Collator::class) ? new \Collator($locale) : null;
        usort($options, static fn (array $a, array $b): int => $collator instanceof \Collator ? (int) $collator->compare($a['name'], $b['name']) : strcmp($a['name'], $b['name']));

        return $options;
    }

    public static function name(string $iso, string $locale): string
    {
        $iso = mb_strtoupper($iso);
        $name = class_exists(Locale::class) ? Locale::getDisplayRegion('-'.$iso, $locale) : '';

        return is_string($name) && $name !== '' && $name !== $iso ? $name : $iso;
    }

    public static function flag(string $iso): string
    {
        return asset('icons/flags/'.mb_strtolower($iso).'.svg');
    }
}
