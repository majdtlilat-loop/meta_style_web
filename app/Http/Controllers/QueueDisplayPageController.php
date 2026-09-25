<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Kernel\Entitlements\Entitlements;
use App\Modules\Queue\Application\DisplayPresentation;
use App\Modules\Queue\Domain\Models\QueueDisplay;
use App\View\Queue\DisplayPage;
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
 * What it does carry is the screen's PRESENTATION — its labels in every
 * language it cycles and its promotional playlist — an allow-list built by
 * `DisplayPresentation`, so the first paint is already in the right language
 * and a language switch never needs the network (§9).
 *
 * ## Fails closed
 *
 * Unknown key, inactive display, archived display and unknown center all answer
 * 404 — the same as anything else that does not exist, so nobody can enumerate
 * a center's screens.
 */
final class QueueDisplayPageController extends Controller
{
    /*
     * Collaborators are resolved per call: the router keeps its controller
     * instance, and a memoised plan must never outlive the request that read it.
     */
    public function __invoke(string $center, string $display, Entitlements $entitlements, DisplayPresentation $presentations, DisplayPage $page): View
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
        if (! $entitlements->enabled('queue_display')) {
            throw new NotFoundHttpException;
        }

        $presentation = $presentations->forDisplay($screen, app()->getLocale());

        return view('queue.display', $page->data(
            $screen,
            $presentation,
            // The feed has no session or cookie to resolve a language from, so it
            // is told the one this page started in — otherwise its first poll
            // would switch a screen on "automatic" into another language.
            route('api.queue.display', ['center' => $center, 'display' => $display, 'locale' => $presentation['start']]),
            // A screen that will chime or speak needs one touch when it is
            // switched on — browsers refuse audio before a gesture. A silent
            // screen starts straight away.
            wantsSound: $screen->sound_enabled
                || ($screen->voice_enabled && $entitlements->enabled('queue_voice')),
        ));
    }
}
