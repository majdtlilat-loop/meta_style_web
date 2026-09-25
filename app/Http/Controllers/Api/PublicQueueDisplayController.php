<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Http\ApiResponse;
use App\Modules\Queue\Application\DisplayFeed;
use App\Modules\Queue\Application\DisplayPresentation;
use App\Modules\Queue\Domain\Models\QueueDisplay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The feed a television polls. Read-only, unauthenticated, allow-listed.
 *
 * ## No auth middleware, ever
 *
 * This sits behind `public.tenant`, which resolves the center from its public
 * key in the path and is the one deliberate exception to "a tenant identifier
 * never comes from the client" (ADR-036). That middleware may never share a
 * route with authentication, and an architecture test enforces it — a public
 * key in a URL must not become a way to act as a center.
 *
 * ## Fails closed
 *
 * An unknown key, an inactive screen and an archived one all answer 404, the
 * same as a center that does not exist. Anything more precise would let somebody
 * enumerate which displays a center has (docs/17-QUEUE.md §9).
 *
 * ## Numbers and destinations, nothing else
 *
 * {@see DisplayFeed} names every field, the speech payload included. No
 * customer name, no phone, no notes, no employee identity, no capacity, no
 * internal ids — not even the call event's uuid (§13, §14, §48).
 *
 * ## The look of the screen rides along only when it changed
 *
 * `presentation_version` is a digest of the screen's labels, languages and
 * promotional playlist ({@see DisplayPresentation}). The page sends the digest
 * it holds as `pv`; the full presentation is added only when they differ, so a
 * playlist or language change reaches the television within one poll and
 * costs nothing the rest of the day. It never changes the queue half of the
 * payload, and never the announcement.
 */
final class PublicQueueDisplayController extends Controller
{
    public function __invoke(Request $request, string $center, string $display, DisplayFeed $feed, DisplayPresentation $presentations): JsonResponse
    {
        unset($center);

        $screen = QueueDisplay::query()
            ->with(['branch', 'department', 'servicePoint'])
            ->where('public_key', $display)
            ->first();

        if (! $screen instanceof QueueDisplay || ! $screen->isLive()) {
            throw new NotFoundHttpException;
        }

        // The announcement rides in the feed: it is built only for the CURRENT
        // call and only when the screen MAY speak, which the feed answers —
        // it owns both halves, the `queue_voice` entitlement and the screen's
        // own setting (§16, §19).
        $payload = $feed->forDisplay($screen);

        $presentation = $presentations->forDisplay($screen, app()->getLocale());
        $payload['presentation_version'] = $presentation['version'];

        if ($request->query('pv') !== $presentation['version']) {
            $payload['presentation'] = $presentation;
        }

        return ApiResponse::data($payload);
    }
}
