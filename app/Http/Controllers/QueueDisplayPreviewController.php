<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Modules\Queue\Application\DisplayFeed;
use App\Modules\Queue\Application\DisplayLanguages;
use App\Modules\Queue\Application\DisplayPresentation;
use App\Modules\Queue\Application\QueueSetupQuery;
use App\Modules\Queue\Domain\Models\QueueDisplay;
use App\View\Queue\DisplayPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Manager's preview of a waiting-room screen: the REAL television page and
 * the REAL feed, for a signed-in manager — no copy of the queue logic.
 *
 * Lives in the authenticated Manager group (auth:web, tenant by host), never
 * under the public resolver (ADR-036). The screen is re-resolved through
 * `QueueSetupQuery::display()` — `queue.display.manage` and the screen's
 * branch — and needs `queue_display`; anything else is a 403 or a 404.
 *
 * Differences from the wall, all presentational: it works while the screen is
 * switched off (so it can be checked before going live); it never speaks,
 * chimes or asks for the one-touch start — its feed carries neither the speech
 * payload nor the new-call key; `lang` pins ANY language the center
 * has switched on — not only the ones the screen cycles — so each language and
 * its direction can be checked, and a pinned preview never rotates; a language
 * the center has not switched on is ignored. `sample=1` shows a clearly
 * labelled sample call when nobody has been called yet, so the call-over-media
 * layout can be seen. It never writes anything (docs/17-QUEUE.md §9).
 *
 * Collaborators are resolved per call, not held by the controller: a router
 * keeps its controller instance, and a memoised plan must never outlive the
 * request that read it.
 */
final class QueueDisplayPreviewController extends Controller
{
    public function page(
        Request $request,
        string $center,
        string $uuid,
        QueueSetupQuery $setup,
        Entitlements $entitlements,
        DisplayLanguages $languages,
        DisplayPresentation $presentations,
        DisplayPage $page,
    ): Response {
        $screen = $this->screen($request, $uuid, $setup, $entitlements);
        $pin = $languages->pinnable($request->query('lang'));
        $presentation = $presentations->forDisplay($screen, app()->getLocale(), $pin);

        // The feed is pinned to the same language, or its first poll would
        // hand the page the screen's own presentation back.
        $feed = ['center' => $center, 'uuid' => $screen->uuid];

        if ($pin !== null) {
            $feed['lang'] = $pin;
        }

        return response()
            ->view('queue.display', $page->data(
                $screen,
                $presentation,
                route('center.queue.displays.preview.feed', $feed),
                wantsSound: false,
                preview: true,
                lockLocale: $pin,
                sample: $request->boolean('sample'),
            ))
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store, private');
    }

    public function feed(
        Request $request,
        string $center,
        string $uuid,
        QueueSetupQuery $setup,
        Entitlements $entitlements,
        DisplayLanguages $languages,
        DisplayPresentation $presentations,
        DisplayFeed $feed,
    ): JsonResponse {
        unset($center);

        $screen = $this->screen($request, $uuid, $setup, $entitlements);
        $pin = $languages->pinnable($request->query('lang'));

        $payload = $feed->forDisplay($screen, pin: $pin);

        // A preview never speaks or chimes: no speech payload and no new-call
        // key, so there is nothing to act on whatever the page is told.
        $payload['announcement'] = null;
        $payload['call_key'] = null;

        $presentation = $presentations->forDisplay($screen, app()->getLocale(), $pin);
        $payload['presentation_version'] = $presentation['version'];

        if ($request->query('pv') !== $presentation['version']) {
            $payload['presentation'] = $presentation;
        }

        return ApiResponse::data($payload)->header('Cache-Control', 'no-store, private');
    }

    private function screen(Request $request, string $uuid, QueueSetupQuery $setup, Entitlements $entitlements): QueueDisplay
    {
        $user = $request->user('web');

        abort_unless($user instanceof User, 403);

        // Permission and branch; a foreign or unknown screen is not found.
        $screen = $setup->display($user, $uuid);

        if (! $entitlements->enabled('queue_display')) {
            throw new NotFoundHttpException;
        }

        return $screen;
    }
}
