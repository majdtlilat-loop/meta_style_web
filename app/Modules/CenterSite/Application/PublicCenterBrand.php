<?php

declare(strict_types=1);

namespace App\Modules\CenterSite\Application;

use App\Kernel\Media\MediaOwner;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\CenterSite\Contracts\CenterBrandReader;
use App\Modules\CenterSite\Domain\CenterTheme;

/**
 * {@see CenterBrandReader}: the bound center's brand, ready to render.
 *
 * Two queries at most — the settings row and the brand media — and only
 * validated values leave: colours and gradients come from CenterTheme, URLs
 * from the center's own public media route.
 */
final class PublicCenterBrand implements CenterBrandReader
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly BrandSettings $settings,
        private readonly SiteMedia $media,
    ) {}

    public function forPublic(): array
    {
        $brand = $this->settings->get();
        $assets = $this->media->resolve([$brand['logo_light'], $brand['logo_dark'], $brand['favicon']], MediaOwner::Brand);

        return [
            'name' => (string) ($this->tenants->tenant()->name ?? ''),
            'logo_light_url' => $assets[$brand['logo_light']]['url'] ?? null,
            'logo_dark_url' => $assets[$brand['logo_dark']]['url'] ?? null,
            'favicon_url' => $assets[$brand['favicon']]['url'] ?? null,
            'tokens' => CenterTheme::tokens($brand),
            'radius' => $brand['radius'],
        ];
    }
}
