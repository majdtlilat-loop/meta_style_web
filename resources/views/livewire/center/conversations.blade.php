{{--
    Conversations: WhatsApp threads, and the person answering them.
    docs/25-WHATSAPP.md §17.

    EVERY value is escaped by Blade — a message body is text a customer wrote
    and a delivery state is a string a provider sent. The customer's number
    arrives already masked by the presenter as `contact_phone` (ADR-042).
    Delivery is shown exactly as the channel recorded it, never more.
--}}
<div class="stack conversations-page" @if($allowed) wire:poll.10s.visible @endif>
    <x-ui.page-header :title="__('conversations.title')">
        <x-slot:meta>
            @if($waiting > 0)
                <span class="badge" data-tone="warning"><x-ui.icon name="bell" size="12" />{{ __('conversations.waiting_count', ['count' => $waiting]) }}</span>
            @endif
            @if($assistant)
                <span class="badge" data-tone="info"><x-ui.icon name="sparkles" size="12" />{{ __('conversations.assistant_on') }}</span>
            @endif
        </x-slot:meta>
    </x-ui.page-header>

    @if(! $channel && $offer)
        <x-manager.feature-locked :offer="$offer" compact history />
    @endif

    @if(! $allowed)
        <x-ui.empty-state icon="lock" :title="__('conversations.not_allowed')" :description="__('conversations.not_allowed_hint')" />
    @else
        <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />

        <div class="segmented segmented--scroll" role="group" aria-label="{{ __('ui.fields.status') }}">
            <button type="button" wire:click="$set('status', '')" aria-pressed="{{ $status === '' ? 'true' : 'false' }}">{{ __('conversations.all_open') }}<span class="segmented__count">{{ array_sum($counts) }}</span></button>
            @foreach($statuses as $value)
                <button type="button" wire:click="$set('status', '{{ $value }}')" aria-pressed="{{ $status === $value ? 'true' : 'false' }}">{{ __('conversations.filter_'.$value) }}<span class="segmented__count">{{ $counts[$value] ?? 0 }}</span></button>
            @endforeach
        </div>

        <div class="inbox" data-thread-open="{{ $thread ? 'true' : 'false' }}">
            <section class="card card--flush inbox__list" aria-label="{{ __('conversations.title') }}">
                @if($conversations === [])
                    <x-ui.empty-state icon="conversations" compact :title="__('conversations.empty')" />
                @else
                    <ul class="inbox__threads">
                        @foreach($conversations as $row)
                            <li wire:key="conversation-{{ $row['uuid'] }}">
                                <button type="button" class="inbox__thread" wire:click="open('{{ $row['uuid'] }}')" aria-current="{{ $viewing === $row['uuid'] ? 'true' : 'false' }}" @if($row['needs_attention']) data-attention @endif>
                                    <span class="avatar" aria-hidden="true">{{ $row['name'] ? mb_strtoupper(mb_substr($row['name'], 0, 1)) : '#' }}</span>
                                    <span class="inbox__thread-body">
                                        <span class="inbox__thread-top">
                                            <strong>{{ $row['name'] ?? __('conversations.unknown_contact') }}</strong>
                                            <time class="cell-sub tabular">{{ $row['at'] }}</time>
                                        </span>
                                        <span class="inbox__thread-sub">
                                            @if($row['contact_phone'])<span class="tabular" dir="ltr">{{ $row['contact_phone'] }}</span>@endif
                                            @if($row['branch'])<span>· {{ $row['branch'] }}</span>@endif
                                        </span>
                                        <x-ui.status :value="$row['status']" :label="__('conversations.status_'.$row['status'], ['name' => $row['assigned_to'] ?? __('conversations.a_colleague')])" />
                                    </span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="card card--flush inbox__thread-view" aria-label="{{ __('conversations.thread') }}">
                @if($thread === null)
                    <x-ui.empty-state icon="message" compact :title="__('conversations.pick')" />
                @else
                    <header class="card__header inbox__thread-head">
                        <button type="button" class="icon-button inbox__back" wire:click="close" aria-label="{{ __('ui.actions.back') }}"><x-ui.icon name="arrow-left" /></button>
                        <div>
                            <h2>{{ $thread['name'] ?? __('conversations.unknown_contact') }}</h2>
                            <p class="cell-sub">
                                @if($thread['contact_phone'])<span dir="ltr" class="tabular">{{ $thread['contact_phone'] }}</span>@endif
                                @if($thread['branch']) · {{ $thread['branch'] }}@endif
                                @if($thread['account']) · {{ $thread['account'] }}@endif
                            </p>
                        </div>
                        <x-ui.status :value="$thread['status']" :label="__('conversations.status_'.$thread['status'], ['name' => $thread['assigned_to'] ?? __('conversations.a_colleague')])" />
                    </header>

                    <ol class="chat" aria-live="polite">
                        @forelse($thread['messages'] as $message)
                            <li class="chat__message" data-direction="{{ $message['direction'] }}" data-author="{{ $message['author'] }}" wire:key="message-{{ $message['uuid'] }}">
                                <div class="chat__bubble">
                                    <p class="chat__author">
                                        {{-- Which voice said it: the bot and a colleague are different conversations (§3). --}}
                                        {{ $message['author'] === 'staff' ? ($message['author_name'] ?? __('conversations.author_staff')) : __('conversations.author_'.$message['author']) }}
                                    </p>
                                    <p class="prewrap" dir="auto">{{ $message['body'] }}</p>
                                    @if($message['template'])<span class="chip chip--muted">{{ __('conversations.template', ['name' => $message['template']]) }}</span>@endif
                                    <p class="chat__meta">
                                        <time class="tabular">{{ $message['at'] }}</time>
                                        @if($message['direction'] === 'outbound' && $message['delivery_state'] && $message['delivery_state'] !== 'sent')
                                            {{-- `unknown` is its own state, never "failed": the customer may well have it (§9). --}}
                                            <span class="chat__delivery" data-state="{{ $message['delivery_state'] }}">{{ __('conversations.delivery_'.$message['delivery_state']) }}</span>
                                        @endif
                                    </p>
                                </div>
                            </li>
                        @empty
                            <li class="muted chat__empty">{{ __('conversations.no_messages') }}</li>
                        @endforelse
                    </ol>

                    @if($thread['status'] !== 'closed')
                        <footer class="inbox__composer">
                            @if($canTakeover)
                                <div class="cluster cluster--tight">
                                    @if($thread['status'] !== 'human_active')
                                        <x-ui.button size="sm" variant="secondary" icon="user-check" wire:click="takeOver" wire:loading.attr="data-loading" wire:target="takeOver">{{ __('conversations.take_over') }}</x-ui.button>
                                    @elseif($assistant)
                                        <x-ui.button size="sm" variant="secondary" icon="sparkles" wire:click="returnToAssistant" wire:loading.attr="data-loading" wire:target="returnToAssistant">{{ __('conversations.return_to_assistant') }}</x-ui.button>
                                    @endif
                                    <x-ui.button size="sm" variant="ghost" icon="archive" wire:click="closeConversation"
                                        wire:confirm="{{ __('conversations.close_confirm') }}" data-confirm-title="{{ __('conversations.close') }}">{{ __('conversations.close') }}</x-ui.button>
                                </div>
                            @endif

                            @if($canReply && $channel)
                                <form class="composer" wire:submit="send">
                                    <x-ui.field :label="__('conversations.reply')" for="conversation-reply" name="reply" class="sr-label">
                                        <textarea id="conversation-reply" wire:model="reply" rows="2" dir="auto" maxlength="{{ $maxReply }}" placeholder="{{ __('conversations.reply_placeholder') }}"></textarea>
                                    </x-ui.field>
                                    <div class="composer__actions">
                                        <span class="field-help">{{ __('conversations.reply_help') }}</span>
                                        <x-ui.button type="submit" icon="send" wire:loading.attr="data-loading" wire:target="send">{{ __('ui.actions.send') }}</x-ui.button>
                                    </div>
                                </form>
                            @elseif($canReply)
                                <p class="field-help">{{ __('conversations.reply_locked') }}</p>
                            @endif
                        </footer>
                    @endif
                @endif
            </section>
        </div>
    @endif
</div>
