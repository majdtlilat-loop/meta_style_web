{{--
    One visit. Planned beside actual (docs/16 §18): who was booked, who is
    doing it, which rooms were actually used and when. Notes are filtered by
    visibility before they reach this template; flags from JourneyBoardView.
--}}
<div>
    @if($panel)
        <x-ui.drawer :title="$panel['customer'] ?? __('manager_queue.card.guest')" :description="$panel['source'] === 'walk_in' ? __('manager_visits.panel.walk_in_arrived', ['time' => $panel['arrived'] ?? '—']) : __('manager_visits.panel.booked_for', ['time' => $panel['time'] ?? '—', 'reference' => $panel['reference'] ?? ''])" close="close" size="lg">
            <div class="stack">
                <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />

                <div class="cluster">
                    @if(isset($panel['journey_status']))
                        <x-ui.status :value="$panel['journey_status']" :label="__('labels.journey_status.'.$panel['journey_status'])" />
                    @endif
                    @if($panel['appointment_status'])
                        <x-ui.status :value="$panel['appointment_status']" :label="__('labels.appointment_status.'.$panel['appointment_status'])" :dot="false" />
                    @endif
                    @if($panel['arrived'])<span class="badge">{{ __('manager_visits.panel.arrived', ['time' => $panel['arrived']]) }}</span>@endif
                    @if($panel['completed'] ?? null)<span class="badge" data-tone="success">{{ __('manager_visits.panel.completed_at', ['time' => $panel['completed']]) }}</span>@endif
                    @if($panel['aborted'] ?? null)<span class="badge" data-tone="neutral">{{ __('manager_visits.panel.left_at', ['time' => $panel['aborted']]) }}</span>@endif
                </div>

                @if(! empty($panel['abort_reason']))
                    <x-ui.notice :message="__('manager_visits.panel.left_reason', ['reason' => $panel['abort_reason']])" tone="info" />
                @endif

                <section class="stack stack--sm" aria-labelledby="visit-services">
                    <h3 id="visit-services" class="section-heading">{{ __('manager_visits.panel.services') }}</h3>
                    <ol class="visit-stages">
                        @foreach($panel['stages'] as $stage)
                            <li class="visit-stage" data-status="{{ $stage['status'] }}" wire:key="stage-{{ $stage['uuid'] }}">
                                <header class="visit-stage__head">
                                    <span class="visit-stage__position">{{ $stage['position'] }}</span>
                                    <div class="visit-stage__title">
                                        <strong>{{ $stage['service'] }}</strong>
                                        @if($stage['department'])<span class="cell-sub">{{ $stage['department'] }}</span>@endif
                                    </div>
                                    <x-ui.status :value="$stage['status']" :label="__('labels.stage_status.'.$stage['status'])" />
                                </header>

                                <dl class="visit-stage__facts">
                                    <div><dt>{{ __('manager_visits.panel.booked_with') }}</dt><dd>{{ $stage['booked_employee'] ?? __('manager_visits.panel.anyone') }}</dd></div>
                                    <div><dt>{{ __('manager_visits.panel.with') }}</dt><dd>{{ $stage['employee'] ?? '—' }}@if($stage['reassigned']) <span class="badge" data-tone="warning">{{ __('manager_visits.panel.reassigned') }}</span>@endif</dd></div>
                                    @if($stage['planned'])<div><dt>{{ __('manager_visits.panel.planned') }}</dt><dd class="tabular">{{ $stage['planned'] }}</dd></div>@endif
                                    <div><dt>{{ __('manager_visits.panel.actual') }}</dt><dd class="tabular">{{ $stage['started'] ?? '—' }}@if($stage['finished']) → {{ $stage['finished'] }}@endif @if($stage['elapsed'] !== null)<span class="cell-sub">{{ __('manager_visits.card.elapsed', ['minutes' => $stage['elapsed'], 'expected' => $stage['expected']]) }}</span>@endif</dd></div>
                                </dl>

                                @if($stage['resources'] !== [])
                                    <p class="chip-list">
                                        @foreach($stage['resources'] as $usage)
                                            <span class="chip @unless($usage['open']) chip--muted @endunless"><x-ui.icon name="resources" size="12" /> {{ $usage['name'] }} <span class="tabular" dir="ltr">{{ $usage['from'] }}–{{ $usage['until'] ?? '…' }}</span></span>
                                        @endforeach
                                    </p>
                                @endif

                                @if($stage['skip_reason'])
                                    <p class="cell-sub">{{ __('manager_visits.panel.skipped_because', ['reason' => $stage['skip_reason']]) }}</p>
                                @endif

                                <div class="cluster cluster--tight visit-stage__actions">
                                    @if($stage['can']['start'])
                                        <x-ui.button size="sm" icon="play" wire:click="start('{{ $stage['uuid'] }}')" wire:loading.attr="data-loading" wire:target="start('{{ $stage['uuid'] }}')">{{ __('manager_visits.actions.start') }}</x-ui.button>
                                    @endif
                                    @if($stage['can']['finish'])
                                        <x-ui.button size="sm" icon="check" wire:click="finish('{{ $stage['uuid'] }}')" wire:loading.attr="data-loading" wire:target="finish('{{ $stage['uuid'] }}')">{{ __('manager_visits.actions.finish') }}</x-ui.button>
                                    @endif
                                    @if($stage['can']['handoff'])
                                        <x-ui.button size="sm" variant="secondary" icon="arrow-right" wire:click="showForm('handoff', '{{ $stage['uuid'] }}')">{{ __('manager_visits.actions.hand_on') }}</x-ui.button>
                                    @endif
                                    @if($stage['can']['ticket'])
                                        <x-ui.button size="sm" variant="secondary" icon="ticket" wire:click="issueTicket('{{ $stage['uuid'] }}')" wire:loading.attr="data-loading" wire:target="issueTicket('{{ $stage['uuid'] }}')">{{ __('manager_visits.actions.give_number') }}</x-ui.button>
                                    @endif
                                    @if($stage['can']['reassign'])
                                        <x-ui.button size="sm" variant="ghost" icon="users" wire:click="showForm('reassign', '{{ $stage['uuid'] }}')">{{ __('manager_visits.actions.reassign') }}</x-ui.button>
                                    @endif
                                    @if($stage['can']['swap'] && $stage['resources'] !== [])
                                        <x-ui.button size="sm" variant="ghost" icon="resources" wire:click="showForm('swap', '{{ $stage['uuid'] }}')">{{ __('manager_visits.actions.swap') }}</x-ui.button>
                                    @endif
                                    @if($stage['can']['skip'])
                                        <x-ui.button size="sm" variant="ghost" wire:click="showForm('skip', '{{ $stage['uuid'] }}')">{{ __('manager_visits.actions.skip') }}</x-ui.button>
                                    @endif
                                    @if($stage['can']['note'])
                                        <x-ui.button size="sm" variant="ghost" icon="file-text" wire:click="showForm('note', '{{ $stage['uuid'] }}')">{{ __('manager_visits.actions.note') }}</x-ui.button>
                                    @endif
                                </div>

                                @if($form !== '' && $this->stage === $stage['uuid'])
                                    @include('livewire.center.journey.visit-form', ['stageData' => $stage, 'choices' => $choices, 'visibilities' => $panel['panel']['note_visibility'] ?? ['internal']])
                                @endif

                                @if($canViewNotes && $stage['notes'] !== [])
                                    <ul class="note-list visit-notes">
                                        @foreach($stage['notes'] as $note)
                                            <li wire:key="note-{{ $note['uuid'] }}" @if($note['visibility'] === 'manager_only') data-restricted @endif>
                                                <p class="prewrap">{{ $note['body'] }}</p>
                                                <p class="cell-sub">
                                                    {{ $note['author'] ?? '—' }} · <span class="tabular">{{ $note['at'] }}</span> ·
                                                    {{ __('manager_visits.visibility.'.$note['visibility']) }}
                                                    @if($stage['can']['note'])
                                                        <button type="button" class="text-button text-button--danger" wire:click="deleteNote('{{ $stage['uuid'] }}', '{{ $note['uuid'] }}')"
                                                            wire:confirm="{{ __('manager_visits.notes.delete_confirm') }}" data-confirm-title="{{ __('manager_visits.notes.delete') }}" data-confirm-tone="danger">{{ __('ui.actions.delete') }}</button>
                                                    @endif
                                                </p>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </section>

                @if(in_array($form, ['leave', 'cancel'], true))
                    <form class="drawer-section stack stack--sm queue-form" x-on:submit.prevent>
                        <h3>{{ __('manager_visits.forms.'.$form.'.title') }}</h3>
                        <p class="field-help">{{ __('manager_visits.forms.'.$form.'.help') }}</p>
                        <x-ui.field :label="__('manager_queue.forms.reason')" for="visit-reason" name="reason">
                            <input id="visit-reason" type="text" wire:model="reason" maxlength="190" autocomplete="off">
                        </x-ui.field>
                        <div class="form-actions">
                            <x-ui.button variant="ghost" wire:click="cancelForm">{{ __('ui.actions.cancel') }}</x-ui.button>
                            <x-ui.button variant="danger" wire:click="{{ $form === 'leave' ? 'leave' : 'cancelBooking' }}" wire:loading.attr="data-loading" wire:target="leave,cancelBooking"
                                wire:confirm="{{ __('manager_visits.forms.'.$form.'.confirm') }}" data-confirm-title="{{ __('manager_visits.forms.'.$form.'.title') }}" data-confirm-tone="danger">{{ __('manager_visits.forms.'.$form.'.submit') }}</x-ui.button>
                        </div>
                    </form>
                @endif

                @if($panel['handoffs'] !== [])
                    <section class="drawer-section">
                        <h3>{{ __('manager_visits.panel.handoffs') }}</h3>
                        <ol class="timeline">
                            @foreach($panel['handoffs'] as $handoff)
                                <li class="timeline__item">
                                    <span class="timeline__dot"><x-ui.icon name="arrow-right" size="12" /></span>
                                    <div class="timeline__body">
                                        <p><strong>{{ $handoff['from'] ?? '—' }}</strong> → {{ $handoff['to'] ?? __('manager_visits.panel.handoff_end') }}</p>
                                        <p class="cell-sub">{{ $handoff['from_employee'] ?? '—' }} → {{ $handoff['to_employee'] ?? __('manager_visits.panel.anyone') }}</p>
                                        @if($handoff['note'])<p class="muted">{{ $handoff['note'] }}</p>@endif
                                        <p class="timeline__meta"><span class="tabular">{{ $handoff['at'] }}</span>@if($handoff['by']) · {{ $handoff['by'] }}@endif</p>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </section>
                @endif
            </div>

            <x-slot:footer>
                @if($panel['panel']['leave'] ?? false)
                    <x-ui.button size="sm" variant="danger-soft" icon="user-x" wire:click="showForm('leave')">{{ __('manager_visits.actions.left') }}</x-ui.button>
                @endif
                @if($panel['panel']['cancel_booking'] ?? false)
                    <x-ui.button size="sm" variant="ghost" icon="x-circle" wire:click="showForm('cancel')">{{ __('manager_visits.actions.cancel_booking') }}</x-ui.button>
                @endif
                <span class="drawer__spacer"></span>
                @if($canCheckout && $panel['journey_uuid'] && ($panel['journey_status'] ?? '') !== 'aborted')
                    <x-ui.button size="sm" variant="secondary" icon="pos" :href="route('center.pos', ['journey' => $panel['journey_uuid']])" wire:navigate>{{ __('manager_visits.actions.checkout') }}</x-ui.button>
                @endif
                @if($panel['panel']['complete'] ?? false)
                    <x-ui.button size="sm" icon="check-circle" wire:click="complete" wire:loading.attr="data-loading" wire:target="complete">{{ __('manager_visits.actions.complete') }}</x-ui.button>
                @endif
            </x-slot:footer>
        </x-ui.drawer>
    @endif
</div>
