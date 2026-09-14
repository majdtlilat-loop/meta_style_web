<?php

declare(strict_types=1);

namespace App\Modules\Employees\Domain\Models;

use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * A member of staff, as a business record.
 *
 * `user` is optional in both directions: a stylist can be listed and assigned
 * to branches with no system access at all, and an owner can have a login
 * without being an employee who performs services.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $user_id
 * @property TranslatedText $name
 * @property EmployeeStatus $status
 */
final class Employee extends Model
{
    use UsesTenantConnection;

    protected $table = 'employees';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => Translatable::class,
            'status' => EmployeeStatus::class,
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $employee): void {
            $employee->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'employee_branches')->withTimestamps();
    }

    public function hasLogin(): bool
    {
        return $this->user_id !== null;
    }

    /**
     * @return list<int>
     */
    public function branchIds(): array
    {
        /** @var list<int> $ids */
        $ids = $this->branches()->pluck('branches.id')->all();

        return $ids;
    }
}
