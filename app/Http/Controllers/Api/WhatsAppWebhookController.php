<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Conversations\Application\Actions\ReceiveWhatsAppWebhook;
use App\Modules\Conversations\Domain\Data\InboundEnvelope;
use App\Modules\Conversations\Domain\Exceptions\WebhookRejected;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Meta's webhook: the registration handshake, and every inbound notification.
 *
 * ## What the caller learns from a refusal: nothing
 *
 * Every failure — unknown account, unusable credentials, bad signature,
 * malformed body — answers `403` with an empty body. They are deliberately
 * indistinguishable, because telling a forger WHICH check they failed tells
 * them how far they got and what to change (docs/25-WHATSAPP.md §8).
 *
 * The distinction is kept where it is useful: a row in the center's own
 * `whatsapp_webhook_events`, with a reason code.
 *
 * ## Why it answers 200 to things it did not act on
 *
 * Meta retries anything that is not 2xx, forever. A duplicate notification, an
 * unsupported message type and a rate-limited sender are all cases where
 * retrying achieves nothing, so they are accepted and recorded. Only a
 * notification that is not BELIEVABLE is refused.
 *
 * ## The raw body, not the parsed one
 *
 * `$request->getContent()` — the exact bytes. The signature is computed over
 * them, and Laravel's parsed array would re-encode differently
 * (key order, escapes, number formatting), so a signature check over it could
 * never match.
 */
final class WhatsAppWebhookController extends Controller
{
    /**
     * Meta's verification challenge, during webhook setup.
     *
     * A GET carrying `hub.mode`, `hub.verify_token` and `hub.challenge`. The
     * challenge is echoed back as PLAIN TEXT, because that is what Meta's
     * documented handshake expects — a JSON envelope fails the setup.
     */
    public function verify(
        string $center,
        string $account,
        Request $request,
        ReceiveWhatsAppWebhook $webhook,
    ): Response {
        unset($center);

        $challenge = $webhook->challenge($account, $this->envelope($request));

        if ($challenge === null) {
            return response('', 403);
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function __invoke(
        string $center,
        string $account,
        Request $request,
        ReceiveWhatsAppWebhook $webhook,
    ): Response {
        unset($center);

        try {
            $webhook($account, $this->envelope($request));
        } catch (WebhookRejected) {
            /*
             * Empty, and always the same. The reason code lives on the event
             * row; it is never answered to the caller.
             */
            return response('', 403);
        }

        /*
         * 200 with no body. Meta needs only the status, and a body would be an
         * opportunity to leak something about what was processed.
         */
        return response('', 200);
    }

    private function envelope(Request $request): InboundEnvelope
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $value = $values[0] ?? null;

            if (is_string($value)) {
                // Lower-cased once, here, so the adapter never has to guess at
                // a header's casing.
                $headers[mb_strtolower((string) $name)] = $value;
            }
        }

        /** @var array<string, string> $query */
        $query = array_filter($request->query(), 'is_string');

        return new InboundEnvelope(
            // The EXACT bytes. Never `$request->all()` (§8).
            rawBody: $request->getContent(),
            headers: $headers,
            query: $query,
        );
    }
}
