<?php

declare(strict_types=1);

namespace App\Kernel\Storage;

/**
 * Where a file lives, and the rules that come with it.
 *
 * Making the collection an enum means "which disk, public or private, what may
 * be stored" is a property of the collection rather than a decision repeated —
 * and eventually got wrong — at every call site (docs/09-STORAGE.md §5).
 *
 * Only the collections Phase 2 can exercise are defined. The rest arrive with
 * the features that use them; an enum full of unreachable cases is
 * documentation pretending to be code.
 */
enum MediaCollection: string
{
    /** Logos, covers, icons. Rendered on the public menu, so CDN-cacheable. */
    case Branding = 'branding';

    /**
     * Service, department, category and branch photography.
     *
     * Public for the same reason as branding: it is the electronic menu, which
     * a guest opens with no account. Separate from branding so a center can
     * later be given a different retention or CDN policy for a large gallery
     * than for its logo.
     */
    case Catalog = 'catalog';

    /** Generated reports and data exports. Private, expiring. */
    case Exports = 'exports';

    /** Uploads in progress. Purged daily. */
    case Temp = 'tmp';

    /**
     * Private collections are never served by a public URL — access goes
     * through a permission check and a short-lived signed URL
     * (docs/09-STORAGE.md §7).
     */
    public function isPublic(): bool
    {
        return in_array($this, [self::Branding, self::Catalog], true);
    }

    /**
     * The Laravel disk backing this collection. Both disks are made
     * tenant-aware by the tenancy filesystem bootstrapper, which roots them at
     * `tenants/{tenant-key}/`.
     */
    public function disk(): string
    {
        return $this->isPublic() ? 'public' : 'local';
    }

    public function directory(): string
    {
        return $this->value;
    }
}
