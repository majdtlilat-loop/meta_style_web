<?php

declare(strict_types=1);

namespace App\Modules\Menu\Domain;

/**
 * Where a menu version sits in the draft → publish → history cycle.
 *
 * Exactly one draft and exactly one published version exist at a time; every
 * previously published version is archived and stays available for rollback.
 */
enum MenuVersionStatus: string
{
    /** What the owner is editing. Never visible to a customer. */
    case Draft = 'draft';

    /** What customers see right now. */
    case Published = 'published';

    /** Previously live. Kept so a bad publish can be undone. */
    case Archived = 'archived';

    public function isLive(): bool
    {
        return $this === self::Published;
    }
}
