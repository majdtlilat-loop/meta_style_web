<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Identity;

use App\Kernel\Platform\Identity\Models\PlatformUser;
use Illuminate\Support\Str;

/** RFC 6238 TOTP plus single-use recovery codes, without an external package. */
final class PlatformMfa
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    /** @return list<string> */
    public function generateRecoveryCodes(): array
    {
        return array_map(
            static fn (): string => Str::lower(Str::random(5).'-'.Str::random(5)),
            range(1, 8),
        );
    }

    public function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== 6) {
            return false;
        }

        $counter = intdiv($timestamp ?? time(), 30);

        foreach ([-1, 0, 1] as $drift) {
            if (hash_equals($this->code($secret, $counter + $drift), $code)) {
                return true;
            }
        }

        return false;
    }

    public function consumeRecoveryCode(PlatformUser $user, string $presented): bool
    {
        $codes = $user->mfa_recovery_codes ?? [];
        $hash = hash('sha256', Str::lower(trim($presented)));
        $index = array_search($hash, $codes, true);

        if ($index === false) {
            return false;
        }

        unset($codes[$index]);
        $user->forceFill(['mfa_recovery_codes' => array_values($codes)])->save();

        return true;
    }

    public function provisioningUri(PlatformUser $user, string $secret): string
    {
        $label = rawurlencode('Meta Style:'.$user->email);

        return "otpauth://totp/{$label}?secret={$secret}&issuer=Meta%20Style&digits=6&period=30";
    }

    private function code(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        $binaryCounter = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $number = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($number % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    private function base32Decode(string $value): string
    {
        $bits = '';

        foreach (str_split(strtoupper(preg_replace('/[^A-Z2-7]/i', '', $value) ?? '')) as $character) {
            $position = strpos(self::ALPHABET, $character);

            if ($position === false) {
                continue;
            }

            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }

        return $decoded;
    }
}
