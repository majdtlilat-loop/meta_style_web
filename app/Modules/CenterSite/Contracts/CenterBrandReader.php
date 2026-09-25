<?php

declare(strict_types=1);

namespace App\Modules\CenterSite\Contracts;

/**
 * The bound center's own customer-facing brand, for every public surface that
 * shows it (the center site, the public menu, the booking and cart pages,
 * printed documents).
 *
 * Separate from Meta Style's platform branding: nothing here ever reads or
 * writes the platform's logo or theme. Values are already validated — colours
 * are `#rrggbb`, gradients are built from validated stops and a fixed angle,
 * URLs point at the center's own public media — so a renderer may place them
 * into a `style` attribute or an `<img src>` without further checks.
 */
interface CenterBrandReader
{
    /**
     * @return array{
     *     name: string,
     *     logo_light_url: string|null,
     *     logo_dark_url: string|null,
     *     favicon_url: string|null,
     *     tokens: array<string, string>,
     *     radius: string
     * }
     */
    public function forPublic(): array;
}
