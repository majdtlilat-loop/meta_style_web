<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Application;

use App\Kernel\Authorization\BranchScope;
use App\Modules\Reviews\Contracts\PublicRatingReader;

/**
 * {@see PublicRatingReader} through RatingSummary — the one place an average is
 * computed — so the public figure can never disagree with the Manager's.
 */
final class PublicRatingQuery implements PublicRatingReader
{
    public function __construct(private readonly RatingSummary $ratings) {}

    public function summary(): array
    {
        return $this->ratings->overall(BranchScope::all());
    }
}
