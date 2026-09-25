<?php

declare(strict_types=1);

namespace App\Modules\Employees\Domain\Data;

/**
 * An edit to a member of staff's business record: what they are called and
 * where they work.
 *
 * Deliberately not their login, roles or access scope — those are security
 * changes with their own Actions, permissions and audit severity, and letting
 * them ride along on a rename is how access changes go unnoticed.
 */
final readonly class EmployeeChanges
{
    /**
     * @param  array<string, string|null>  $name  locale => value
     * @param  list<int>  $branchIds
     */
    public function __construct(
        public array $name,
        public array $branchIds,
    ) {}
}
