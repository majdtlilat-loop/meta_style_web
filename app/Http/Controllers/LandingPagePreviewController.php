<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Kernel\Localization\LanguageRegistry;
use App\Modules\LandingCms\Application\LandingPlans;
use App\Modules\LandingCms\Domain\LandingContent;
use App\Modules\LandingCms\Domain\Models\LandingPage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The saved DRAFT, rendered exactly as the public page would render it.
 *
 * Behind platform sign-in and the CMS permission (route middleware). `lang`
 * chooses the language for this render only — it never changes the Super
 * Admin's own interface language.
 */
final class LandingPagePreviewController extends Controller
{
    public function __invoke(Request $request, LandingPlans $plans, LanguageRegistry $languages): View
    {
        $lang = $request->query('lang');
        if (is_string($lang) && $languages->supports($lang)) {
            app()->setLocale($lang);
        }

        $page = LandingPage::query()->where('slug', 'home')->first();

        return view('platform.landing.index', [
            'content' => LandingContent::hydrate($page->draft_content ?? []),
            'pricing' => $plans->build(app()->getLocale()),
            'preview' => true,
        ]);
    }
}
