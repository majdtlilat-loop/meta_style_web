<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Http\ApiErrorCode;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Modules\Conversations\Application\Actions\ChangeConversationState;
use App\Modules\Conversations\Application\Actions\ReplyAsStaff;
use App\Modules\Conversations\Application\ConversationPresenter;
use App\Modules\Conversations\Application\ConversationsQuery;
use App\Modules\Conversations\Domain\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The staff conversation surface: read a thread, reply, take it over, close it.
 *
 * Validate → one Action → a presented shape. No business logic, no direct
 * database access (docs/01-ARCHITECTURE.md §4).
 *
 * A conversation the reader may not see is `404`, never `403` — a thread in
 * another branch must not be distinguishable from one that does not exist
 * (docs/08-AUDIT-SECURITY.md).
 */
final class ConversationController extends Controller
{
    public function index(
        Request $request,
        ConversationsQuery $query,
        ConversationPresenter $presenter,
    ): JsonResponse {
        $user = $this->user($request);

        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:24'],
            'branch' => ['nullable', 'integer'],
        ]);

        $conversations = $query->inbox($user, array_filter($filters, static fn (mixed $v): bool => $v !== null));

        return ApiResponse::data([
            'conversations' => array_map(
                fn (Conversation $conversation): array => $presenter->summary($conversation, $user),
                $conversations,
            ),
            // The number in the navigation. One indexed count, never a
            // collection counted in PHP.
            'waiting' => $query->waitingCount($user),
        ]);
    }

    public function show(
        string $uuid,
        Request $request,
        ConversationsQuery $query,
        ConversationPresenter $presenter,
    ): JsonResponse {
        $user = $this->user($request);

        $conversation = $query->find($user, $uuid);

        if (! $conversation instanceof Conversation) {
            return ApiResponse::error(ApiErrorCode::NotFound, 'That conversation was not found.');
        }

        return ApiResponse::data([
            'conversation' => $presenter->detail($conversation, $query->timeline($conversation), $user),
        ]);
    }

    public function reply(
        string $uuid,
        Request $request,
        ConversationsQuery $query,
        ReplyAsStaff $reply,
        ConversationPresenter $presenter,
    ): JsonResponse {
        $user = $this->user($request);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.ReplyAsStaff::MAX_LENGTH],
        ]);

        $conversation = $query->find($user, $uuid);

        if (! $conversation instanceof Conversation) {
            return ApiResponse::error(ApiErrorCode::NotFound, 'That conversation was not found.');
        }

        $message = $reply($conversation, $user, (string) $data['body']);

        return ApiResponse::data(['message' => $presenter->messages([$message])[0]], 201);
    }

    public function takeOver(
        string $uuid,
        Request $request,
        ConversationsQuery $query,
        ChangeConversationState $state,
        ConversationPresenter $presenter,
    ): JsonResponse {
        return $this->transition($uuid, $request, $query, $presenter, $state->takeOver(...));
    }

    public function returnToAssistant(
        string $uuid,
        Request $request,
        ConversationsQuery $query,
        ChangeConversationState $state,
        ConversationPresenter $presenter,
    ): JsonResponse {
        return $this->transition($uuid, $request, $query, $presenter, $state->returnToAssistant(...));
    }

    public function close(
        string $uuid,
        Request $request,
        ConversationsQuery $query,
        ChangeConversationState $state,
        ConversationPresenter $presenter,
    ): JsonResponse {
        return $this->transition($uuid, $request, $query, $presenter, $state->close(...));
    }

    /**
     * The three state changes differ only in which Action method runs, so the
     * lookup, the 404 and the response shape are written once.
     *
     * @param  callable(Conversation, User): Conversation  $move
     */
    private function transition(
        string $uuid,
        Request $request,
        ConversationsQuery $query,
        ConversationPresenter $presenter,
        callable $move,
    ): JsonResponse {
        $user = $this->user($request);

        $conversation = $query->find($user, $uuid);

        if (! $conversation instanceof Conversation) {
            return ApiResponse::error(ApiErrorCode::NotFound, 'That conversation was not found.');
        }

        return ApiResponse::data([
            'conversation' => $presenter->summary($move($conversation, $user), $user),
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
