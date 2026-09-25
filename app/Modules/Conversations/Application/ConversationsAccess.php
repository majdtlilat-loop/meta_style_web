<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use App\Modules\Conversations\Domain\Models\Conversation;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The gates every conversation operation passes.
 *
 *   1. the CENTER owns the capability   — NEW activity only
 *   2. the USER holds the permission    — never a role name, no Owner bypass
 *   3. the user may work at the BRANCH  — a thread belongs to a branch once it
 *                                         is about a visit at one
 *
 * ## What a downgrade stops, and what it must not
 *
 * The Booking rule again (docs/05-ENTITLEMENTS.md §6.2). Losing
 * `whatsapp_booking` stops the center RECEIVING and SENDING — the bot goes
 * quiet. It does NOT stop staff reading the threads they already have, or
 * closing them: a center that can no longer see what was said to their
 * customers in their name is worse off than one that never had the feature,
 * and the conversation is a record of something the center did
 * (docs/25-WHATSAPP.md §19).
 *
 * ## A thread with no branch
 *
 * A conversation has no branch until it is about something at one — a first
 * message is just a phone number saying hello. Branch scope is therefore
 * checked only when there IS a branch; an unassigned thread is visible to
 * anybody with the permission, because the alternative is a thread nobody can
 * pick up.
 */
final class ConversationsAccess
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * New activity — receiving, replying, letting the assistant answer.
     *
     * @throws EntitlementRequired
     * @throws AuthorizationException
     */
    public function ensure(User $user, Permission $permission, Conversation $conversation, string $refusal): void
    {
        $this->entitlements->ensure('whatsapp_booking');

        $this->authorize($user, $permission, $conversation, $refusal);
    }

    /**
     * Reading and closing. Never asks about the entitlement.
     *
     * @throws AuthorizationException
     */
    public function authorize(User $user, Permission $permission, Conversation $conversation, string $refusal): void
    {
        if (! $user->hasPermission($permission)) {
            throw new AuthorizationException($refusal);
        }

        $branchId = $conversation->branch_id;

        if ($branchId !== null && ! $user->canAccessBranch($branchId)) {
            throw new AuthorizationException('You may not work in that branch.');
        }
    }

    /**
     * Whether the center may run the WhatsApp channel at all right now.
     *
     * For the inbound path and the AI listener, which have no user and must
     * not throw — a webhook that raised an entitlement exception would be
     * answered non-2xx, and Meta would retry it forever.
     */
    public function channelEnabled(): bool
    {
        return $this->entitlements->enabled('whatsapp_booking');
    }

    /**
     * Whether the assistant may answer at all right now.
     *
     * A center can own the WhatsApp channel WITHOUT owning RAYAN — that is a
     * real configuration: messages arrive, staff answer them by hand, and no
     * AI is involved. So the two are checked separately and never collapsed
     * (§19).
     */
    public function assistantEnabled(): bool
    {
        return $this->entitlements->enabled('rayan_ai');
    }
}
