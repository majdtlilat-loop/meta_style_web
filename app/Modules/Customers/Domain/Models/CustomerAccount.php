<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * A customer's login. One per customer, at most, and never a staff account.
 *
 * DELIBERATELY NOT A STAFF `User`. Different table, different guard, different
 * auth provider, and no roles or permissions of any kind — a customer is not a
 * member of staff with fewer boxes ticked, and modelling them as one is how a
 * customer eventually ends up holding a permission somebody forgot to withhold
 * (docs/13-ROADMAP.md Phase 5 §5).
 *
 * The separation is enforced by the framework rather than by care: Sanctum
 * compares a token's owner against its guard's provider model, so a customer
 * token presented to `auth:sanctum` (provider `staff`) is refused, and a staff
 * token presented to `auth:customer-api` likewise.
 *
 * Tenant binding is inherited and structural. The row lives in this center's
 * database, so a token issued here finds nothing under another center — the
 * same property staff tokens have (ADR-027).
 *
 * @property int $id
 * @property string $uuid
 * @property int $customer_id
 * @property string|null $password
 * @property bool $is_active
 * @property Carbon|null $phone_verified_at
 * @property Carbon|null $last_login_at
 * @property Carbon|null $password_changed_at
 */
final class CustomerAccount extends Authenticatable
{
    use HasApiTokens;
    use UsesTenantConnection;

    protected $table = 'customer_accounts';

    protected $guarded = [];

    /**
     * @var list<string>
     */
    protected $hidden = ['password', 'remember_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $account): void {
            $account->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Can this account be signed in to at all?
     *
     * A row with no password exists but cannot authenticate — the same shape
     * staff accounts use before activation.
     */
    public function canAuthenticate(): bool
    {
        return $this->is_active && $this->password !== null;
    }

    /**
     * Has this customer proved they own their phone number?
     *
     * ALWAYS FALSE IN PHASE 5, and honestly so. There is no SMS or WhatsApp
     * provider yet, so nothing sets `phone_verified_at` — an architecture test
     * asserts that no production code path writes it. This method exists so
     * that when verification arrives, the actions that should require it have
     * something to ask, rather than the column being retrofitted along with
     * every call site (ADR-040).
     */
    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null;
    }
}
