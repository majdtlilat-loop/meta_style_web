<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Models;

use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Notes\Concerns\HasInternalNotes;
use App\Kernel\Notes\NoteOwner;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Customers\Domain\Enums\CustomerSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A person this center does business with.
 *
 * The CRM record, and only that. It holds no credential — a login is a separate
 * {@see CustomerAccount} row, created only if this customer wants one. That is
 * what makes "guest" free: a guest is a customer with no account, not a
 * different table and not a flag somebody has to keep in step
 * (docs/13-ROADMAP.md Phase 5 §§2, 4).
 *
 * Tenant-wide, never branch-scoped: the same person books at two branches of
 * one center and keeps one history (§20).
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $phone E.164 — the identity
 * @property string|null $phone_display as the person typed it
 * @property string|null $email
 * @property string|null $preferred_locale
 * @property Carbon|null $date_of_birth
 * @property CustomerSource $source
 * @property bool $allow_operational_messages
 * @property bool $marketing_opt_in
 * @property Carbon|null $marketing_opt_in_at
 * @property Carbon|null $archived_at
 */
final class Customer extends Model
{
    use HasInternalNotes;
    use UsesTenantConnection;

    protected $table = 'customers';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => CustomerSource::class,
            'date_of_birth' => 'date',
            'allow_operational_messages' => 'boolean',
            'marketing_opt_in' => 'boolean',
            'marketing_opt_in_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $customer): void {
            $customer->uuid ??= (string) Str::uuid();
        });
    }

    public function noteOwnerType(): NoteOwner
    {
        return NoteOwner::Customer;
    }

    /**
     * The optional login.
     *
     * @return HasOne<CustomerAccount, $this>
     */
    public function account(): HasOne
    {
        return $this->hasOne(CustomerAccount::class);
    }

    /**
     * @return BelongsToMany<CustomerTag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(CustomerTag::class, 'customer_customer_tag')
            ->orderBy('customer_tags.sort_order');
    }

    /**
     * Registered means "has an account", nothing else.
     *
     * There is no `is_guest` column to fall out of step with reality.
     */
    public function isRegistered(): bool
    {
        return $this->relationLoaded('account')
            ? $this->account !== null
            : $this->account()->exists();
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function phoneNumber(): ?PhoneNumber
    {
        return PhoneNumber::parse($this->phone);
    }

    /**
     * Finds a customer by any spelling of a phone number.
     *
     * The lookup that prevents the common duplicate: `0750…`, `+964750…` and
     * `964 750…` all normalise to one string before they reach the query
     * (§3, §19).
     */
    public static function findByPhone(?string $phone): ?self
    {
        $parsed = PhoneNumber::parse($phone);

        if ($parsed === null) {
            return null;
        }

        /** @var self|null $customer */
        $customer = self::query()->where('phone', $parsed->e164)->first();

        return $customer;
    }

    /**
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Customers with a login.
     *
     * `whereHas` rather than a column, so the two can never disagree.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeRegistered(Builder $query, bool $registered = true): Builder
    {
        return $registered
            ? $query->whereHas('account')
            : $query->whereDoesntHave('account');
    }
}
