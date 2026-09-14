<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Enums;

/**
 * Did the customer ask for this person, or did the engine choose them?
 *
 * Worth a column because the two are operationally different. Moving a booking
 * the customer made with "anyone available" to a different stylist is a
 * scheduling detail; moving one where they asked for Ahmed by name is a
 * conversation somebody has to have. Without this, `employee_id` alone cannot
 * tell them apart after the fact (docs/13-ROADMAP.md Phase 6 §5).
 */
enum EmployeeSelection: string
{
    /** The customer named this employee. */
    case Specific = 'specific';

    /** The customer said "any available"; the engine picked. */
    case Any = 'any';
}
