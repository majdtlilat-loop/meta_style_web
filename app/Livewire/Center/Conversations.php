<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Kernel\Time\BranchClock;
use App\Livewire\Center\Concerns\RequiresFeature;
use App\Livewire\Center\Queue\Concerns\RunsDeskActions;
use App\Modules\Conversations\Application\Actions\ChangeConversationState;
use App\Modules\Conversations\Application\Actions\ReplyAsStaff;
use App\Modules\Conversations\Application\ConversationPresenter;
use App\Modules\Conversations\Application\ConversationsQuery;
use App\Modules\Conversations\Domain\Enums\ConversationStatus;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Rayan\Application\AiProviderRegistry;
use App\Modules\Rayan\Application\RayanSettings;
use App\Modules\ServiceJourney\Application\VisitOptions;
use App\View\Manager\FeatureOffer;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The staff inbox: WhatsApp threads, and what a person does about them.
 *
 * Every rule belongs to the Actions: the lock, the authorization, the audit
 * entry and which moves are legal are decided there, and replying implies
 * taking over inside `ReplyAsStaff` rather than here (docs/25-WHATSAPP.md
 * §§12, 17). Branch scope belongs to `ConversationsQuery`, not to the filter:
 * a manager of one branch cannot reach another's threads by editing what this
 * screen sends.
 *
 * ## The customer's number is never rendered raw
 *
 * A conversation IS a phone number, and this screen would otherwise be a
 * contact list for anybody holding `conversation.view`. The presenter masks it
 * through `CustomerPresenter`, exactly as every other screen does (ADR-042).
 *
 * ## Locked, honestly
 *
 * The inbox belongs to `whatsapp_booking` (and RAYAN answers through it). A
 * center with neither sees the upgrade page; one that has threads keeps
 * reading them, and taking over or closing (authorize-only in the Action) —
 * but a new reply needs the channel, so the composer says so. The provider
 * adapters are implemented and contract-tested, NOT live-verified
 * (docs/12 §12): nothing here claims a message was delivered beyond the state
 * the channel recorded.
 */
#[Layout('components.layouts.app')]
final class Conversations extends Component
{
    use RequiresFeature;
    use RunsDeskActions;

    /** Filter: a `ConversationStatus` value, or empty for every open thread. */
    #[Url]
    public string $status = '';

    /** The thread being read, by uuid. */
    #[Url(as: 'thread')]
    public string $viewing = '';

    public string $reply = '';

    public function open(string $uuid): void
    {
        $this->viewing = $uuid;
        $this->reply = '';
        $this->notice = '';
        $this->resetErrorBag();
    }

    public function close(): void
    {
        $this->viewing = '';
    }

    public function send(ConversationsQuery $query, ReplyAsStaff $reply): void
    {
        $this->validate(['reply' => ['required', 'string', 'max:'.ReplyAsStaff::MAX_LENGTH]], [], ['reply' => __('conversations.reply')]);

        $this->act($query, function (Conversation $conversation, User $user) use ($reply): void {
            $reply($conversation, $user, $this->reply);

            $this->reply = '';
            $this->succeeded(__('conversations.sent'));
        });
    }

    public function takeOver(ConversationsQuery $query, ChangeConversationState $state): void
    {
        $this->act($query, function (Conversation $conversation, User $user) use ($state): void {
            $state->takeOver($conversation, $user);

            $this->succeeded(__('conversations.taken_over'));
        });
    }

    public function returnToAssistant(ConversationsQuery $query, ChangeConversationState $state): void
    {
        $this->act($query, function (Conversation $conversation, User $user) use ($state): void {
            $state->returnToAssistant($conversation, $user);

            $this->succeeded(__('conversations.returned'));
        });
    }

    public function closeConversation(ConversationsQuery $query, ChangeConversationState $state): void
    {
        $this->act($query, function (Conversation $conversation, User $user) use ($state): void {
            $state->close($conversation, $user);

            $this->succeeded(__('conversations.closed'));
            $this->viewing = '';
        });
    }

