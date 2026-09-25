{{--
    Reception flow: customer → services → optional stylist → visit (and a number).
    docs/17-QUEUE.md §22. Short on purpose — somebody is standing at the desk.

    Existing customers are listed with the presenter's MASKED contact
    (`contact_phone`); nothing here filters on it (ADR-042).
--}}
<div>
    @if($ready)
        <x-ui.drawer :title="__('manager_queue.walk_in.title')" close="close" submit="save">
            <div class="stack">
                <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />

                @if(count($branches) > 1)
                    <x-ui.field :label="__('manager_queue.walk_in.branch')" for="walk-in-branch" name="branch" required>
                        <select id="walk-in-branch" wire:model.live="branch">
                            @foreach($branches as $option)
                                <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif

                <fieldset class="form-section stack stack--sm">
                    <legend>{{ __('manager_queue.walk_in.customer') }}</legend>

                    @if($customer !== '')
                        <div class="walk-in-chosen">
                            <span class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($customerName, 0, 1)) }}</span>
                            <strong>{{ $customerName }}</strong>
                            <button type="button" class="text-button" wire:click="clearCustomer">{{ __('manager_queue.walk_in.change') }}</button>
                        </div>
                    @else
                        @if($canSearch)
                            <div class="field">
                                <label for="walk-in-search">{{ __('manager_queue.walk_in.search') }}</label>
                                <div class="search-input">
                                    <x-ui.icon name="search" />
                                    <input id="walk-in-search" type="search" wire:model.live.debounce.350ms="search" autocomplete="off" placeholder="{{ __('manager_queue.walk_in.search_placeholder') }}">
                                </div>
                            </div>
                            @if($matches !== [])
                                <ul class="walk-in-matches" role="list">
                                    @foreach($matches as $match)
                                        <li wire:key="match-{{ $match['uuid'] }}">
                                            <button type="button" wire:click="pick('{{ $match['uuid'] }}')">
                                                <span class="avatar avatar--sm" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) $match['name'], 0, 1)) }}</span>
                                                <span>{{ $match['name'] }}</span>
                                                @if($match['contact_phone'])<span class="cell-sub tabular" dir="ltr">{{ $match['contact_phone'] }}</span>@endif
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @elseif(mb_strlen(trim($search)) >= 2)
                                <p class="field-help">{{ __('manager_queue.walk_in.no_match') }}</p>
                            @endif
                            <p class="divider-label"><span>{{ __('manager_queue.walk_in.or_new') }}</span></p>
                        @endif

                        <x-ui.field :label="__('manager_queue.walk_in.name')" for="walk-in-name" name="name" required>
                            <input id="walk-in-name" type="text" wire:model="name" maxlength="190" autocomplete="off">
                        </x-ui.field>
                        <x-ui.phone number="phone" country="phoneCountry" id="walk-in-phone" :help="__('manager_queue.walk_in.phone_help')" />
                    @endif
                </fieldset>

                <fieldset class="field">
                    <legend>{{ __('manager_queue.walk_in.services') }}<span class="required" aria-hidden="true">*</span></legend>
                    @if($serviceOptions === [])
                        <p class="field-help">{{ __('manager_queue.walk_in.no_services') }}</p>
                    @else
                        <div class="chip-select walk-in-services">
                            @foreach($serviceOptions as $service)
                                <label class="chip-toggle" wire:key="svc-{{ $service['uuid'] }}">
                                    <input type="checkbox" value="{{ $service['uuid'] }}" wire:model.live="services">
                                    <span>{{ $service['name'] }} <small class="muted">{{ __('manager_queue.minutes', ['minutes' => $service['duration']]) }}</small></span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                    @error('services')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                </fieldset>

                @if($serviceOptions !== [])
                    <x-ui.field :label="__('manager_queue.walk_in.employee')" for="walk-in-employee" :help="$services === [] ? __('manager_queue.walk_in.employee_first') : null">
                        <select id="walk-in-employee" wire:model="employee" @disabled($services === [])>
                            <option value="">{{ __('manager_queue.walk_in.anyone') }}</option>
                            @foreach($employeeOptions as $option)
                                <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif

                @if($queueAvailable)
                    <div class="form-section stack stack--sm">
                        <label class="check-row">
                            <input type="checkbox" wire:model.live="ticket">
                            <span>{{ __('manager_queue.walk_in.give_number') }}</span>
                        </label>
                        @if($ticket)
                            <div class="segmented" role="group" aria-label="{{ __('manager_queue.actions.priority') }}">
                                @foreach(['normal' => 0, 'high' => 10, 'urgent' => 20] as $level => $value)
                                    <button type="button" wire:click="$set('priority', {{ $value }})" aria-pressed="{{ $priority === $value ? 'true' : 'false' }}">{{ __('manager_queue.priority.'.$level) }}</button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            <x-slot:footer>
                @if($canPrint && $ticket)
                    <label class="check-row queue-autoprint" x-data="queueAutoPrint" title="{{ __('manager_queue.walk_in.auto_print_hint') }}">
                        <input type="checkbox" x-model="enabled">
                        <span>{{ __('manager_queue.walk_in.auto_print') }}</span>
                    </label>
                @endif
                <span class="drawer__spacer"></span>
                <x-ui.button variant="ghost" wire:click="close">{{ __('ui.actions.cancel') }}</x-ui.button>
                <x-ui.button type="submit" icon="check" wire:loading.attr="data-loading" wire:target="save">{{ $queueAvailable && $ticket ? __('manager_queue.walk_in.submit_ticket') : __('manager_queue.walk_in.submit_visit') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.drawer>
    @endif
</div>
