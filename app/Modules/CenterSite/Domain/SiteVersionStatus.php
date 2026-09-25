<?php

declare(strict_types=1);

namespace App\Modules\CenterSite\Domain;

/**
 * Where a site version sits in the draft → publish → history cycle.
 *
 * At most one draft and one published version exist at a time; every version
 * that was ever live is archived and stays available to restore from.
 */
enum SiteVersionStatus: string
{
    /** What the owner is editing. Never visible to a customer. */
    case Draft = 'draft';

    /** What the center's public page renders right now. */
    case Published = 'published';

    /** Previously live. Kept so a change can be undone by copying it forward. */
    case Archived = 'archived';
}