    public function render(ConversationsQuery $query, ConversationPresenter $presenter, Entitlements $entitlements, RayanSettings $settings, AiProviderRegistry $providers): View
    {
        $user = $this->viewer();
        $channel = $entitlements->enabled('whatsapp_booking');
        $assistant = $entitlements->enabled('rayan_ai');
        // "Assistant on" only when it can actually answer: owned, switched on
        // by the center and backed by a configured provider — the same three
        // the WhatsApp settings page shows (docs/27-RAYAN.md §6).
        $answering = $assistant && $settings->enabled() && $providers->get($settings->provider())->capabilities()->available;

        if (! $channel && ! $assistant && ! $query->hasHistory() && ($locked = $this->lockedView('whatsapp_booking', 'rayan_ai'))) {
            return $locked;
        }

        $base = [
            'allowed' => false,
            'conversations' => [],
            'counts' => [],
            'waiting' => 0,
            'thread' => null,
            'statuses' => ConversationStatus::openValues(),
            'canReply' => false,
            'canTakeover' => false,
            'channel' => $channel,
            'assistant' => $answering,
            'maxReply' => ReplyAsStaff::MAX_LENGTH,
            'offer' => $channel ? null : app(FeatureOffer::class)->for('whatsapp_booking', null, $user),
        ];

        try {
            $conversations = $query->inbox($user, $this->status === '' ? [] : ['status' => $this->status]);
            $counts = $query->counts($user);
        } catch (AuthorizationException) {
            return view('livewire.center.conversations', $base);
        }

        $timezone = $this->timezone();
        $thread = null;

        if ($this->viewing !== '') {
            $conversation = $query->find($user, $this->viewing);

            if ($conversation instanceof Conversation) {
                $thread = $presenter->detail($conversation, $query->timeline($conversation), $user);
                $thread['messages'] = array_map(fn (array $m): array => $m + [
                    'at' => $this->stamp($m['created_at'] ?? null, $timezone),
                ], $thread['messages']);
            }
        }

        return view('livewire.center.conversations', [
            'allowed' => true,
            'conversations' => array_map(function (Conversation $conversation) use ($presenter, $user, $timezone): array {
                $summary = $presenter->summary($conversation, $user);

                return [
                    'uuid' => $summary['uuid'],
                    'status' => $summary['status'],
                    'needs_attention' => $summary['needs_attention'],
                    'name' => $summary['customer']['name'] ?? null,
                    // Masked by the presenter (ADR-042); display only.
                    'contact_phone' => $summary['customer']['phone'] ?? null,
                    'branch' => $summary['branch']['name'] ?? null,
                    'assigned_to' => $summary['assigned_to'],
                    'at' => $this->stamp($summary['last_message_at'], $timezone),
                ];
            }, $conversations),
            'counts' => $counts,
            'waiting' => $counts[ConversationStatus::HumanRequested->value] ?? 0,
            'thread' => $thread === null ? null : [
                'uuid' => $thread['uuid'],
                'status' => $thread['status'],
                'name' => $thread['customer']['name'] ?? null,
                'contact_phone' => $thread['customer']['phone'] ?? null,
                'branch' => $thread['branch']['name'] ?? null,
                'assigned_to' => $thread['assigned_to'],
                'account' => $thread['account']['display_name'] ?? null,
                'messages' => $thread['messages'],
            ],
            'statuses' => ConversationStatus::openValues(),
            'canReply' => $user->hasPermission(Permission::ConversationReply),
            'canTakeover' => $user->hasPermission(Permission::ConversationTakeover),
        ] + $base);
    }

    /**
     * Resolves the thread, runs the work, and turns every refusal into a
     * message on the screen. A thread out of scope reads exactly like one that
     * does not exist, so the screen cannot be used to discover which branches
     * have conversations.
     *
     * @param  callable(Conversation, User): void  $work
     */
    private function act(ConversationsQuery $query, callable $work): void
    {
        $this->attempt(function () use ($query, $work): void {
            $conversation = $query->find($this->viewer(), $this->viewing);

            if (! $conversation instanceof Conversation) {
                $this->succeeded(__('manager_queue.errors.not_found'), 'danger');

                return;
            }

            $work($conversation, $this->viewer());
        });
    }

    /**
     * The center's wall clock: the first branch the viewer works in. A thread
     * without a branch has no better answer.
     */
    private function timezone(): string
    {
        $branches = app(VisitOptions::class)->branches($this->viewer());
        $branch = $branches === [] ? null : app(VisitOptions::class)->branch($this->viewer(), $branches[0]['uuid']);

        return $branch === null ? 'UTC' : $branch->timezone;
    }

    private function stamp(?string $iso, string $timezone): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        $local = BranchClock::toLocal(CarbonImmutable::parse($iso)->utc(), $timezone)->locale(app()->getLocale());
        $today = BranchClock::localDate(CarbonImmutable::now()->utc(), $timezone);

        return $local->toDateString() === $today ? $local->format('H:i') : $local->isoFormat('D MMM, HH:mm');
    }
}
