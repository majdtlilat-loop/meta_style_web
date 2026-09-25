<?php

declare(strict_types=1);

namespace App\Modules\CenterSite\Application;

use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Modules\Branches\Contracts\SiteBranchReader;
use App\Modules\Catalog\Contracts\SiteCatalogReader;
use App\Modules\CenterSite\Domain\SiteContext;
use App\Modules\Employees\Contracts\PublicTeamReader;
use App\Modules\Memberships\Contracts\SiteMembershipReader;
use App\Modules\Packages\Contracts\SitePackageReader;

/**
 * What THIS center has that its site may point at: its languages, its site
 * media, and the uuids of its services, categories, employees, branches,
 * membership plans and packages — each read through the owning module's
 * contract, never its tables.
 *
 * `context()` is what the normalizer validates against: a uuid from another
 * center is not in any of these lists, so it is refused.
 */
final class SiteReferences
{
    public function __construct(
        private readonly TenantLocales $locales,
        private readonly LanguageRegistry $languages,
        private readonly SiteMedia $media,
        private readonly SiteCatalogReader $catalog,
        private readonly PublicTeamReader $team,
        private readonly SiteBranchReader $branches,
        private readonly SiteMembershipReader $memberships,
        private readonly SitePackageReader $packages,
    ) {}

    public function context(): SiteContext
    {
        $catalog = $this->catalog->references();

        return new SiteContext(
            primary: $this->locales->default(),
            locales: $this->languages->supported(),
            media: $this->media->kinds(),
            refs: [
                'services' => $catalog['services'],
                'categories' => $catalog['categories'],
                'employees' => $this->team->references(),
                'branches' => $this->branches->references(),
                'memberships' => $this->memberships->references(),
                'packages' => $this->packages->references(),
            ],
        );
    }

    /**
     * Picker choices for the editor, in the editor's language.
     *
     * @return array{services: list<array{uuid: string, name: string, category: string|null}>, categories: list<array{uuid: string, name: string}>, employees: list<array{uuid: string, name: string}>, branches: list<array{uuid: string, name: string, is_main: bool}>, memberships: list<array{uuid: string, name: string}>, packages: list<array{uuid: string, name: string}>, offers: array{memberships: bool, packages: bool}}
     */
    public function options(string $locale): array
    {
        $catalog = $this->catalog->options($locale);

        return [
            'services' => $catalog['services'],
            'categories' => $catalog['categories'],
            'employees' => $this->team->options($locale),
            'branches' => $this->branches->options($locale),
            'memberships' => $this->memberships->options($locale),
            'packages' => $this->packages->options($locale),
            'offers' => ['memberships' => $this->memberships->offered(), 'packages' => $this->packages->offered()],
        ];
    }
}
