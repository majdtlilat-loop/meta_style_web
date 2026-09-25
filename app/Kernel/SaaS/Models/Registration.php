<?php

declare(strict_types=1);

namespace App\Kernel\SaaS\Models;

use App\Kernel\SaaS\Enums\RegistrationStatus;
use App\Kernel\SaaS\RegistrationAccessToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A self-registration request and its provisioning progress.
 *
 * BOOTSTRAP CREDENTIAL LIFECYCLE (docs/DECISIONS.md ADR-031)
 * ---------------------------------------------------------
 * `owner_password_hash` holds a **bcrypt hash**, additionally **encrypted at
 * rest** with `APP_KEY`. Plaintext is hashed in the web request and is never
 * persisted anywhere, at any point.
 *
 * The hash is KEPT while the registration can still become a working center:
 *
 *     preparing            provisioning is running
 *     failed               retryable, until credentials_expire_at
 *
 * It is DESTROYED the moment that stops being true:
 *
 *     ready                provisioning succeeded — no longer needed
 *     cancelled            given up on deliberately
 *     abandoned            retry window elapsed (swept)
 *
 * The queue payload carries only this row's uuid, so no credential material
 * reaches `jobs`, `failed_jobs`, `tenant_operations`, audit entries or logs —
 * a failed job payload can sit in the database indefinitely, which is exactly
 * why it must not contain one.
 *
 * ACCESS CAPABILITY (docs/DECISIONS.md ADR-035)
 * --------------------------------------------
 * The uuid is a public LOCATOR and authorises nothing. `access_token_hash`
 * holds SHA-256 of a 256-bit random token handed to the registering client
 * once; presenting it is what permits reading status or triggering a retry.
 *
 *     preparing / failed   read and retry
 *     ready                read only, until access_expires_at (a short grace so
 *                          the client's next poll can still learn its center
 *                          key); retry stays impossible because it requires
 *                          status `failed`, which `ready` cannot return to
 *     cancelled            hash destroyed with the credential
 *     abandoned            hash destroyed with the credential
 *
 * @property string $uuid
 * @property RegistrationStatus $status
 * @property string|null $owner_password_hash
 * @property string|null $access_token_hash
 * @property Carbon|null $credentials_expire_at
 * @property Carbon|null $access_expires_at
 * @property string|null $tenant_id
 * @property string|null $requested_slug
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $verification_expires_at
 * @property string $source
 * @property array<string, mixed>|null $options
 * @property string|null $created_by_label
 * @property string $center_name
 * @property string $owner_name
 * @property string|null $owner_email
 * @property string|null $owner_phone
 * @property string $locale
 * @property string|null $error
 * @property Carbon|null $created_at
 */
final class Registration extends Model
{
    protected $connection = 'control';

    protected $table = 'registrations';

    protected $guarded = [];

