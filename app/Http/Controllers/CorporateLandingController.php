<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\LandingCms\Application\LandingPlans;
use App\Modules\LandingCms\Domain\LandingContent;
use App\Modules\LandingCms\Domain\Models\LandingPage;
use Illuminate\Contracts\View\View;

/** The public corporate website: the PUBLISHED landing page, with live plans. */
final class CorporateLandingController extends Controller
{
    public function __invoke(LandingPlans $plans): View
    {
        $page = LandingPage::query()->where('slug', 'home')->where('status', 'published')->first();
        $content = LandingContent::hydrate($page instanceof LandingPage ? ($page->published_content ?? []) : []);

        return view('platform.landing.index', [
            'content' => $content,
            'pricing' => $plans->build(app()->getLocale()),
        ]);
    }
}
