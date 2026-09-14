<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A one-time link letting a staff member set their own first password.
 *
 * Only the SHA-256 hash is stored, so this table leaking does not yield usable
 * tokens. SHA-256 rather than bcrypt is deliberate and correct here: the token
 * is 40+ random characters, so there is no dictionary to slow down, and the
 * lookup has to be an indexed equality match.
 *
 * @property int $user_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon|null $revoked_at
 */
final class StaffActivationToken extends Model
{
    use UsesTenantConnection;

    protected $table = 'staff_activation_tokens';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /**
     * @param  Builder<StaffActivationToken>  $query
     * @return Builder<StaffActivationToken>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    public function isUsable(): bool
    {
        return $this->used_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
