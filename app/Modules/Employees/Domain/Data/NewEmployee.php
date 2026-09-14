<?php

declare(strict_types=1);

namespace App\Modules\Employees\Domain\Data;

use App\Modules\Employees\Domain\Enums\EmployeeStatus;

/**
 * Validated input for adding a member of staff.
 *
 * A DTO rather than a request array so the action's contract is explicit and
 * the same call works from HTTP, a console command, a test or (later) an
 * import — none of which should have to know a form field's name.
 */
final readonly class NewEmployee
{
    /**
     * @param  array<string, string>  $name  locale => value
     * @param  list<int>  $branchIds
     * @param  list<int>  $roleIds
     */
    public function __construct(
        public array $name,
        public array $branchIds = [],
        public array $roleIds = [],
        public EmployeeStatus $status = EmployeeStatus::Active,
        public ?string $email = null,
        public ?string $phone = null,
    ) {}

    /**
     * A login is wanted only when there is something to identify it by.
     *
     * An employee with neither is perfectly valid — they are simply staff the
     * center wants listed, not staff who sign in.
     */
    public function wantsLogin(): bool
    {
        return $this->email !== null || $this->phone !== null;
    }

    public function displayName(): string
    {
        foreach ($this->name as $value) {
            if (trim($value) !== '') {
                return $value;
            }
        }

        return 'Staff member';
    }
}
