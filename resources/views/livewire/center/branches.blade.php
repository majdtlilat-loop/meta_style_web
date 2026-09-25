{{--
    Branch management.

    Split shifts are entered as several intervals on one day, because that is
    what the schema stores and what most centers in this market actually work
    (docs/13-ROADMAP.md Phase 4 §1). Date exceptions (holidays, special hours)
    are edited beside the week and saved with it.
--}}
<div class="stack branches-page">
    <x-ui.page-header :title="__('ui.manager_nav.items.branches')">
        @if($canView && $canCreate)
            <x-slot:actions>
                <button class="button" type="button" wire:click="create"><x-ui.icon name="plus" size="16" />{{ __('manager_staff.branches.add') }}</button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />

    @if(! $canView)
        <x-ui.card>
            <x-ui.empty-state icon="lock" :title="__('manager_staff.branches.no_access_title')" :description="__('manager_staff.branches.no_access_body')" />
        </x-ui.card>
    @else
        @if($archivedCount > 0 || $view === 'archived')
            <div class="segmented" role="group" aria-label="{{ __('manager_staff.branches.view_label') }}">
                <button type="button" wire:click="$set('view', 'active')" aria-pressed="{{ $view === 'active' ? 'true' : 'false' }}">{{ __('manager_staff.branches.current') }}<span class="segmented__count">{{ $activeCount }}</span></button>
                <button type="button" wire:click="$set('view', 'archived')" aria-pressed="{{ $view === 'archived' ? 'true' : 'false' }}">{{ __('ui.states.archived') }}<span class="segmented__count">{{ $archivedCount }}</span></button>
            </div>
        @endif

        @if($branches === [])
            <x-ui.card>
                <x-ui.empty-state icon="branches" :title="$view === 'archived' ? __('manager_staff.branches.no_archived') : __('manager_staff.branches.empty_title')">
                    @if($view !== 'archived' && $canCreate)<button class="button button--sm" type="button" wire:click="create"><x-ui.icon name="plus" size="16" />{{ __('manager_staff.branches.add') }}</button>@endif
                </x-ui.empty-state>
            </x-ui.card>
        @else
            <div class="branch-grid">
                @foreach($branches as $branch)
                    <article class="branch-card" wire:key="branch-{{ $branch['uuid'] }}" @if($branch['archived']) data-archived @endif>
                        <header class="branch-card__head">
                            <span class="branch-card__icon" aria-hidden="true"><x-ui.icon name="map-pin" /></span>
                            <div>
                                <h2>{{ $branch['name'] }}</h2>
                                @if($branch['address'])<span class="cell-sub">{{ $branch['address'] }}</span>@endif
                            </div>
                        </header>
                        <div class="cluster cluster--tight">
                            @if($branch['main'])<x-ui.status tone="primary" :label="__('manager_staff.branches.main')" :dot="false" />@endif
                            @if($branch['archived'])
                                <x-ui.status value="archived" :label="__('ui.states.archived')" />
                            @else
                                <x-ui.status :value="$branch['active'] ? 'active' : 'inactive'" :label="$branch['active'] ? __('ui.states.active') : __('ui.states.inactive')" />
                                @unless($branch['public'])<x-ui.status tone="neutral" :label="__('manager_staff.branches.hidden')" :dot="false" />@endunless
                            @endif
                        </div>
                        <dl class="summary-list">
                            <div><dt>{{ __('manager_staff.branches.timezone') }}</dt><dd dir="ltr">{{ $branch['timezone'] }}</dd></div>
                            @if($branch['contact_phone'])<div><dt>{{ __('phone_field.label') }}</dt><dd dir="ltr" class="tabular">{{ $branch['contact_phone'] }}</dd></div>@endif
                            @if($branch['contact_whatsapp'])<div><dt>{{ __('manager_staff.branches.whatsapp') }}</dt><dd dir="ltr" class="tabular">{{ $branch['contact_whatsapp'] }}</dd></div>@endif
                            <div><dt>{{ __('manager_staff.branches.opening_hours') }}</dt><dd>{{ trans_choice('manager_staff.branches.open_days', $branch['open_days'], ['count' => $branch['open_days']]) }}</dd></div>
                        </dl>
                        <details class="disclosure">
                            <summary>{{ __('manager_staff.branches.opening_hours') }}</summary>
                            <ul class="hours-list">
                                @foreach($branch['week'] as $day)
                                    <li><span>{{ $day['day'] }}</span><span dir="ltr" @class(['muted' => $day['slots'] === [], 'tabular'])>{{ $day['slots'] === [] ? __('manager_staff.branches.closed') : implode(', ', $day['slots']) }}</span></li>
                                @endforeach
                            </ul>
                        </details>
                        @if($branch['exceptions'] !== [])
                            <div class="branch-card__exceptions">
                                <span class="cell-sub">{{ __('manager_staff.branches.upcoming_exceptions') }}</span>
                                <ul class="chip-list">
                                    @foreach($branch['exceptions'] as $exception)
                                        <li class="chip" @if($exception['note']) title="{{ $exception['note'] }}" @endif>{{ $exception['date'] }} · <span dir="ltr">{{ $exception['closed'] ? __('manager_staff.branches.closed') : $exception['hours'] }}</span></li>
                                    @endforeach
                                    @if($branch['more_exceptions'] > 0)<li class="chip chip--muted">+{{ $branch['more_exceptions'] }}</li>@endif
                                </ul>
                            </div>
                        @endif
                        @if($branch['can_manage'])
                            <footer class="branch-card__foot">
                                @if($branch['archived'])
                                    <button class="button button--secondary button--sm" type="button" wire:click="restore('{{ $branch['uuid'] }}')" wire:loading.attr="data-loading" wire:target="restore('{{ $branch['uuid'] }}')"><x-ui.icon name="undo" size="16" />{{ __('ui.actions.restore') }}</button>
                                @else
                                    @unless($branch['main'])
                                        <button class="button button--ghost button--sm button--danger-text" type="button" wire:click="archive('{{ $branch['uuid'] }}')"
                                            wire:confirm="{{ __('manager_staff.branches.archive_confirm', ['name' => $branch['name']]) }}" data-confirm-title="{{ __('manager_staff.branches.archive_title') }}" data-confirm-tone="danger"><x-ui.icon name="archive" size="16" />{{ __('ui.actions.archive') }}</button>
                                    @endunless
                                    <button class="button button--secondary button--sm" type="button" wire:click="edit('{{ $branch['uuid'] }}')" wire:loading.attr="data-loading" wire:target="edit('{{ $branch['uuid'] }}')"><x-ui.icon name="edit" size="16" />{{ __('ui.actions.edit') }}</button>
                                @endif
                            </footer>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    @endif

    @if($canManage && $showForm)
        <x-ui.drawer :title="$editing ? __('manager_staff.branches.edit') : __('manager_staff.branches.add')" close="closeForm" submit="save" size="lg">
            <div class="stack">
                @error('form')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $message }}</p></div>@enderror

                <section class="drawer-section">
                    <h3>{{ __('manager_staff.sections.details') }}</h3>
                    <x-ui.lang-tabs id="branch-text" :locales="$locales" :primary="$primaryLocale" :values="['name' => $name, 'address' => $address]" :fields="[
                        ['name' => 'name', 'label' => __('manager_staff.fields.name'), 'max' => 190, 'required' => true],
                        ['name' => 'address', 'label' => __('manager_staff.branches.address'), 'max' => 300],
                    ]" />
                    @error('name')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                    <x-ui.field :label="__('manager_staff.branches.timezone')" for="branch-timezone" name="timezone" required>
                        <input id="branch-timezone" type="text" dir="ltr" wire:model="timezone" list="branch-timezones" autocomplete="off" required>
                        <datalist id="branch-timezones"><option value="Asia/Baghdad"><option value="Asia/Riyadh"><option value="Asia/Kuwait"><option value="Asia/Qatar"><option value="Asia/Dubai"><option value="Asia/Amman"><option value="Asia/Beirut"><option value="Asia/Tehran"><option value="Europe/Istanbul"></datalist>
                    </x-ui.field>
                </section>

                <section class="drawer-section">
                    <h3>{{ __('manager_staff.branches.contact') }}</h3>
                    <div class="form-grid">
                        <x-ui.phone number="phone" country="phoneCountry" id="branch-phone" :label="__('phone_field.label')" />
                        <x-ui.phone number="whatsapp" country="whatsappCountry" id="branch-whatsapp" :label="__('manager_staff.branches.whatsapp')" />
                        <x-ui.field :label="__('manager_staff.fields.email')" for="branch-email" name="email">
                            <input id="branch-email" type="email" dir="ltr" wire:model="email" maxlength="190">
                        </x-ui.field>
                        <x-ui.field :label="__('manager_staff.branches.map')" for="branch-map" name="mapUrl">
                            <input id="branch-map" type="url" dir="ltr" wire:model="mapUrl" placeholder="https://" maxlength="500">
                        </x-ui.field>
                    </div>
                </section>

                <section class="drawer-section">
                    <h3>{{ __('manager_staff.branches.visibility') }}</h3>
                    <div class="cms-grid-2">
                        <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model="isActive"><span>{{ __('manager_staff.branches.active_switch') }}</span></label>
                        <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model="isPublic"><span>{{ __('manager_staff.branches.public_switch') }}</span></label>
                    </div>
                    <details class="disclosure" @if($errors->hasAny(['latitude', 'longitude', 'sortOrder'])) open @endif>
                        <summary>{{ __('manager_staff.branches.advanced') }}</summary>
                        <div class="form-grid form-grid--3 branches-page__advanced">
                            <x-ui.field :label="__('manager_staff.branches.latitude')" for="branch-lat" name="latitude">
                                <input id="branch-lat" type="text" inputmode="decimal" dir="ltr" wire:model="latitude" placeholder="33.3152">
                            </x-ui.field>
                            <x-ui.field :label="__('manager_staff.branches.longitude')" for="branch-lng" name="longitude">
                                <input id="branch-lng" type="text" inputmode="decimal" dir="ltr" wire:model="longitude" placeholder="44.3661">
                            </x-ui.field>
                            <x-ui.field :label="__('manager_staff.branches.sort_order')" for="branch-sort" name="sortOrder" :help="__('manager_staff.branches.sort_help')">
                                <input id="branch-sort" type="number" min="0" max="9999" dir="ltr" wire:model="sortOrder">
                            </x-ui.field>
                        </div>
                    </details>
                </section>

                <section class="drawer-section">
                    <div class="cluster cluster--tight">
                        <h3>{{ __('manager_staff.branches.opening_hours') }}</h3>
                        <span class="info-tip" role="img" tabindex="0" title="{{ __('manager_staff.branches.hours_help') }}" aria-label="{{ __('manager_staff.branches.hours_help') }}"><x-ui.icon name="info" size="16" /></span>
                    </div>
                    @error('hours')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $message }}</p></div>@enderror
                    <div class="hours-editor">
                        @foreach($editorDays as $day)
                            <div class="hours-editor__day" wire:key="day-{{ $day['day'] }}">
                                <span class="hours-editor__label">{{ $day['label'] }}</span>
                                <div class="hours-editor__slots">
                                    @forelse($day['indexes'] as $index)
                                        <div class="hours-editor__slot" wire:key="interval-{{ $index }}">
                                            <input type="time" dir="ltr" wire:model="hours.{{ $index }}.opens_at" aria-label="{{ $day['label'] }} · {{ __('manager_staff.branches.opens') }}">
                                            <span aria-hidden="true">–</span>
                                            <input type="time" dir="ltr" wire:model="hours.{{ $index }}.closes_at" aria-label="{{ $day['label'] }} · {{ __('manager_staff.branches.closes') }}">
                                            <button class="icon-button icon-button--sm icon-button--danger" type="button" wire:click="removeInterval({{ $index }})" aria-label="{{ __('ui.actions.remove') }}" title="{{ __('ui.actions.remove') }}"><x-ui.icon name="close" /></button>
                                        </div>
                                    @empty
                                        <span class="muted">{{ __('manager_staff.branches.closed') }}</span>
                                    @endforelse
                                </div>
                                <button class="icon-button icon-button--sm" type="button" wire:click="addInterval({{ $day['day'] }})" aria-label="{{ $day['label'] }} · {{ __('manager_staff.branches.add_interval') }}" title="{{ __('manager_staff.branches.add_interval') }}"><x-ui.icon name="plus" /></button>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="drawer-section">
                    <div class="cluster cluster--between">
                        <h3>{{ __('manager_staff.branches.exceptions') }}</h3>
                        <button class="button button--secondary button--sm" type="button" wire:click="addException"><x-ui.icon name="plus" size="16" />{{ __('manager_staff.branches.add_exception') }}</button>
                    </div>
                    @error('exceptions')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $message }}</p></div>@enderror
                    @if($exceptions === [])
                        <p class="muted">{{ __('manager_staff.branches.no_exceptions') }}</p>
                    @else
                        <ul class="branch-exceptions" role="list">
                            @foreach($exceptions as $index => $exception)
                                <li class="branch-exceptions__row" wire:key="exception-{{ $index }}">
                                    <x-ui.field :label="__('manager_staff.branches.exception_date')" for="exception-date-{{ $index }}" name="exceptions.{{ $index }}.date" required>
                                        <input id="exception-date-{{ $index }}" type="date" dir="ltr" wire:model="exceptions.{{ $index }}.date" required>
                                    </x-ui.field>
                                    <label class="choice choice--switch branch-exceptions__closed"><input class="switch" type="checkbox" role="switch" wire:model.live="exceptions.{{ $index }}.is_closed"><span>{{ __('manager_staff.branches.closed_all_day') }}</span></label>
                                    @unless($exception['is_closed'])
                                        <div class="branch-exceptions__times">
                                            <x-ui.field :label="__('manager_staff.branches.opens')" for="exception-open-{{ $index }}" name="exceptions.{{ $index }}.opens_at" required>
                                                <input id="exception-open-{{ $index }}" type="time" dir="ltr" wire:model="exceptions.{{ $index }}.opens_at">
                                            </x-ui.field>
                                            <x-ui.field :label="__('manager_staff.branches.closes')" for="exception-close-{{ $index }}" name="exceptions.{{ $index }}.closes_at" required>
                                                <input id="exception-close-{{ $index }}" type="time" dir="ltr" wire:model="exceptions.{{ $index }}.closes_at">
                                            </x-ui.field>
                                        </div>
                                    @endunless
                                    <x-ui.field :label="__('manager_staff.branches.exception_note')" for="exception-note-{{ $index }}" name="exceptions.{{ $index }}.note" class="branch-exceptions__note">
                                        <input id="exception-note-{{ $index }}" type="text" wire:model="exceptions.{{ $index }}.note" maxlength="190" placeholder="{{ __('manager_staff.branches.exception_note_placeholder') }}">
                                    </x-ui.field>
                                    <button class="icon-button icon-button--sm icon-button--danger branch-exceptions__remove" type="button" wire:click="removeException({{ $index }})" aria-label="{{ __('ui.actions.remove') }}" title="{{ __('ui.actions.remove') }}"><x-ui.icon name="trash" /></button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closeForm">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="save">{{ __('manager_staff.branches.save') }}</button>
            </x-slot:footer>
        </x-ui.drawer>
    @endif
</div>
