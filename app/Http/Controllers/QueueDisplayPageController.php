<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Localization\LanguageRegistry;
use App\Modules\Queue\Domain\Models\QueueDisplay;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The page a television loads once and then leaves open all day.
 *
 * ## Guest, read-only, and never behind auth
 *
 * It sits on `public.tenant`, which resolves the center from its public key in
 * the path — the one deliberate exception to "a tenant identifier never comes
 * from the client" (ADR-036). That middleware may never share a route with
 * authentication, and an architecture test enforces it. A staff URL on an
 * unattended screen would be a session left logged in on a wall
 * (docs/17-QUEUE.md §26).
 *
 * ## It renders a shell; the FEED is the state
 *
 * The page holds no queue data. It polls the public JSON feed and re-renders,
 * so a screen that lost its network for an hour recovers completely on its next
 * successful poll. Correctness never depends on delivery (§49).
 *
 * ## Fails closed
 *
 * Unknown key, inactive display, archived display and unknown center all answer
 * 404 — the same as anything else that does not exist, so nobody can enumerate
 * a center's screens.
 */
final class QueueDisplayPageController extends Controller
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly LanguageRegistry $languages,
    ) {}

    public function __invoke(string $center, string $display): View
    {
        $screen = QueueDisplay::query()
            ->with('branch')
            ->where('public_key', $display)
            ->first();

        if (! $screen instanceof QueueDisplay || ! $screen->isLive()) {
            throw new NotFoundHttpException;
        }

        // A center that stopped paying for screens stops serving them, and the
        // answer is the same 404 — not a message explaining the billing state
        // of a business to whoever is standing in its waiting room.
        if (! $this->entitlements->enabled('queue_display')) {
            throw new NotFoundHttpException;
        }

        $locale = $screen->locale ?? app()->getLocale();

        return view('queue.display', [
            'feedUrl' => route('api.queue.display', ['center' => $center, 'display' => $display]),
            'locale' => $locale,
            'direction' => $this->languages->direction($locale),
            'branchName' => $screen->branch?->name?->get($locale),
            'displayName' => $screen->name,
        ]);
    }
}
