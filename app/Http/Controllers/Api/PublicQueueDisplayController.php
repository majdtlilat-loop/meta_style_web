<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Http\ApiResponse;
use App\Modules\Queue\Application\Announcement;
use App\Modules\Queue\Application\DisplayFeed;
use App\Modules\Queue\Domain\Models\QueueDisplay;
use App\Modules\Queue\Domain\Models\QueueTicket;
use Illuminate\Http\JsonResponse;
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
 * {@see DisplayFeed} names every field. No customer name, no phone, no notes,
 * no employee identity, no capacity, no internal ids (§14, §48).
 */
final class PublicQueueDisplayController extends Controller
{
    public function __invoke(string $center, string $display, DisplayFeed $feed, Announcement $announcements): JsonResponse
    {
        unset($center);

        $screen = QueueDisplay::query()
            ->with(['branch', 'department', 'servicePoint'])
            ->where('public_key', $display)
            ->first();

        if (! $screen instanceof QueueDisplay || ! $screen->isLive()) {
            throw new NotFoundHttpException;
        }

        $payload = $feed->forDisplay($screen);

        /*
         * The announcement is built only for the CURRENT call, and only when
         * the screen MAY speak — which the feed answers, because it owns both
         * halves of that question: the `queue_voice` entitlement and the
         * screen's own setting. A silent screen carries no speech payload at
         * all: nothing for a browser to read out, and nothing extra on the wire
         * every three seconds (§16, §19).
         */
        if ($feed->mayAnnounce($screen) && $payload['now_calling'] !== null) {
            $ticket = QueueTicket::query()
                ->with('servicePoint')
                ->where('branch_id', $screen->branch_id)
                ->where('last_announcement_uuid', $payload['now_calling']['announcement_id'])
                ->first();

            $payload['announcement'] = $ticket instanceof QueueTicket
                ? $announcements->forTicket($ticket, $screen->voiceLocales())
                : null;
        } else {
            $payload['announcement'] = null;
        }

        return ApiResponse::data($payload);
    }
}
