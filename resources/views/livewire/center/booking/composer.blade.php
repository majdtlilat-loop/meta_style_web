{{--
    New booking. Every choice offered here is a list the engine would accept
    for being the right KIND of thing (BookingOptions); whether it is FREE is
    the engine's availability answer, and the booking itself is the engine's
    decision (docs/15-BOOKING.md §1). The one-time code appears in the done
    state of THIS drawer only (docs/24 §11).
--}}
<div>
@if($step === 'done' && $booked)
    <x-ui.drawer :title="__('manager_booking.composer.done_title')" :description="$booked['when']" close="close" size="lg">
        <div class="stack">
            <div class="bk-done">
                <span class="bk-done__icon" aria-hidden="true"><x-ui.icon name="check-circle" size="28" /></span>
                <div>
                    <strong>{{ __('manager_booking.composer.done_body', ['reference' => $booked['reference']]) }}</strong>
                    <p class="muted">{{ $booked['when'] }}</p>
                </div>
            </div>

            @if($issuedCode !== '')
                <div class="secret-card" role="status">
                    <div class="secret-card__head">
                        <span class="secret-card__icon" aria-hidden="true"><x-ui.icon name="key" /></span>
                        <div>
                            <strong>{{ __('manager_booking.code.title') }}</strong>
                            <p>{{ __('manager_booking.code.once') }}</p>
                        </div>
                    </div>
                    <div class="copy-field">
                        <code dir="ltr" class="bk-code">{{ $issuedCode }}</code>
                        <button class="button button--secondary button--sm" type="button" data-copy="{{ $issuedCode }}" data-copied="{{ __('ui.actions.copied') }}"><x-ui.icon name="copy" size="16" />{{ __('ui.actions.copy') }}</button>
                    </div>
                </div>
            @else
                <x-ui.notice tone="info" :message="__('manager_booking.code.replayed')" />
            @endif
        </div>
        <x-slot:footer>
            <button class="button button--ghost" type="button" wire:click="close">{{ __('ui.actions.close') }}</button>
            <span class="drawer__spacer"></span>
            <button class="button button--secondary" type="button" wire:click="bookAnother"><x-ui.icon name="plus" size="16" />{{ __('manager_booking.composer.another') }}</button>
            <button class="button" type="button" wire:click="openBooking">{{ __('manager_booking.composer.open_booking') }}</button>
        </x-slot:footer>
    </x-ui.drawer>
