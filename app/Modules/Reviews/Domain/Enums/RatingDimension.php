<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Domain\Enums;

/**
 * What a structured rating row is about.
 *
 * The review itself carries the OVERALL score — the visit, and through it the
 * center. These two are the optional detail underneath it, and each one is
 * anchored to a completed journey stage: the customer may rate the service they
 * actually received, and the employee who actually performed it
 * (docs/22-REVIEWS.md §§10–12).
 *
 * There is deliberately no `center` case. The overall rating is a column on the
 * review, not a row here, so "the visit's score" can never be absent, duplicated
 * or disagreed with.
 */
enum RatingDimension: string
{
    case Service = 'service';
    case Employee = 'employee';
}
