{{--
    One ticket: its history (queue_ticket_events — domain data, not the audit
    log) and the decisions that need a reason or a destination. Every flag comes
    from QueueBoardView; every button is an Action.
--}}
<div>
    @if($detail)
        <x-ui.drawer :title="__('manager_queue.panel.title', ['number' => $detail['number']])" :description="$detail['customer'] ?? __('manager_queue.card.guest')" close="close">
            <div class="stack">
                <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />

                <div class="cluster">
                    <x-ui.status :value="$detail['state']" :label="__('labels.ticket_state.'.$detail['state'])" />
                    @if($detail['priority_level'] !== 'normal')
                        <span class="badge" data-tone="{{ $detail['priority_level'] === 'urgent' ? 'danger' : 'warning' }}">{{ __('manager_queue.priority.'.$detail['priority_level']) }}</span>
                    @endif
                    @if($detail['walk_in'])<span class="badge" data-tone="neutral">{{ __('manager_queue.card.walk_in') }}</span>@endif
                </div>

                <dl class="kv-grid">
                    <div><dt>{{ __('manager_queue.panel.service') }}</dt><dd>{{ $detail['service'] ?? '—' }}</dd></div>
                    <div><dt>{{ __('manager_queue.panel.employee') }}</dt><dd>{{ $detail['employee'] ?? __('manager_queue.panel.anyone') }}</dd></div>
                    <div><dt>{{ __('manager_queue.panel.destination') }}</dt><dd>@if($detail['destination'])<span dir="ltr">{{ $detail['destination']['code'] }}</span> · {{ $detail['destination']['name'] }}@else{{ $detail['department'] ?? '—' }}@endif</dd></div>
                    <div><dt>{{ __('ui.fields.branch') }}</dt><dd>{{ $detail['branch'] ?? '—' }}</dd></div>
                    <div><dt>{{ __('manager_queue.panel.issued') }}</dt><dd class="tabular">{{ $detail['issued'] ?? '—' }}</dd></div>
                    <div><dt>{{ __('manager_queue.panel.first_call') }}</dt><dd class="tabular">{{ $detail['first_called'] ?? '—' }}@if($detail['waited_minutes'] !== null) <span class="cell-sub">{{ __('manager_queue.panel.waited', ['minutes' => $detail['waited_minutes']]) }}</span>@endif</dd></div>
                    <div><dt>{{ __('manager_queue.panel.calls') }}</dt><dd class="tabular">{{ $detail['call_count'] }}@if($detail['skip_count'] > 0) <span class="cell-sub">{{ __('manager_queue.card.skips', ['count' => $detail['skip_count']]) }}</span>@endif</dd></div>
                    @if($detail['hold_reason'])
                        <div><dt>{{ __('manager_queue.panel.hold_reason') }}</dt><dd>{{ $detail['hold_reason'] }}</dd></div>
                    @endif
                    @if($detail['close_reason'])
                        <div><dt>{{ __('manager_queue.panel.close_reason') }}</dt><dd>{{ $detail['close_reason'] }}</dd></div>
                    @endif
                </dl>

                {{-- Quick decisions: one press each. --}}
                <div class="cluster">
                    @if($detail['can']['call'] || $detail['can']['recall'])
                        <x-ui.button size="sm" icon="megaphone" wire:click="showForm('call')">{{ $detail['can']['recall'] ? __('manager_queue.actions.recall') : __('manager_queue.actions.call_to') }}</x-ui.button>
                    @endif
                    @if($detail['can']['skip'])
                        <x-ui.button size="sm" variant="secondary" wire:click="skip" wire:loading.attr="data-loading" wire:target="skip">{{ __('manager_queue.actions.no_answer') }}</x-ui.button>
                    @endif
                    @if($detail['can']['resume'])
                        <x-ui.button size="sm" icon="undo" wire:click="resume" wire:loading.attr="data-loading" wire:target="resume">{{ __('manager_queue.actions.resume') }}</x-ui.button>
                    @endif
                    @if($detail['can']['hold'])
                        <x-ui.button size="sm" variant="secondary" icon="pause" wire:click="showForm('hold')">{{ __('manager_queue.actions.hold') }}</x-ui.button>
                    @endif
                    @if($detail['can']['transfer'])
                        <x-ui.button size="sm" variant="secondary" icon="move" wire:click="showForm('transfer')">{{ __('manager_queue.actions.transfer') }}</x-ui.button>
                    @endif
                    @if($detail['can']['priority'])
                        <x-ui.button size="sm" variant="secondary" icon="flag" wire:click="showForm('priority')">{{ __('manager_queue.actions.priority') }}</x-ui.button>
                    @endif
                    @if($detail['can']['print'])
                        <a class="button button--secondary button--sm" href="{{ route('center.queue.ticket', ['uuid' => $detail['uuid']]) }}" target="_blank" rel="noopener"><x-ui.icon name="print" size="16" />{{ __('manager_queue.actions.print') }}</a>
                    @endif
                </div>

                @if($form !== '')
                    <form class="drawer-section stack stack--sm queue-form" @if($submit) wire:submit="{{ $submit }}" @else x-on:submit.prevent @endif>
                        <h3>{{ __('manager_queue.forms.'.$form.'.title') }}</h3>
                        @if(in_array($form, ['cancel', 'abandon'], true))
                            <p class="field-help">{{ __('manager_queue.forms.'.$form.'.help') }}</p>
                        @endif

                        @if(in_array($form, ['call', 'transfer'], true))
                            <x-ui.field :label="__('manager_queue.forms.destination')" for="panel-point">
                                <select id="panel-point" wire:model="point">
                                    <option value="">{{ $form === 'call' ? __('manager_queue.forms.no_destination') : __('manager_queue.forms.keep_destination') }}</option>
                                    @foreach($points as $option)
                                        <option value="{{ $option['uuid'] }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                        @endif

                        @if($form === 'transfer' && $departments !== [])
                            <x-ui.field :label="__('manager_queue.filters.department')" for="panel-department">
                                <select id="panel-department" wire:model="department">
                                    <option value="">{{ __('manager_queue.forms.keep_department') }}</option>
                                    @foreach($departments as $option)
                                        <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                        @endif

                        @if($form === 'priority')
                            <div class="segmented" role="group" aria-label="{{ __('manager_queue.actions.priority') }}">
                                @foreach(['normal' => 0, 'high' => 10, 'urgent' => 20] as $level => $value)
                                    <button type="button" wire:click="$set('priority', {{ $value }})" aria-pressed="{{ $priority === $value ? 'true' : 'false' }}">{{ __('manager_queue.priority.'.$level) }}</button>
                                @endforeach
                            </div>
                        @endif

                        @if($form !== 'call')
                            <x-ui.field :label="__('manager_queue.forms.reason')" for="panel-reason" name="reason" :help="in_array($form, ['cancel', 'abandon'], true) ? null : __('ui.states.optional')">
                                <input id="panel-reason" type="text" wire:model="reason" maxlength="190" autocomplete="off">
                            </x-ui.field>
                        @endif

                        <div class="form-actions">
                            <x-ui.button variant="ghost" wire:click="cancelForm">{{ __('ui.actions.cancel') }}</x-ui.button>
                            @if(in_array($form, ['cancel', 'abandon'], true))
                                <x-ui.button variant="danger" wire:click="{{ $form === 'cancel' ? 'cancelTicket' : 'abandon' }}" wire:loading.attr="data-loading" wire:target="cancelTicket,abandon"
                                    wire:confirm="{{ __('manager_queue.forms.'.$form.'.confirm', ['number' => $detail['number']]) }}"
                                    data-confirm-title="{{ __('manager_queue.forms.'.$form.'.title') }}" data-confirm-tone="danger">{{ __('manager_queue.forms.'.$form.'.submit') }}</x-ui.button>
                            @else
                                <x-ui.button type="submit" wire:loading.attr="data-loading">{{ __('manager_queue.forms.'.$form.'.submit') }}</x-ui.button>
                            @endif
                        </div>
                    </form>
                @endif

                <section class="drawer-section">
                    <h3>{{ __('manager_queue.panel.history') }}</h3>
                    @if($detail['history'] === [])
                        <p class="muted">{{ __('manager_queue.panel.no_history') }}</p>
                    @else
                        <ol class="timeline">
                            @foreach($detail['history'] as $event)
                                <li class="timeline__item">
                                    <span class="timeline__dot" data-event="{{ $event['type'] }}"><x-ui.icon :name="['issued' => 'ticket', 'called' => 'megaphone', 'recalled' => 'megaphone', 'skipped' => 'user-x', 'held' => 'pause', 'resumed' => 'undo', 'transferred' => 'move', 'serving_started' => 'play', 'completed' => 'check', 'cancelled' => 'x-circle', 'priority_changed' => 'flag'][$event['type']] ?? 'dot'" size="12" /></span>
                                    <div class="timeline__body">
                                        <p><strong>{{ __('manager_queue.events.'.$event['type']) }}</strong>@if($event['destination']) · <span dir="ltr">{{ $event['destination'] }}</span>@endif</p>
                                        @if($event['reason'])<p class="muted">{{ $event['reason'] }}</p>@endif
                                        <p class="timeline__meta"><span class="tabular">{{ $event['at'] }}</span>@if($event['actor']) · {{ $event['actor'] }}@endif</p>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>
            </div>

            @if($detail['can']['cancel'] || $detail['can']['abandon'])
                <x-slot:footer>
                    @if($detail['can']['abandon'])
                        <x-ui.button size="sm" variant="danger-soft" icon="user-x" wire:click="showForm('abandon')">{{ __('manager_queue.actions.customer_left') }}</x-ui.button>
                    @endif
                    @if($detail['can']['cancel'])
                        <x-ui.button size="sm" variant="ghost" icon="x-circle" wire:click="showForm('cancel')">{{ __('manager_queue.actions.cancel_ticket') }}</x-ui.button>
                    @endif
                    <span class="drawer__spacer"></span>
                    <x-ui.button size="sm" variant="secondary" wire:click="close">{{ __('ui.actions.close') }}</x-ui.button>
                </x-slot:footer>
            @endif
        </x-ui.drawer>
    @endif
</div>