@else
    <x-ui.drawer :title="__('manager_booking.composer.title')" close="close" size="lg">
        <div class="stack bk-composer">
            <x-ui.notice tone="danger" :message="$error" dismiss="dismissNotice" />

            @if(count($branches) > 1)
                <x-ui.field :label="__('manager_booking.composer.branch')" for="bk-c-branch" required>
                    <select id="bk-c-branch" wire:model.live="branchUuid">
                        @foreach($branches as $option)
                            <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
            @endif

            {{-- ── Customer ─────────────────────────────────────────────── --}}
            <section class="drawer-section" aria-labelledby="bk-c-customer">
                <div class="cluster cluster--between">
                    <h3 id="bk-c-customer">{{ __('manager_booking.composer.customer') }}</h3>
                    @if($canSearchCustomers)
                        <div class="segmented" role="group" aria-label="{{ __('manager_booking.composer.customer') }}">
                            <button type="button" wire:click="useCustomerMode('existing')" aria-pressed="{{ $form->customerMode === 'existing' ? 'true' : 'false' }}">{{ __('manager_booking.composer.existing') }}</button>
                            <button type="button" wire:click="useCustomerMode('new')" aria-pressed="{{ $form->customerMode === 'new' ? 'true' : 'false' }}">{{ __('manager_booking.composer.new_customer') }}</button>
                        </div>
                    @endif
                </div>

                @if($form->customerMode === 'existing')
                    @if($form->customerUuid !== '')
                        <div class="bk-chosen">
                            <span class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($form->customerLabel, 0, 1)) }}</span>
                            <strong class="grow">{{ $form->customerLabel }}</strong>
                            <button class="button button--ghost button--sm" type="button" wire:click="clearCustomer">{{ __('manager_booking.composer.change') }}</button>
                        </div>
                    @else
                        <div class="field">
                            <label for="bk-c-search">{{ __('manager_booking.composer.find_customer') }}<span class="required" aria-hidden="true">*</span></label>
                            <div class="search-input">
                                <x-ui.icon name="search" />
                                <input id="bk-c-search" type="search" wire:model.live.debounce.300ms="form.customerSearch" autocomplete="off" maxlength="80"
                                       placeholder="{{ __('manager_booking.composer.find_placeholder') }}">
                            </div>
                            @error('form.customerUuid')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                        </div>
                        @if($customers !== [])
                            <ul class="bk-results" role="list" wire:loading.class="is-refreshing" wire:target="form.customerSearch">
                                @foreach($customers as $candidate)
                                    <li wire:key="cand-{{ $candidate['uuid'] }}">
                                        <button type="button" class="bk-results__item" wire:click="chooseCustomer('{{ $candidate['uuid'] }}')">
                                            <span class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($candidate['name'], 0, 1)) }}</span>
                                            <span class="grow">
                                                <strong>{{ $candidate['name'] }}</strong>
                                                @if($candidate['contact_phone'])<span class="cell-sub" dir="ltr">{{ $candidate['contact_phone'] }}</span>@endif
                                            </span>
                                            @foreach($candidate['tags'] as $tag)<span class="chip">{{ $tag }}</span>@endforeach
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @elseif($lookedUp)
                            <p class="muted bk-hint">{{ __('manager_booking.composer.no_match') }}
                                @if($canCreateCustomers)<button class="text-button" type="button" wire:click="useCustomerMode('new')">{{ __('manager_booking.composer.add_new') }}</button>@endif
                            </p>
                        @endif
                    @endif
                @else
                    <div class="form-grid">
                        <x-ui.field :label="__('manager_booking.composer.name')" for="bk-c-name" name="form.newName" required>
                            <input id="bk-c-name" type="text" wire:model.blur="form.newName" maxlength="190" autocomplete="off">
                        </x-ui.field>
                        <x-ui.phone number="form.newPhone" country="form.newCountry" :label="__('manager_booking.composer.phone')" required />
                    </div>
                    @if($phoneMatch)
                        <p class="bk-hint"><x-ui.icon name="info" size="14" />{{ __('manager_booking.composer.phone_known', ['name' => $phoneMatch]) }}</p>
                    @elseif(! $canCreateCustomers)
                        <p class="bk-hint muted"><x-ui.icon name="info" size="14" />{{ __('manager_booking.composer.no_create') }}</p>
                    @endif
                @endif
            </section>

            {{-- ── Services ─────────────────────────────────────────────── --}}
            <section class="drawer-section" aria-labelledby="bk-c-services">
                <h3 id="bk-c-services">{{ __('manager_booking.composer.services') }}</h3>

                @if($services === [])
                    <x-ui.empty-state compact icon="scissors" :title="__('manager_booking.composer.no_services')" :description="__('manager_booking.composer.no_services_body')" />
                @else
                    <ol class="bk-lines">
                        @foreach($lineOptions as $line)
                            <li class="bk-line" wire:key="line-{{ $line['index'] }}">
                                <div class="bk-line__head">
                                    <span class="bk-line__number tabular">{{ $line['index'] + 1 }}</span>
                                    <label class="sr-only" for="bk-c-service-{{ $line['index'] }}">{{ __('manager_booking.composer.service') }}</label>
                                    <select id="bk-c-service-{{ $line['index'] }}" class="grow" wire:model.live="form.lines.{{ $line['index'] }}.service">
                                        <option value="">{{ __('manager_booking.composer.choose_service') }}</option>
                                        @foreach($services as $option)
                                            <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                                        @endforeach
                                    </select>
                                    @if(count($lineOptions) > 1)
                                        <button class="icon-button icon-button--danger" type="button" wire:click="removeLine({{ $line['index'] }})" aria-label="{{ __('manager_booking.composer.remove_service') }}" title="{{ __('manager_booking.composer.remove_service') }}"><x-ui.icon name="trash" size="18" /></button>
                                    @endif
                                </div>
                                @error('form.lines.'.$line['index'].'.service')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror

                                @if($line['service'])
                                    <p class="bk-line__facts cell-sub">
                                        <x-ui.money :minor="$line['service']['price']['amount']" :currency="$line['service']['price']['currency']" /> · {{ __('manager_booking.duration.minutes', ['minutes' => $line['service']['duration_minutes']]) }}
                                    </p>
                                    <div class="form-grid">
                                        @if($line['service']['variations'] !== [])
                                            <x-ui.field :label="__('manager_booking.composer.option')" for="bk-c-var-{{ $line['index'] }}">
                                                <select id="bk-c-var-{{ $line['index'] }}" wire:model.live="form.lines.{{ $line['index'] }}.variation">
                                                    <option value="">{{ __('manager_booking.composer.standard') }}</option>
                                                    @foreach($line['service']['variations'] as $variation)
                                                        <option value="{{ $variation['uuid'] }}">{{ $variation['name'] }} · {{ __('manager_booking.duration.minutes', ['minutes' => $variation['duration_minutes']]) }}</option>
                                                    @endforeach
                                                </select>
                                            </x-ui.field>
                                        @endif
                                        <x-ui.field :label="__('manager_booking.composer.team_member')" for="bk-c-emp-{{ $line['index'] }}">
                                            <select id="bk-c-emp-{{ $line['index'] }}" wire:model.live="form.lines.{{ $line['index'] }}.employee" @disabled($line['employees'] === [])>
                                                <option value="">{{ __('manager_booking.composer.any_available') }}</option>
                                                @foreach($line['employees'] as $person)
                                                    <option value="{{ $person['uuid'] }}">{{ $person['name'] }}</option>
                                                @endforeach
                                            </select>
                                            @if($line['employees'] === [])<p class="field-help warning-text">{{ __('manager_booking.composer.nobody_qualified') }}</p>@endif
                                        </x-ui.field>
                                        @foreach($line['resources'] as $r => $need)
                                            <x-ui.field :label="$need['type'].($need['quantity'] > 1 ? ' ×'.$need['quantity'] : '')" for="bk-c-res-{{ $line['index'] }}-{{ $r }}">
                                                <select id="bk-c-res-{{ $line['index'] }}-{{ $r }}" wire:model.live="form.lines.{{ $line['index'] }}.resources.{{ $r }}">
                                                    <option value="">{{ __('manager_booking.composer.any_available') }}</option>
                                                    @foreach($need['options'] as $room)
                                                        <option value="{{ $room['uuid'] }}">{{ $room['name'] }}</option>
                                                    @endforeach
                                                </select>
                                            </x-ui.field>
                                        @endforeach
                                    </div>
                                    @if($line['service']['addons'] !== [])
                                        <fieldset class="field">
                                            <legend>{{ __('manager_booking.composer.extras') }}</legend>
                                            <div class="chip-select">
                                                @foreach($line['service']['addons'] as $addon)
                                                    <label class="chip-toggle" wire:key="addon-{{ $line['index'] }}-{{ $addon['uuid'] }}">
                                                        <input type="checkbox" value="{{ $addon['uuid'] }}" wire:model.live="form.lines.{{ $line['index'] }}.addons">
                                                        <span>{{ $addon['name'] }} · <x-ui.money :minor="$addon['price']['amount']" :currency="$addon['price']['currency']" /></span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        </fieldset>
                                    @endif
                                @endif
                            </li>
                        @endforeach
                    </ol>

                    <div class="cluster cluster--between">
                        @if(count($lineOptions) < $maxLines)
                            <button class="button button--ghost button--sm" type="button" wire:click="addLine"><x-ui.icon name="plus" size="16" />{{ __('manager_booking.composer.add_service') }}</button>
                        @endif
                        @if($estimate)
                            <p class="bk-estimate">
                                <span class="muted">{{ __('manager_booking.composer.estimate') }}</span>
                                <strong><x-ui.money :minor="$estimate['price']['amount']" :currency="$estimate['price']['currency']" /></strong>
                                <span class="muted">· {{ $estimateDuration }}</span>
                            </p>
                        @endif
                    </div>
                @endif
            </section>

            {{-- ── Time ─────────────────────────────────────────────────── --}}
            <section class="drawer-section" aria-labelledby="bk-c-time">
                <h3 id="bk-c-time">{{ __('manager_booking.composer.when') }}</h3>
                <div class="bk-daypicker">
                    <button class="icon-button icon-button--bordered" type="button" wire:click="shiftDate(-1)" @disabled($isFirstDay || ! $ready) aria-label="{{ __('manager_booking.calendar.previous.day') }}"><x-ui.icon name="chevron-left" /></button>
                    <label class="sr-only" for="bk-c-date">{{ __('manager_booking.composer.date') }}</label>
                    <input id="bk-c-date" type="date" wire:model.live="date" min="{{ $minDate }}" required>
                    <button class="icon-button icon-button--bordered" type="button" wire:click="shiftDate(1)" @disabled(! $ready) aria-label="{{ __('manager_booking.calendar.next.day') }}"><x-ui.icon name="chevron-right" /></button>
                    <button class="button button--secondary button--sm" type="button" wire:click="findSlots" wire:loading.attr="data-loading" wire:target="findSlots" @disabled(! $ready)>
                        <x-ui.icon name="clock" size="16" />{{ __('manager_booking.composer.find_times') }}
                    </button>
                </div>

                <div wire:loading.class="is-refreshing" wire:target="findSlots,shiftDate,date">
                    @if(! $ready)
                        <p class="muted bk-hint">{{ __('manager_booking.composer.pick_service_first') }}</p>
                    @elseif($searched && $periods === [] && $error === '')
                        <x-ui.empty-state compact icon="clock" :title="__('manager_booking.composer.no_times', ['date' => $dateLabel])" />
                    @elseif($periods !== [])
                        <div class="bk-slots">
                            @foreach($periods as $period)
                                <div class="bk-slots__group" wire:key="period-{{ $period['key'] }}">
                                    <p class="bk-slots__label">{{ $period['label'] }}</p>
                                    <div class="bk-slots__grid" role="group" aria-label="{{ $period['label'] }}">
                                        @foreach($period['slots'] as $option)
                                            <button type="button" class="bk-slot" wire:key="slot-{{ $option['starts_at'] }}" wire:click="pickSlot('{{ $option['starts_at'] }}')" aria-pressed="{{ $form->slot === $option['starts_at'] ? 'true' : 'false' }}">
                                                <strong class="tabular" dir="ltr">{{ $option['time'] }}</strong>
                                                @if($option['staff'] !== '')<span>{{ $option['staff'] }}</span>@endif
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @error('form.slot')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                </div>
            </section>

            <section class="drawer-section">
                <x-ui.field :label="__('manager_booking.composer.note')" for="bk-c-note" name="form.note" :help="__('manager_booking.composer.note_help')">
                    <textarea id="bk-c-note" rows="2" maxlength="500" wire:model.blur="form.note"></textarea>
                </x-ui.field>
            </section>
        </div>

        <x-slot:footer>
            <button class="button button--ghost" type="button" wire:click="close">{{ __('ui.actions.cancel') }}</button>
            <span class="drawer__spacer"></span>
            <button class="button" type="button" wire:click="book" wire:loading.attr="data-loading" wire:target="book" @disabled($form->slot === '')>
                <x-ui.icon name="check" size="16" />{{ __('manager_booking.composer.book') }}
            </button>
        </x-slot:footer>
    </x-ui.drawer>
@endif
</div>
