@php
    use App\View\Label;

    $priorityTone = ['urgent' => 'danger', 'high' => 'warning', 'normal' => 'neutral', 'low' => 'neutral'];
    $dateTime = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->translatedFormat('j M Y, H:i') : '—';
    $size = function (int $bytes): string {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : number_format(max(1, (int) round($bytes / 1024))).' KB';
    };
@endphp

<div class="stack">
    <nav aria-label="Breadcrumb">
        <ol class="breadcrumbs">
            <li><a href="{{ route('superadmin.support.index') }}" wire:navigate>{{ __('sadmin_support.show.back') }}</a></li>
            <li><span aria-current="page" dir="ltr">{{ $ticket->reference }}</span></li>
        </ol>
    </nav>

    <header class="page-header">
        <div>
            <h1>{{ $ticket->subject }}</h1>
            <div class="page-header__meta">
                <x-ui.status :value="$ticket->status" :label="Label::for('ticket_status', $ticket->status)" />
                <x-ui.status :tone="$priorityTone[$ticket->priority] ?? 'neutral'" :label="Label::for('ticket_priority', $ticket->priority)" :dot="false" />
                @if($ticket->tenant)
                    <a class="cell-link" href="{{ route('superadmin.centers.show', ['tenant' => $ticket->tenant_id, 'tab' => 'support']) }}" wire:navigate>{{ $ticket->tenant->name }}</a>
                @endif
            </div>
        </div>
    </header>

    <x-ui.flash />

    <div class="record-grid">
        <section class="card card--flush" aria-labelledby="conversation-title">
            <header class="card__header"><h2 id="conversation-title">{{ __('sadmin_support.show.conversation') }}</h2></header>

            @if($ticket->messages->isEmpty())
                <x-ui.empty-state compact icon="message" :title="__('sadmin_support.show.no_messages')" />
            @else
                <ol class="thread">
                    @foreach($ticket->messages as $message)
                        <li class="thread__item" @if($message->is_internal) data-internal @endif data-author="{{ $message->author_type }}" wire:key="message-{{ $message->id }}">
                            <span class="avatar avatar--sm" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) $message->author_label, 0, 1)) }}</span>
                            <div class="thread__bubble">
                                <div class="thread__meta">
                                    <strong>{{ $message->author_label }}</strong>
                                    <span class="muted">{{ __('sadmin_support.author_types.'.$message->author_type) }}</span>
                                    @if($message->is_internal)<x-ui.status tone="warning" :label="__('sadmin_support.show.internal')" :dot="false" />@endif
                                    <time class="muted" datetime="{{ $message->created_at?->toIso8601String() }}">{{ $dateTime($message->created_at) }}</time>
                                </div>
                                <p class="prewrap">{{ $message->body }}</p>
                                @if($message->attachments->isNotEmpty())
                                    <ul class="attachment-list">
                                        @foreach($message->attachments as $file)
                                            <li>
                                                <a href="{{ route('superadmin.support.attachment', ['ticket' => $ticket->uuid, 'attachment' => $file->uuid]) }}" aria-label="{{ __('sadmin_support.show.download', ['name' => $file->original_name]) }}">
                                                    <x-ui.icon name="paperclip" size="16" /><span dir="ltr">{{ $file->original_name }}</span><span class="muted">{{ $size((int) $file->size) }}</span>
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif

            @if($canManage)
                <form class="composer" wire:submit="reply" @if($internal) data-internal @endif>
                    <div class="segmented" role="group" aria-label="{{ __('sadmin_support.show.reply') }}">
                        <button type="button" wire:click="$set('internal', false)" aria-pressed="{{ $internal ? 'false' : 'true' }}"><x-ui.icon name="reply" size="16" />{{ __('sadmin_support.show.reply') }}</button>
                        <button type="button" wire:click="$set('internal', true)" aria-pressed="{{ $internal ? 'true' : 'false' }}"><x-ui.icon name="lock" size="16" />{{ __('sadmin_support.show.internal') }}</button>
                    </div>
                    <x-ui.field :label="$internal ? __('sadmin_support.show.internal') : __('sadmin_support.show.reply')" for="reply-body" name="body" :help="$internal ? __('sadmin_support.show.internal_help') : null" class="sr-label">
                        <textarea id="reply-body" rows="4" wire:model="body" maxlength="5000" placeholder="{{ $internal ? __('sadmin_support.show.note_placeholder') : __('sadmin_support.show.reply_placeholder') }}" required></textarea>
                    </x-ui.field>
                    <div class="composer__actions">
                        <div class="field composer__file">
                            <label for="reply-attachment" class="button button--ghost button--sm"><x-ui.icon name="paperclip" size="16" />{{ __('sadmin_support.show.attachment') }}</label>
                            <input id="reply-attachment" class="sr-only" type="file" wire:model="attachment" accept=".pdf,.png,.jpg,.jpeg,.txt">
                            @if($attachment)
                                <span class="chip" dir="ltr">{{ $attachment->getClientOriginalName() }}</span>
                            @else
                                <span class="field-help">{{ __('sadmin_support.show.attachment_help') }}</span>
                            @endif
                            @error('attachment')<p class="error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="reply,attachment">
                            <x-ui.icon :name="$internal ? 'save' : 'send'" size="16" />{{ $internal ? __('sadmin_support.show.save_note') : __('sadmin_support.show.send') }}
                        </button>
                    </div>
                </form>
            @endif
        </section>

        <div class="stack">
            <x-ui.card :title="__('sadmin_support.show.details')">
                <dl class="summary-list">
                    <div><dt>{{ __('sadmin_support.show.reference') }}</dt><dd dir="ltr">{{ $ticket->reference }}</dd></div>
                    <div><dt>{{ __('sadmin_support.show.center') }}</dt><dd>{{ $ticket->tenant?->name ?? '—' }}</dd></div>
                    <div><dt>{{ __('sadmin_support.show.opened') }}</dt><dd>{{ $dateTime($ticket->created_at) }}</dd></div>
                    <div><dt>{{ __('sadmin_support.show.opened_by') }}</dt><dd>{{ $ticket->created_by_label }}</dd></div>
                    @if($ticket->resolved_at)<div><dt>{{ __('sadmin_support.show.resolved') }}</dt><dd>{{ $dateTime($ticket->resolved_at) }}</dd></div>@endif
                    @if($ticket->closed_at)<div><dt>{{ __('sadmin_support.show.closed') }}</dt><dd>{{ $dateTime($ticket->closed_at) }}</dd></div>@endif
                </dl>
            </x-ui.card>

            @if($canManage)
                <x-ui.card :title="__('sadmin_support.show.manage')">
                    <form class="stack stack--sm" wire:submit="updateState">
                        <x-ui.field :label="__('sadmin_support.show.status')" for="ticket-status" name="status">
                            <select id="ticket-status" wire:model.live="status">
                                @foreach(\App\Livewire\Sadmin\Support\Index::STATUSES as $value)
                                    <option value="{{ $value }}">{{ Label::for('ticket_status', $value) }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field :label="__('sadmin_support.show.priority')" for="ticket-priority" name="priority">
                            <select id="ticket-priority" wire:model.live="priority">
                                @foreach(\App\Livewire\Sadmin\Support\Index::PRIORITIES as $value)
                                    <option value="{{ $value }}">{{ Label::for('ticket_priority', $value) }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field :label="__('sadmin_support.show.assignee')" for="ticket-assignee" name="assigneeId">
                            <select id="ticket-assignee" wire:model.live="assigneeId">
                                <option value="">{{ __('sadmin_support.show.unassigned') }}</option>
                                @foreach($agents as $agent)
                                    <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        @if($stateChanged)
                            <x-ui.field :label="__('sadmin_support.show.reason')" for="ticket-reason" name="reason" required>
                                <textarea id="ticket-reason" rows="2" wire:model="reason" required></textarea>
                            </x-ui.field>
                            <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="updateState">{{ __('sadmin_support.show.update') }}</button>
                        @endif
                    </form>
                </x-ui.card>
            @endif
        </div>
    </div>
</div>
