<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Conversations\Domain\Enums\ConversationStatus;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\Message;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reading conversations, within the reader's branch scope.
 *
 * READS NEVER WRITE. Nothing here changes a status, sends a message, resolves a
 * customer or touches the assistant — the same rule the benefit queries follow,
 * and an architecture test enforces it (docs/21-LOYALTY... §, ADR-061).
 *
 * ## Branch scope on a thread that has no branch
 *
 * A conversation has no branch until it is about something at one. Those are
 * visible to anybody with the permission: the alternative is a thread nobody
 * can pick up, which is the opposite of what an inbox is for. Once a branch IS
 * known, the usual scope applies (docs/25-WHATSAPP.md §19).
 */
final class ConversationsQuery
{
    /** One screen. The inbox is a worklist, not an archive. */
    private const PER_PAGE = 30;

    /**
     * The staff inbox: open threads, most recently active first.
     *
     * @param  array{status?: string, branch?: int}  $filters
     * @return list<Conversation>
     *
     * @throws AuthorizationException
     */
    public function inbox(User $user, array $filters = []): array
    {
        $this->assertMayView($user);

        $query = Conversation::query()
            ->with([
                /*
                 * `customer.account`, NOT `customer`. `isRegistered()` falls
                 * back to `account()->exists()` whenever that relation is not
                 * loaded, so presenting a customer costs one SELECT PER THREAD
                 * — invisible on a demo inbox, thirty queries on a real one.
                 * `CustomerQuery::list()` loads it for the same reason.
                 */
                'customer.account', 'branch', 'assignee',
            ])
            ->open();

        $status = ConversationStatus::tryFrom((string) ($filters['status'] ?? ''));

        if ($status instanceof ConversationStatus) {
            $query->where('status', $status->value);
        }

        $this->scopeToBranches($query, $user);

        if (isset($filters['branch'])) {
            $query->where('branch_id', (int) $filters['branch']);
        }

        /** @var list<Conversation> $conversations */
        $conversations = $query
            // `last_message_at` then `id`: two threads that last moved in the
            // same second still have a defined order.
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(self::PER_PAGE)
            ->get()
            ->all();

        return $conversations;
    }

    /**
     * How many threads are waiting for a person.
     *
     * The number in the navigation, so it is ONE indexed count and never a
     * collection that gets counted in PHP.
     *
     * @throws AuthorizationException
     */
    public function waitingCount(User $user): int
    {
        $this->assertMayView($user);

        $query = Conversation::query()->where('status', ConversationStatus::HumanRequested->value);

        $this->scopeToBranches($query, $user);

        return $query->count();
    }

    /**
     * Open threads per status, for the inbox filter — one grouped count, in
     * the same branch scope as the inbox itself.
     *
     * @return array<string, int>
     *
     * @throws AuthorizationException
     */
    public function counts(User $user): array
    {
        $this->assertMayView($user);

        $query = Conversation::query()->open();

        $this->scopeToBranches($query, $user);

        $counts = array_fill_keys(ConversationStatus::openValues(), 0);

        foreach ($query->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->toBase()->get() as $row) {
            $status = (string) ($row->status ?? '');

            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) ($row->aggregate ?? 0);
            }
        }

        return $counts;
    }

    /**
     * Has this center ever had a conversation? Decides whether a center
     * without the channel sees its read-only inbox or the upgrade page.
     */
    public function hasHistory(): bool
    {
        return Conversation::query()->exists();
    }

    /**
     * One thread, if the reader may see it.
     *
     * @throws AuthorizationException
     */
    public function find(User $user, string $uuid): ?Conversation
    {
        $this->assertMayView($user);

        /** @var Conversation|null $conversation */
        $conversation = Conversation::query()
            ->with(['customer.account', 'branch', 'assignee', 'account'])
            ->where('uuid', $uuid)
            ->first();

        if (! $conversation instanceof Conversation) {
            return null;
        }

        $branchId = $conversation->branch_id;

        if ($branchId !== null && ! $user->canAccessBranch($branchId)) {
            // A thread in another branch is NOT FOUND, not forbidden — the same
            // rule every other record follows (docs/08-AUDIT-SECURITY.md).
            return null;
        }

        return $conversation;
    }

    /**
     * The timeline, oldest first.
     *
     * Bounded: a thread that has been running for a month is not something a
     * browser should be asked to render in one go.
     *
     * @return list<Message>
     */
    public function timeline(Conversation $conversation, int $limit = 100): array
    {
        /** @var list<Message> $messages */
        $messages = $conversation->messages()
            ->with('author')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->all();

        return $messages;
    }

    /**
     * Branch scope, PLUS the threads that have no branch yet.
     *
     * `BranchScope::applyTo()` on its own emits `whereIn(branch_id, ...)`,
     * which silently excludes NULL — and NULL is the normal state of a thread
     * nobody has worked out the branch of yet. Excluding those would hide every
     * brand-new conversation from every branch-scoped manager, which is exactly
     * the inbox they need to see (docs/25-WHATSAPP.md §19).
     *
     * @param  Builder<Conversation>  $query
     */
    private function scopeToBranches(Builder $query, User $user): void
    {
        $scope = $user->branchScope();

        if ($scope->isUnrestricted()) {
            return;
        }

        $query->where(function (Builder $scoped) use ($scope): void {
            $scoped->whereNull('branch_id')
                ->orWhere(static fn (Builder $inner): Builder => $scope->applyTo($inner));
        });
    }

    /**
     * @throws AuthorizationException
     */
    private function assertMayView(User $user): void
    {
        if (! $user->hasPermission(Permission::ConversationView)) {
            throw new AuthorizationException('You may not view conversations.');
        }
    }
}
