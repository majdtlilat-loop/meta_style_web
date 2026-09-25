<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A center's own WhatsApp Business account at the provider.
 *
 * ## The center owns the number
 *
 * Messages are sent from the CENTER's WhatsApp Business number using the
 * CENTER's access token. Meta Style never becomes the sender of record and
 * never pools centers behind one number — which would put one center's
 * customers into another's inbox the moment a phone number was reused
 * (docs/25-WHATSAPP.md §4).
 *
 * ## Credentials
 *
 * `encrypted:array`, hidden from every serialisation, read through exactly one
 * method, and never returned by an API, a Livewire property, a log line or an
 * audit row. Disabling an account KEEPS them: a status callback for a message
 * already in flight still has to be verified.
 *
 * Deliberately identical in shape to `Payments\Domain\Models\GatewayAccount`,
 * including {@see forgetUnreadableCredentials()} — the failure it works around
 * (Eloquent decrypting the ORIGINAL value to diff it, and throwing, precisely
 * when a manager is re-entering credentials to recover from a key change) is
 * the same failure here.
 *
 * ## No URL column
 *
 * Where the provider lives is platform configuration. A tenant supplies
 * credentials, never an outbound destination — no server-side request forgery
 * by configuration (§4).
 *
 * @property int $id
 * @property string $uuid
 * @property string $provider
 * @property string $display_name
 * @property string|null $phone_number_id
 * @property string|null $business_account_id
 * @property string|null $display_phone_number
 * @property bool $enabled
 * @property array<string, string>|null $credentials
 * @property CarbonImmutable|null $configured_at
 * @property string|null $configured_by_id
 * @property string|null $configured_by_label
 * @property CarbonImmutable|null $last_inbound_at
 * @property CarbonImmutable|null $last_error_at
 * @property string|null $last_error_code
 */
final class WhatsAppAccount extends Model
{
    use UsesTenantConnection;

    protected $table = 'whatsapp_accounts';

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['credentials'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'credentials' => 'encrypted:array',
            'configured_at' => 'immutable_datetime',
            'last_inbound_at' => 'immutable_datetime',
            'last_error_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $account): void {
            $account->uuid ??= (string) Str::uuid();
        });
    }

    public function isConfigured(): bool
    {
        return $this->configured_at !== null
            && $this->phone_number_id !== null
            && $this->readableCredentials() !== null;
    }

    public function isUsable(): bool
    {
        return $this->enabled && $this->isConfigured();
    }

    /**
     * The stored credentials — or null when there are none, or when they can no
     * longer be decrypted because the application key changed.
     *
     * NEVER THROWS. A settings page asking only "is this account usable?" must
     * not fail with a 500 because of a key rotation; the account simply stops
     * operating until a manager enters its credentials again.
     *
     * @return array<string, string>|null
     */
    public function readableCredentials(): ?array
    {
        // The attribute holds ciphertext (the cast encrypts on assignment), so
        // decrypt it the way `encrypted:array` does, where failure is catchable.
        $ciphertext = $this->getAttributes()['credentials'] ?? null;

        if (! is_string($ciphertext) || $ciphertext === '') {
            return null;
        }

        try {
            $values = json_decode(self::currentEncrypter()->decryptString($ciphertext), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($values)) {
            return null;
        }

        $credentials = [];

        foreach ($values as $field => $value) {
            if (is_string($field) && is_string($value)) {
                $credentials[$field] = $value;
            }
        }

        return $credentials === [] ? null : $credentials;
    }

    /**
     * Lets newly entered credentials replace ones that no longer decrypt.
     *
     * Saving a changed encrypted attribute makes Eloquent decrypt the ORIGINAL
     * to compare — which throws exactly when a manager is recovering from a key
     * change. Forgetting the unreadable original first turns the replacement
     * into an ordinary change. Readable credentials are left alone.
     */
    public function forgetUnreadableCredentials(): void
    {
        if ($this->getRawOriginal('credentials') !== null && $this->readableCredentials() === null) {
            $this->setRawAttributes(array_merge($this->getAttributes(), ['credentials' => null]), true);
        }
    }
}
