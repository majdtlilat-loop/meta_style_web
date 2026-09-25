{{--
    The service drawer. Texts in every ENABLED content language (primary
    marked, direction per language); price typed in major units and parsed as
    a string; a blank variation price or duration follows the service
    (ADR-037). Photos and resource requirements have their own sections.
--}}
<div>
    @if($open)
        <x-ui.drawer size="lg" close="close" submit="saveService" :title="$title">
            <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />

            <section class="drawer-section">
                <h3>{{ __('manager_catalog.editor.sections.details') }}</h3>
                <x-ui.lang-tabs id="service-text" :fields="$textFields" :values="$textValues" :locales="$locales" :primary="$primaryLocale" />
            </section>

            <section class="drawer-section">
                <h3>{{ __('manager_catalog.editor.sections.price') }}</h3>
                <div class="form-grid">
                    <x-ui.field :label="__('manager_catalog.fields.price')" for="service-price" name="price" required>
                        <div class="input-affix">
                            <input id="service-price" type="text" dir="ltr" inputmode="decimal" wire:model="price" autocomplete="off" maxlength="32" required>
                            <span class="input-affix__suffix" dir="ltr">{{ $currency }}</span>
                        </div>
                    </x-ui.field>
                    <x-ui.field :label="__('manager_catalog.fields.duration')" for="service-duration" name="duration" required>
                        <div class="input-affix">
                            <input id="service-duration" type="number" dir="ltr" inputmode="numeric" min="1" max="1440" wire:model="duration" required>
                            <span class="input-affix__suffix">{{ __('manager_catalog.editor.minutes') }}</span>
                        </div>
                    </x-ui.field>
                    <x-ui.field :label="__('manager_catalog.fields.category')" for="service-category" name="categoryUuid">
                        <select id="service-category" wire:model="categoryUuid">
                            <option value="">{{ __('manager_catalog.categories.uncategorised') }}</option>
                            @foreach($categories as $option)
                                <option value="{{ $option['uuid'] }}">{{ $option['name'] }}@if($option['archived']) · {{ __('ui.states.archived') }}@endif</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field :label="__('manager_catalog.fields.department')" for="service-department" name="departmentUuid" :help="__('manager_catalog.editor.department_help')">
                        <select id="service-department" wire:model="departmentUuid">
                            <option value="">{{ __('ui.states.none') }}</option>
                            @foreach($departments as $option)
                                <option value="{{ $option['uuid'] }}">{{ $option['name'] }}@if($option['archived']) · {{ __('ui.states.archived') }}@endif</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                </div>
            </section>

            <section class="drawer-section">
                <h3>{{ __('manager_catalog.editor.sections.availability') }}</h3>
                <label class="choice choice--switch">
                    <input type="checkbox" role="switch" wire:model="serviceActive">
                    <span>{{ __('manager_catalog.fields.active') }}<small class="field-help">{{ __('manager_catalog.editor.active_help') }}</small></span>
                </label>
                <label class="choice choice--switch">
                    <input type="checkbox" role="switch" wire:model="servicePublic">
                    <span>{{ __('manager_catalog.fields.public') }}<small class="field-help">{{ __('manager_catalog.editor.public_help') }}</small></span>
                </label>
                <label class="choice choice--switch">
                    <input type="checkbox" role="switch" wire:model="serviceOnline">
                    <span>{{ __('manager_catalog.fields.online') }}@unless($bookingOwned)<small class="field-help">{{ __('manager_catalog.editor.online_locked') }}</small>@endunless</span>
                </label>
                <label class="choice choice--switch">
                    <input type="checkbox" role="switch" wire:model.live="allBranches">
                    <span>{{ __('manager_catalog.fields.all_branches') }}</span>
                </label>
                @unless($allBranches)
                    <fieldset class="field catalog-editor__choices">
                        <legend>{{ __('manager_catalog.fields.branches') }}<span class="required" aria-hidden="true">*</span></legend>
                        <div class="chip-select">
                            @foreach($branches as $option)
                                <label class="chip-toggle" wire:key="branch-{{ $option['uuid'] }}">
                                    <input type="checkbox" value="{{ $option['uuid'] }}" wire:model="branchUuids">
                                    <span>{{ $option['name'] }}@if($option['archived']) · {{ __('ui.states.archived') }}@endif</span>
                                </label>
                            @endforeach
                        </div>
                        @error('branchUuids')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                    </fieldset>
                @endunless
            </section>

            <section class="drawer-section">
                <h3>{{ __('manager_catalog.editor.sections.team') }}</h3>
                @if($employees === [])
                    <p class="muted">{{ __('manager_catalog.editor.no_staff') }}</p>
                @else
                    <div class="chip-select" role="group" aria-label="{{ __('manager_catalog.editor.sections.team') }}">
                        @foreach($employees as $option)
                            <label class="chip-toggle" wire:key="employee-{{ $option['uuid'] }}">
                                <input type="checkbox" value="{{ $option['uuid'] }}" wire:model="employeeUuids">
                                <span>{{ $option['name'] }}@if($option['inactive']) · {{ __('ui.states.inactive') }}@endif</span>
                            </label>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="drawer-section">
                <div class="catalog-editor__head">
                    <h3>{{ __('manager_catalog.editor.sections.variations') }}</h3>
                    <button type="button" class="button button--secondary button--sm" wire:click="addVariation"><x-ui.icon name="plus" size="16" />{{ __('manager_catalog.editor.add_variation') }}</button>
                </div>
                @if($variations !== [])
                    <ol class="catalog-variations" role="list" x-data x-sortable="moveVariation" data-sortable-group="variations">
                        @foreach($variations as $index => $variation)
                            <li @class(['catalog-variation', 'is-inactive' => ! $variation['active']]) data-sortable-item="{{ $variation['key'] }}" wire:key="variation-{{ $variation['key'] }}">
                                <div class="catalog-variation__bar">
                                    <button type="button" class="catalog-row__handle" data-sortable-handle aria-label="{{ __('manager_catalog.editor.drag_variation', ['number' => $loop->iteration]) }}" title="{{ __('manager_catalog.ordering.drag_hint') }}"><x-ui.icon name="grip" size="16" /></button>
                                    <span class="catalog-variation__number">{{ $loop->iteration }}</span>
                                    <label class="choice choice--inline">
                                        <input type="checkbox" role="switch" wire:model.live="variations.{{ $index }}.active">
                                        <span>{{ __('manager_catalog.fields.active') }}</span>
                                    </label>
                                    <span class="catalog-variation__tools">
                                        <button type="button" class="icon-button icon-button--sm" wire:click="moveVariationBy('{{ $variation['key'] }}', -1)" @disabled($loop->first) aria-label="{{ __('manager_catalog.editor.move_variation_up', ['number' => $loop->iteration]) }}" title="{{ __('ui.actions.move_up') }}"><x-ui.icon name="arrow-up" size="16" /></button>
                                        <button type="button" class="icon-button icon-button--sm" wire:click="moveVariationBy('{{ $variation['key'] }}', 1)" @disabled($loop->last) aria-label="{{ __('manager_catalog.editor.move_variation_down', ['number' => $loop->iteration]) }}" title="{{ __('ui.actions.move_down') }}"><x-ui.icon name="arrow-down" size="16" /></button>
                                        @if($variation['uuid'] === null)
                                            <button type="button" class="icon-button icon-button--sm icon-button--danger" wire:click="removeVariation({{ $index }})" aria-label="{{ __('manager_catalog.editor.remove_variation', ['number' => $loop->iteration]) }}" title="{{ __('ui.actions.remove') }}"><x-ui.icon name="trash" size="16" /></button>
                                        @endif
                                    </span>
                                </div>
                                <div class="catalog-variation__fields">
                                    @foreach($languages as $language)
                                        <div class="field" wire:key="variation-{{ $variation['key'] }}-{{ $language['code'] }}">
                                            <label for="variation-{{ $variation['key'] }}-name-{{ $language['code'] }}">{{ __('manager_catalog.fields.variation_name') }} <span class="muted">· {{ $language['native'] }}</span>@if($language['primary'])<span class="required" aria-hidden="true">*</span>@endif</label>
                                            <input id="variation-{{ $variation['key'] }}-name-{{ $language['code'] }}" type="text" wire:model="variations.{{ $index }}.name.{{ $language['code'] }}" lang="{{ $language['code'] }}" dir="{{ $language['dir'] }}" maxlength="190" @required($language['primary'])>
                                            @error('variations.'.$index.'.name.'.$language['code'])<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                                        </div>
                                    @endforeach
                                    <div class="field">
                                        <label for="variation-{{ $variation['key'] }}-price">{{ __('manager_catalog.fields.price') }}</label>
                                        <div class="input-affix">
                                            <input id="variation-{{ $variation['key'] }}-price" type="text" dir="ltr" inputmode="decimal" maxlength="32" wire:model="variations.{{ $index }}.price" placeholder="{{ __('manager_catalog.editor.follows', ['value' => $price]) }}">
                                            <span class="input-affix__suffix" dir="ltr">{{ $currency }}</span>
                                        </div>
                                        @error('variations.'.$index.'.price')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                                    </div>
                                    <div class="field">
                                        <label for="variation-{{ $variation['key'] }}-duration">{{ __('manager_catalog.fields.duration') }}</label>
                                        <div class="input-affix">
                                            <input id="variation-{{ $variation['key'] }}-duration" type="number" dir="ltr" inputmode="numeric" min="1" max="1440" wire:model="variations.{{ $index }}.duration" placeholder="{{ __('manager_catalog.editor.follows', ['value' => $duration]) }}">
                                            <span class="input-affix__suffix">{{ __('manager_catalog.editor.minutes') }}</span>
                                        </div>
                                        @error('variations.'.$index.'.duration')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>

            <section class="drawer-section">
                <h3>{{ __('manager_catalog.editor.sections.photos') }}</h3>
                @if($editingService)
                    <livewire:center.catalog.media-gallery owner="service" :owner-uuid="$editingService" :key="'service-gallery-'.$editingService" />
                @else
                    <p class="catalog-editor__note"><x-ui.icon name="image" size="16" />{{ __('manager_catalog.editor.photos_after_save') }}</p>
                @endif
            </section>

            @if($can['resourcesView'] && ($resourceTypes !== [] || $requirements !== []))
                <section class="drawer-section">
                    <div class="catalog-editor__head">
                        <h3>{{ __('manager_catalog.editor.sections.resources') }}</h3>
                        @if($can['resourcesManage'])
                            <button type="button" class="button button--secondary button--sm" wire:click="addRequirement"><x-ui.icon name="plus" size="16" />{{ __('manager_catalog.editor.add_requirement') }}</button>
                        @endif
                    </div>
                    @if($requirements === [])
                        <p class="muted">{{ __('manager_catalog.editor.no_requirements') }}</p>
                    @else
                        <div class="repeat-list">
                            @foreach($requirements as $index => $requirement)
                                <div class="repeat-row" wire:key="requirement-{{ $index }}">
                                    <div class="repeat-row__fields repeat-row__fields--2">
                                        <select aria-label="{{ __('manager_catalog.fields.resource_type') }}" wire:model="requirements.{{ $index }}.type" @disabled(! $can['resourcesManage'])>
                                            <option value="">{{ __('manager_catalog.editor.choose_resource') }}</option>
                                            @foreach($resourceTypes as $type)
                                                <option value="{{ $type['uuid'] }}">{{ $type['name'] }}@if($type['archived']) · {{ __('ui.states.archived') }}@endif</option>
                                            @endforeach
                                        </select>
                                        <input type="number" dir="ltr" min="1" max="255" inputmode="numeric" aria-label="{{ __('manager_catalog.fields.quantity') }}" wire:model="requirements.{{ $index }}.quantity" @disabled(! $can['resourcesManage'])>
                                    </div>
                                    @if($can['resourcesManage'])
                                        <button type="button" class="icon-button icon-button--sm icon-button--danger" wire:click="removeRequirement({{ $index }})" aria-label="{{ __('manager_catalog.editor.remove_requirement') }}" title="{{ __('ui.actions.remove') }}"><x-ui.icon name="trash" size="16" /></button>
                                    @endif
                                </div>
                                @error('requirements.'.$index.'.quantity')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                            @endforeach
                        </div>
                    @endif
                    @error('requirements')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                </section>
            @endif

            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="close">{{ $editingService ? __('ui.actions.close') : __('ui.actions.cancel') }}</button>
                @if($can['save'])
                    <button class="button" type="submit" wire:loading.attr="disabled" wire:target="saveService">
                        <span wire:loading.remove wire:target="saveService"><x-ui.icon name="save" size="16" /></span>
                        <span class="spinner" wire:loading wire:target="saveService" aria-hidden="true"></span>
                        {{ $editingService ? __('ui.actions.save_changes') : __('manager_catalog.editor.create') }}
                    </button>
                @endif
            </x-slot:footer>
        </x-ui.drawer>
    @endif
</div>