    /**
     * Never serialise the credential, whatever a caller asks for.
     *
     * @var list<string>
     */
    protected $hidden = ['owner_password_hash', 'access_token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RegistrationStatus::class,
            'completed_at' => 'datetime',
            'settled_at' => 'datetime',
            'credentials_expire_at' => 'datetime',
            'access_expires_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'verification_sent_at' => 'datetime',
            'verification_expires_at' => 'datetime',
            // Encrypted at rest: the control plane should not hold a usable
            // credential in readable form for the minutes it exists.
            'owner_password_hash' => 'encrypted',
            'options' => 'array',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $registration): void {
            $registration->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * Registrations whose retry window has closed while still failed.
     *
     * @param  Builder<Registration>  $query
     * @return Builder<Registration>
     */
    public function scopeSweepable(Builder $query, ?Carbon $now = null): Builder
    {
        return $query
            ->where('status', RegistrationStatus::Failed)
            ->whereNotNull('credentials_expire_at')
            ->where('credentials_expire_at', '<=', $now ?? Carbon::now());
    }

    /**
     * Registrations still holding a capability past its read grace.
     *
     * Separate from `sweepable`: these are SUCCESSFUL registrations, not
     * abandoned ones. Nothing about them changes except that the token stops
     * existing (ADR-035).
     *
     * @param  Builder<Registration>  $query
     * @return Builder<Registration>
     */
    public function scopeAccessExpired(Builder $query, ?Carbon $now = null): Builder
    {
        return $query
            ->whereNotNull('access_token_hash')
            ->whereNotNull('access_expires_at')
            ->where('access_expires_at', '<=', $now ?? Carbon::now());
    }

    public function credentialWindowHasClosed(?Carbon $now = null): bool
    {
        return $this->credentials_expire_at !== null
            && $this->credentials_expire_at->isBefore($now ?? Carbon::now());
    }

    public function hasCredential(): bool
    {
        return $this->owner_password_hash !== null;
    }

    /**
     * Can this registration be retried right now?
     *
     * Deliberately stricter than the status alone: a retry with no credential
     * and no owner already created cannot finish, and a window that has closed
     * means the credential is about to be destroyed anyway. Better to refuse
     * clearly than to queue work that will fail.
     */
    public function isRetryable(?Carbon $now = null): bool
    {
        return $this->status->isRetryable() && ! $this->credentialWindowHasClosed($now);
    }

    /**
     * Does this presented token open this registration at all?
     *
     * Read access only. Whether the caller may also RETRY is a separate
     * question answered by {@see isRetryable()} — deliberately separate, so the
     * read grace after `ready` can never be mistaken for permission to queue
     * provisioning work again (ADR-035).
     */
    public function accessTokenMatches(?string $presented, ?Carbon $now = null): bool
    {
        if (! RegistrationAccessToken::matches($presented, $this->access_token_hash)) {
            return false;
        }

        return ! $this->accessWindowHasClosed($now);
    }

    public function accessWindowHasClosed(?Carbon $now = null): bool
    {
        return $this->access_expires_at !== null
            && $this->access_expires_at->isBefore($now ?? Carbon::now());
    }

    /**
     * Issues a fresh capability and returns the plaintext.
     *
     * The only moment the plaintext exists in this process. It is handed to the
     * caller and written nowhere: only its digest reaches the row.
     */
    public function issueAccessToken(): string
    {
        $plaintext = RegistrationAccessToken::generate();

        $this->forceFill([
            'access_token_hash' => RegistrationAccessToken::hash($plaintext),
            'access_expires_at' => null,
        ]);

        return $plaintext;
    }

    public function forgetAccessToken(): void
    {
        if ($this->access_token_hash !== null || $this->access_expires_at !== null) {
            $this->forceFill([
                'access_token_hash' => null,
                'access_expires_at' => null,
            ])->save();
        }
    }

    /**
     * Destroys the bootstrap credential.
     *
     * Called on every terminal outcome. A registration that keeps a password
     * hash after it can no longer use one is pure liability.
     */
    public function forgetCredentials(): void
    {
        if ($this->owner_password_hash !== null) {
            $this->forceFill([
                'owner_password_hash' => null,
                'credentials_expire_at' => null,
            ])->save();
        }
    }

    /**
     * Moves the registration to a terminal state and destroys the credential.
     */
    public function settle(RegistrationStatus $status, ?string $error = null): void
    {
        $this->forceFill([
            'status' => $status,
            // Preserved unless replaced: the reason a registration failed is
            // what support needs when the owner asks why, and abandoning it
            // hours later must not erase that.
            'error' => $error ?? $this->error,
            'settled_at' => Carbon::now(),
        ])->save();

        if ($status->requiresCredentialDestruction()) {
            $this->forgetCredentials();
        }

        $this->settleAccessToken($status);
    }

    /**
     * What a terminal status does to the capability.
     *
     * `ready` keeps it briefly and read-only. Destroying it the instant
     * provisioning succeeds would refuse the client's very next poll — and that
     * poll is how the owner learns their center key.
     *
     * `cancelled` and `abandoned` leave nothing worth reading, so the
     * capability goes with the credential.
     */
    private function settleAccessToken(RegistrationStatus $status): void
    {
        if ($status === RegistrationStatus::Ready) {
            $this->forceFill([
                'access_expires_at' => Carbon::now()->addMinutes($this->statusGraceMinutes()),
            ])->save();

            return;
        }

        if ($status->requiresCredentialDestruction()) {
            $this->forgetAccessToken();
        }
    }

    private function statusGraceMinutes(): int
    {
        $minutes = config('metastyle.registration.status_grace_minutes');

        return is_numeric($minutes) ? (int) $minutes : 60;
    }

    /**
     * Safe for API responses: status and identifiers only.
     *
     * @return array<string, mixed>
     */
    public function toStatusPayload(): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'center_name' => $this->center_name,
            'slug' => $this->requested_slug,
            'tenant' => $this->tenant_id,
            'retryable' => $this->isRetryable(),
            'error' => $this->status === RegistrationStatus::Failed
                ? 'Provisioning failed. The registration can be retried.'
                : null,
        ];
    }
}
