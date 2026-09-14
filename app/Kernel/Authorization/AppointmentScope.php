<?php

declare(strict_types=1);

namespace App\Kernel\Authorization;

/**
 * WHICH appointments a user may see, once branch scope has already said where.
 *
 * Two questions, both required, in this order:
 *
 *   {@see BranchScope}      — which branches may this user work in?
 *   AppointmentScope        — all of that branch's book, or only their own?
 *
 * A receptionist sees the whole branch. A stylist sees the rows they are the
 * assigned employee on. That is the scope Phase 6 deferred, and it is expressed
 * as a value rather than as `if ($user->role === 'employee')` — a role-name
 * check would be wrong the first time a center invents a role, and it would put
 * authorization somewhere the role editor cannot see
 * (docs/13-ROADMAP.md Phase 7 §30).
 *
 * Like `BranchScope`, this holds an employee ID and no model. Employees are a
 * business module and this is the Kernel; the id is an authorization fact, the
 * row is not (docs/04-MODULE-BOUNDARIES.md §2). Resolving a user to their
 * linked employee therefore happens in a module — this only carries the answer.
 */
final readonly class AppointmentScope
{
    /**
     * @param  int|null  $employeeId  null means unrestricted
     */
    private function __construct(
        public ?int $employeeId,
        public bool $denied = false,
    ) {}

    /**
     * Every appointment in the branches the user may see.
     */
    public static function all(): self
    {
        return new self(null);
    }

    /**
     * Only appointments this employee is assigned to.
     */
    public static function ownEmployee(int $employeeId): self
    {
        return new self($employeeId);
    }

    /**
     * Nothing.
     *
     * Distinct from `ownEmployee(0)` — a user with the own-schedule permission
     * but NO linked employee record has no appointments rather than every
     * appointment, and the difference is one careless `?? null` away from being
     * a privilege escalation.
     */
    public static function none(): self
    {
        return new self(null, true);
    }

    public function isUnrestricted(): bool
    {
        return ! $this->denied && $this->employeeId === null;
    }

    public function isDenied(): bool
    {
        return $this->denied;
    }

    public function isLimitedToOwn(): bool
    {
        return ! $this->denied && $this->employeeId !== null;
    }
}
