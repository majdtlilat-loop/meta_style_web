<?php

declare(strict_types=1);

namespace App\Modules\CenterSite\Http;

use App\Modules\CenterSite\Application\PublicSitePage;
use Illuminate\Contracts\View\View;

/**
 * Gives the center's other public pages (cart, checkout) the same frame as
 * the home page: brand tokens, header, footer, favicon.
 *
 * Only when the page did not already pass one — the home page and the
 * preview build their own, from the published or the draft content.
 */
final class CenterPublicShellComposer
{
    public function __construct(private readonly PublicSitePage $site) {}

    public function compose(View $view): void
    {
        if (! array_key_exists('page', $view->getData())) {
            $view->with('page', $this->site->shell(app()->getLocale()));
        }
    }
}
