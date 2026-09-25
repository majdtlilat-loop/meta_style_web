<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Authorization\Permission;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Modules\Conversations\Application\Actions\ManageWhatsAppAccount;
use App\Modules\Conversations\Application\WhatsAppConnections;
use App\Modules\Conversations\Domain\Models\WhatsAppAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The center's WhatsApp connection: read its state, or replace its credentials.
 *
 * ## The response NEVER contains a credential
 *
 * Not the access token, not the app secret, not the verify token, and not a
 * masked version of any of them. What a manager needs is "is this configured
 * and working", which is a boolean and a timestamp — and a masked secret is
 * still a partial secret that a screenshot carries (docs/25-WHATSAPP.md §20).
 *
 * `phone_number_id` and the display number ARE returned. Neither is a secret:
 * one appears in every outbound URL and every inbound notification, the other
 * is printed on the center's own shopfront.
 *
 * ## The webhook URL is shown, deliberately
 *
 * The manager has to paste it into their Meta app. It carries the center's
 * PUBLIC key and the account's PUBLIC uuid, and knowing both lets somebody send
 * a notification that will be rejected for want of a signature — so it is safe
 * to display, copy and email (§8).
 */
final class WhatsAppAccountController extends Controller
{
    public function show(Request $request, WhatsAppConnections $connections): JsonResponse
    {
        $user = $this->authorized($request);
        unset($user);

        /** @var WhatsAppAccount|null $account */
        $account = WhatsAppAccount::query()->orderBy('id')->first();

        return ApiResponse::data(['account' => $this->present($account, $connections)]);
    }

    public function update(
        Request $request,
        ManageWhatsAppAccount $manage,
        WhatsAppConnections $connections,
    ): JsonResponse {
        $user = $this->authorized($request);

        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:120'],
            /*
             * Meta's identifier for the sending number. Constrained to the
             * shape Meta issues because it goes into an outbound URL PATH —
             * the Action re-checks this, and so does this layer, because the
             * cost of the check is nothing and the cost of missing it is a
             * request carrying the center's token to an address of somebody
             * else's choosing (ADR-071).
             */
            'phone_number_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'business_account_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'display_phone_number' => ['nullable', 'string', 'max:32'],
            'enabled' => ['required', 'boolean'],

            // Empty keeps what is stored, so a manager can rename or toggle
            // without re-entering three secrets.
            'access_token' => ['nullable', 'string', 'max:512'],
            'app_secret' => ['nullable', 'string', 'max:255'],
            'verify_token' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var WhatsAppAccount|null $existing */
        $existing = WhatsAppAccount::query()->orderBy('id')->first();

        $account = $manage->save(
            user: $user,
            displayName: (string) $data['display_name'],
            phoneNumberId: (string) $data['phone_number_id'],
            businessAccountId: $data['business_account_id'] ?? null,
            displayPhoneNumber: $data['display_phone_number'] ?? null,
            credentials: [
                'access_token' => (string) ($data['access_token'] ?? ''),
                'app_secret' => (string) ($data['app_secret'] ?? ''),
                'verify_token' => (string) ($data['verify_token'] ?? ''),
            ],
            enabled: (bool) $data['enabled'],
            account: $existing,
        );

        return ApiResponse::data(['account' => $this->present($account, $connections)]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function present(?WhatsAppAccount $account, WhatsAppConnections $connections): ?array
    {
        if (! $account instanceof WhatsAppAccount) {
            return null;
        }

        return [
            'uuid' => $account->uuid,
            'provider' => $account->provider,
            'display_name' => $account->display_name,
            'phone_number_id' => $account->phone_number_id,
            'business_account_id' => $account->business_account_id,
            'display_phone_number' => $account->display_phone_number,
            'enabled' => $account->enabled,
            // Whether it CAN work, never what makes it work.
            'configured' => $account->isConfigured(),
            'configured_at' => $account->configured_at?->toIso8601String(),
            'configured_by' => $account->configured_by_label,
            /*
             * FACTS, not claims. Both are written only from a real provider
             * interaction, so "last inbound: never" genuinely means Meta has
             * not reached this endpoint — which is the first thing to check
             * when a center says the bot is silent (§16).
             */
            'last_inbound_at' => $account->last_inbound_at?->toIso8601String(),
            'last_error_at' => $account->last_error_at?->toIso8601String(),
            'last_error_code' => $account->last_error_code,
            'webhook_url' => $connections->webhookUrl($account),
        ];
    }

    private function authorized(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        if (! $user->hasPermission(Permission::WhatsAppManage)) {
            abort(403, 'You may not manage the WhatsApp connection.');
        }

        return $user;
    }
}
