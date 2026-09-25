<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Payments\Domain\Enums\GatewayEnvironment;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A branch's own merchant account at a payment provider.
 *
 * `credentials` is encrypted at rest with the application key and hidden from
 * every serialisation. It is read only through `readableCredentials()` — to
 * know whether the account is usable, and to hand the values to the provider
 * adapter — and never presented, logged or audited. Staff screens see whether
 * an account is configured and a masked, non-secret hint
 * (docs/19-PAYMENTS.md §19).
 *
 * @property int $id
 * @property string $uuid
 * @property int $branch_id
 * @property string $provider
 * @property GatewayEnvironment $environment
 * @property string $display_name
 * @property bool $enabled
 * @property array<string, string>|null $credentials
 * @property string|null $safe_identifier
 * @property Carbon|null $configured_at
 * @property string|null $configured_by_id
 * @property string|null $configured_by_label
 */
final class GatewayAccount extends Model
{
    use UsesTenantConnection;

    protected $table = 'payment_gateway_accounts';

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['credentials'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'environment' => GatewayEnvironment::class,
            'enabled' => 'boolean',
            'credentials' => 'encrypted:array',
            'configured_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $account): void {
            $account->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isConfigured(): bool
    {
        return $this->configured_at !== null && $this->readableCredentials() !== null;
    }

    /**
     * The stored credentials — or null when there are none, or when they can no
     * longer be decrypted because the application key changed without the
     * credentials being re-encrypted.
     *
     * Never throws. A page that only asks "is this account usable?" — the
     * gateway settings, the customer's invoice — must not fail because of a key
     * rotation; the account simply stops operating until a manager enters its
     * credentials again (docs/19-PAYMENTS.md §19, Operations).
     *
     * @return array<string, string>|null
     */
    public function readableCredentials(): ?array
    {
        // The attribute holds ciphertext (the cast encrypts on assignment), so
        // decrypt it the way `encrypted:array` does — with the model's own
        // encrypter, previous keys included — where a failure is catchable.
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

        // Credentials are named text fields; anything else was never written here.
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
     * Saving a changed encrypted attribute makes Eloquent decrypt the ORIGINAL to
     * compare — which throws precisely when a manager is re-entering credentials
     * to recover from a key change. Forgetting the unreadable original first
     * turns the replacement into an ordinary change. Readable credentials are
     * left alone.
     */
    public function forgetUnreadableCredentials(): void
    {
        if ($this->getRawOriginal('credentials') !== null && $this->readableCredentials() === null) {
            $this->setRawAttributes(array_merge($this->getAttributes(), ['credentials' => null]), true);
        }
    }
}
