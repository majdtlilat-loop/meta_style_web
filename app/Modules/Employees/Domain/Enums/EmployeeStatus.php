<?php

declare(strict_types=1);

namespace App\Modules\Employees\Domain\Enums;

/**
 * Employment state.
 *
 * Separate from the linked User's `is_active`: a person can keep working after
 * their system access is revoked, and can be suspended from work while their
 * account still exists.
 */
enum EmployeeStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
