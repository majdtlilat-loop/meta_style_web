<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Credentials;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/**
 * A platform-level provider secret (the AI key), encrypted at rest.
 *
 * Write-only by design: nothing presents `credentials`; screens show only
 * whether it is configured and a four-character hint. Read the value through
 * {@see readable()}, which fails closed (null) if the application key changed.
 *
 * @property int $id
 * @property string $provider
 * @property string|null $hint
 * @property string|null $configured_by_label
 * @property Carbon|null $configured_at
 * @property Carbon|null $last_tested_at
 * @property bool|null $last_test_ok
 * @property string|null $last_test_message
 */
final class PlatformProviderCredential extends Model
{
    protected $connection = 'control';

    protected $table = 'platform_provider_credentials';

    protected $guarded = [];

    protected $hidden = ['credentials'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'configured_at' => 'datetime',
            'last_tested_at' => 'datetime',
            'last_test_ok' => 'boolean',
        ];
    }

    /** @return array<string, string>|null */
    public function readable(): ?array
    {
        $ciphertext = $this->getAttributes()['credentials'] ?? null;
        if (! is_string($ciphertext) || $ciphertext === '') {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($ciphertext), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        return is_array($decoded) ? array_filter($decoded, 'is_string') : null;
    }

    /** @param array<string, string> $credentials */
    public function store(#[\SensitiveParameter] array $credentials): void
    {
        $this->setRawAttributes(array_merge($this->getAttributes(), [
            'credentials' => Crypt::encryptString(json_encode($credentials, JSON_THROW_ON_ERROR)),
        ]));
    }
}
