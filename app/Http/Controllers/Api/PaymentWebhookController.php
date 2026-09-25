<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Application\Actions\ReceiveGatewayWebhook;
use App\Modules\Payments\Domain\Data\InboundWebhook;
use App\Modules\Payments\Domain\Exceptions\ProviderRequestFailed;
use App\Modules\Payments\Domain\Exceptions\WebhookRejected;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A payment provider's callback.
 *
 * `public.tenant` resolved the center from its public key; nothing here
 * authenticates a user, and no auth middleware may ever be added — a test
 * enforces it for every public route.
 *
 * The answers say as little as possible (docs/19-PAYMENTS.md §§21, 84):
 *
 *   202  received — processed, a duplicate, still pending, or for a payment this
 *        account does not have. The same answer, so references cannot be probed.
 *   400  not a believable callback. No reason given.
 *   503  the provider's status could not be read; the provider should retry.
 *
 * The body is handed to the adapter and discarded. It is never logged or stored.
 */
final class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $center, string $account, ReceiveGatewayWebhook $receive): JsonResponse
    {
        unset($center);

        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }

        try {
            $receive($account, new InboundWebhook((string) $request->getContent(), $headers));
        } catch (WebhookRejected) {
            return new JsonResponse(['received' => false], 400);
        } catch (ProviderRequestFailed) {
            return new JsonResponse(['received' => false], 503);
        }

        return new JsonResponse(['received' => true], 202);
    }
}
