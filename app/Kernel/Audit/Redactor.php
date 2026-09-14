<?php

declare(strict_types=1);

namespace App\Kernel\Audit;

/**
 * Strips secrets from audit payloads.
 *
 * Central, not per call site: the one thing guaranteed about a redaction rule
 * applied by hand at each call site is that somebody will forget it. Meta Style
 * must never record passwords, tokens, merchant secrets, card data, CVV, PIN or
 * OTP anywhere — including here (docs/08-AUDIT-SECURITY.md §4, §9).
 *
 * Keys are matched on whole segments, not substrings. Naive substring matching
 * is badly behaved in both directions: "company_name" contains "pan",
 * "shipping" contains "pin", and "merchant_id" would be redacted alongside
 * "merchant_secret" — losing the very context that makes an audit entry
 * useful, while still missing camelCase variants.
 */
final class Redactor
{
    public const PLACEHOLDER = '[redacted]';

    /**
     * Matched against whole key segments: `db_password` and `passwordHash`
     * both hit "password"; `company_name` does not hit "pan".
     *
     * @var list<string>
     */
    private const SENSITIVE_SEGMENTS = [
        'password', 'passwd', 'secret', 'secrets',
        'token', 'tokens', 'credential', 'credentials',
        'authorization', 'signature',
        'cvv', 'cvc', 'pin', 'otp', 'pan',
    ];

    /**
     * Compound names where the sensitive part is only meaningful together:
     * "merchant_key" is a secret, "merchant_id" is not.
     *
     * @var list<string>
     */
    private const SENSITIVE_PATTERNS = [
        '/(?:^|_)(?:api|private|secret|merchant|encryption|signing|access|refresh|app)_?keys?$/',
        '/(?:^|_)card(?:_?(?:number|no|num))?$/',
        '/(?:^|_)keys?$/',
    ];

    /**
     * Audit payloads are not always string-keyed maps: `['permissions' => [...]]`
     * recurses into a LIST, whose keys are integers. An integer key carries no
     * name and so can never be sensitive — it is the values beneath it that
     * matter.
     *
     * @param  array<array-key, mixed>|null  $payload
     * @return array<array-key, mixed>|null
     */
    public function redact(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $result = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $result[$key] = self::PLACEHOLDER;

                continue;
            }

            $result[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $result;
    }

    public function isSensitive(string $key): bool
    {
        $normalized = self::normalize($key);

        foreach (self::segments($normalized) as $segment) {
            if (in_array($segment, self::SENSITIVE_SEGMENTS, true)) {
                return true;
            }
        }

        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * `apiKey` and `API_KEY` both become `api_key`.
     */
    private static function normalize(string $key): string
    {
        return mb_strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key));
    }

    /**
     * @return list<string>
     */
    private static function segments(string $normalized): array
    {
        return array_values(array_filter(preg_split('/[^a-z0-9]+/', $normalized) ?: []));
    }
}
