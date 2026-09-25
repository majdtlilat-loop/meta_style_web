{{--
    One of the center's own tickets (App\Livewire\Manager\Support\Ticket).
    Internal platform notes never reach this page.
--}}
<div class="stack support-ticket">
    <nav aria-label="{{ __('manager_support.ticket.breadcrumb') }}">
        <ol class="breadcrumbs">
            <li><a href="{{ $back }}" wire:navigate>{{ __('manager_support.title') }}</a></li>
            <li><span aria-current="page" dir="ltr">{{ $ticket['reference'] }}</span></li>
        </ol>
    </nav>

    <header class="page-header">
        <div>
            <h1>{{ $ticket['subject'] }}</h1>
            <div class="page-header__meta">
                <x-ui.status :value="$ticket['status']" :label="$ticket['status_label']" />
                <x-ui.status :tone="$ticket['priority_tone']" :label="$ticket['priority_label']" :dot="false" />
                <span class="muted" dir="ltr">{{ $ticket['reference'] }}</span>
            </div>
        </div>
        @if($canClose || $canReopen)
            <div class="page-header__actions">
                @if($canReopen)
                    <button class="button button--secondary" type="button" wire:click="reopen" wire:loading.attr="data-loading" wire:target="reopen"><x-ui.icon name="refresh" size="16" />{{ __('manager_support.actions.reopen') }}</button>
                @endif
                @if($canClose)
                    <button class="button button--secondary" type="button" wire:click="close"
                            wire:confirm="{{ __('manager_support.confirm.close_body') }}"
                            data-confirm-title="{{ __('manager_support.confirm.close_title') }}"
                            data-confirm-label="{{ __('manager_support.actions.close') }}"
                            data-confirm-tone="danger"
                            wire:loading.attr="data-loading" wire:target="close"><x-ui.icon name="check-circle" size="16" />{{ __('manager_support.actions.close') }}</button>
                @endif
            </div>
        @endif
    </header>

    <x-ui.flash />
    @if($error !== '')
        <div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $error }}</p></div>
    @endif

    <div class="record-grid">
        <section class="card card--flush" aria-labelledby="support-conversation-title">
            <header class="card__header"><h2 id="support-conversation-title">{{ __('manager_support.ticket.conversation') }}</h2></header>

            @if($messages === [])
                <x-ui.empty-state compact icon="message" :title="__('manager_support.ticket.no_messages')" />
            @else
                <ol class="thread" aria-live="polite">
                    @foreach($messages as $message)
                        <li class="thread__item" data-author="{{ $message['side'] }}" wire:key="message-{{ $message['id'] }}">
                            <span class="avatar avatar--sm" aria-hidden="true">{{ $message['initial'] }}</span>
                            <div class="thread__bubble">
                                <div class="thread__meta">
                                    <strong>{{ $message['author'] }}</strong>
                                    <span class="muted">{{ $message['origin'] }}</span>
                                    @if($message['time'])
                                        <time class="muted" datetime="{{ $message['time']['iso'] }}" title="{{ $message['time']['full'] }}">{{ $message['time']['relative'] }}</time>
                                    @endif
                                </div>
                                <p class="prewrap">{{ $message['body'] }}</p>
                                @if($message['attachments'] !== [])
                                    <ul class="attachment-list">
                                        @foreach($message['attachments'] as $file)
                                            <li>
                                                @if($file['href'])
                                                    <a href="{{ $file['href'] }}" aria-label="{{ __('manager_support.ticket.download', ['name' => $file['name']]) }}">
                                                        <x-ui.icon name="paperclip" size="16" /><span dir="ltr">{{ $file['name'] }}</span><span class="muted">{{ $file['size'] }}</span>
                                                    </a>
                                                @else
                                                    <span><x-ui.icon name="paperclip" size="16" /><span dir="ltr">{{ $file['name'] }}</span></span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif

            @if($canReply)
                <form class="composer" wire:submit="reply">
                    <x-ui.field :label="__('manager_support.fields.reply')" for="support-reply" name="body" class="sr-label">
                        <textarea id="support-reply" rows="4" wire:model="body" maxlength="5000" placeholder="{{ __('manager_support.ticket.reply_placeholder') }}" required></textarea>
                    </x-ui.field>
                    @include('livewire.manager.support.attachments-field', ['inputId' => 'support-reply-files'])
                    <div class="composer__actions">
                        <span class="field-help">{{ $ticket['status'] === 'resolved' ? __('manager_support.ticket.reply_reopens') : '' }}</span>
                        <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="reply,files"><x-ui.icon name="send" size="16" />{{ __('manager_support.actions.send') }}</button>
                    </div>
                </form>
            @elseif($ticket['status'] === 'closed')
                <p class="support-ticket__closed"><x-ui.icon name="lock" size="16" />{{ __('manager_support.ticket.closed_note') }}</p>
            @endif
        </section>

        <x-ui.card :title="__('manager_support.ticket.details')">
            <dl class="summary-list">
                <div><dt>{{ __('manager_support.ticket.reference') }}</dt><dd dir="ltr">{{ $ticket['reference'] }}</dd></div>
                <div><dt>{{ __('manager_support.ticket.opened_by') }}</dt><dd>{{ $ticket['opened_by'] }}</dd></div>
                @if($ticket['opened'])<div><dt>{{ __('manager_support.ticket.opened') }}</dt><dd><time datetime="{{ $ticket['opened']['iso'] }}">{{ $ticket['opened']['full'] }}</time></dd></div>@endif
                @if($ticket['resolved'])<div><dt>{{ __('manager_support.ticket.resolved') }}</dt><dd><time datetime="{{ $ticket['resolved']['iso'] }}">{{ $ticket['resolved']['full'] }}</time></dd></div>@endif
                @if($ticket['closed'])<div><dt>{{ __('manager_support.ticket.closed') }}</dt><dd><time datetime="{{ $ticket['closed']['iso'] }}">{{ $ticket['closed']['full'] }}</time></dd></div>@endif
            </dl>
            <x-slot:footer><span class="field-help">{{ __('manager_support.ticket.separate_note') }}</span></x-slot:footer>
        </x-ui.card>
    </div>
</div>
