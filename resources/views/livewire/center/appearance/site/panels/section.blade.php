{{-- One section. Params: $id, $path (`content.sections.<id>`), $target (`sections.<id>`); $section, $spec from the view. --}}
<x-ui.card :title="$choices['types'][$section['type']]['label']">
    <x-slot:actions>
        <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model.live="{{ $path }}.enabled"><span>{{ __('manager_site.fields.show_section') }}</span></label>
        @if($canManage)
            <button class="button button--ghost button--sm" type="button" wire:click="duplicateSection('{{ $id }}')"><x-ui.icon name="copy" size="16" />{{ __('manager_site.actions.duplicate') }}</button>
            <button class="button button--ghost button--sm button--danger-text" type="button" wire:click="askRemoveSection('{{ $id }}')"><x-ui.icon name="trash" size="16" />{{ __('manager_site.actions.remove_section') }}</button>
        @endif
    </x-slot:actions>
    <div class="stack">
        <div class="cms-grid-3">
            <x-ui.field :label="__('manager_site.fields.anchor')" :for="$id.'-anchor'" :name="$path.'.anchor'" :help="__('manager_site.fields.anchor_help')">
                <div class="input-affix"><span class="input-affix__prefix" dir="ltr">#</span><input id="{{ $id }}-anchor" dir="ltr" wire:model.blur="{{ $path }}.anchor" maxlength="41" autocomplete="off" spellcheck="false"></div>
            </x-ui.field>
            <x-ui.field :label="__('manager_site.fields.layout')" :for="$id.'-layout'" :name="$path.'.layout'">
                <select id="{{ $id }}-layout" wire:model.live="{{ $path }}.layout">
                    @foreach($choices['layouts'][$section['type']] as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                </select>
            </x-ui.field>
            <x-ui.field :label="__('manager_site.fields.visibility')" :for="$id.'-visibility'" :name="$path.'.visibility'">
                <select id="{{ $id }}-visibility" wire:model="{{ $path }}.visibility">
                    @foreach($choices['visibility'] as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                </select>
            </x-ui.field>
            @if(in_array($section['layout'], ['grid', 'cards', 'mosaic'], true))
                <x-ui.field :label="__('manager_site.fields.columns')" :for="$id.'-columns'" :name="$path.'.columns'">
                    <select id="{{ $id }}-columns" wire:model="{{ $path }}.columns">
                        @foreach($choices['columns'] as $columns)<option value="{{ $columns }}">{{ $columns }}</option>@endforeach
                    </select>
                </x-ui.field>
            @endif
            <x-ui.field :label="__('manager_site.fields.alignment')" :for="$id.'-alignment'" :name="$path.'.alignment'">
                <select id="{{ $id }}-alignment" wire:model="{{ $path }}.alignment">
                    @foreach($choices['alignments'] as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                </select>
            </x-ui.field>
        </div>

        <x-ui.lang-tabs :id="'section-'.$id" :locales="$locales" :primary="$primary" :values="[
            $path.'.eyebrow' => $section['eyebrow'], $path.'.title' => $section['title'],
            $path.'.subtitle' => $section['subtitle'], $path.'.body' => $section['body'],
        ]" :fields="[
            ['name' => $path.'.eyebrow', 'label' => __('manager_site.fields.eyebrow'), 'max' => $choices['limits']['eyebrow']],
            ['name' => $path.'.title', 'label' => __('manager_site.fields.title'), 'max' => $choices['limits']['title'], 'counter' => true],
            ['name' => $path.'.subtitle', 'label' => __('manager_site.fields.subtitle'), 'max' => $choices['limits']['subtitle']],
            ['name' => $path.'.body', 'label' => __('manager_site.fields.body'), 'type' => 'textarea', 'rows' => 4, 'max' => $choices['limits']['body'], 'counter' => true],
        ]" />

        <fieldset class="field">
            <legend>{{ __('manager_site.fields.section_icon') }}</legend>
            <div class="icon-picker" role="radiogroup">
                @foreach($choices['icons'] as $icon)
                    <label class="icon-picker__option" title="{{ __('manager_site.icons.'.$icon) }}">
                        <input class="sr-only" type="radio" name="{{ $id }}-icon" value="{{ $icon }}" wire:model.live="{{ $path }}.icon">
                        <x-ui.icon :name="$icon" size="18" /><span class="sr-only">{{ __('manager_site.icons.'.$icon) }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>
    </div>
</x-ui.card>

@if($spec['source'] !== null)
    @include('livewire.center.appearance.site.sources.'.$spec['source'], ['source' => $section['source'], 'sourcePath' => $path.'.source'])
@endif

@if($spec['items'] !== null)
    @include('livewire.center.appearance.site.partials.items', ['kind' => $spec['items'], 'max' => $spec['max']])
@endif

@if($spec['media'])
    <x-ui.card :title="__('manager_site.media.title')">
        <div class="stack">
            <div class="cms-media-grid">
                @include('livewire.center.appearance.site.partials.media', ['target' => $target.'.image', 'kind' => 'image', 'label' => __('manager_site.fields.image'), 'uuid' => $section['image'], 'poster' => ''])
                @include('livewire.center.appearance.site.partials.media', ['target' => $target.'.video', 'kind' => 'video', 'label' => __('manager_site.fields.video'), 'uuid' => $section['video'], 'poster' => $section['poster']])
                @include('livewire.center.appearance.site.partials.media', ['target' => $target.'.poster', 'kind' => 'image', 'label' => __('manager_site.fields.poster'), 'uuid' => $section['poster'], 'poster' => ''])
            </div>
            <div class="cms-grid-2">
                <x-ui.field :label="__('manager_site.fields.media_position')" :for="$id.'-media-position'" :name="$path.'.media_position'">
                    <select id="{{ $id }}-media-position" wire:model="{{ $path }}.media_position">
                        @foreach($choices['media_positions'] as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                    </select>
                </x-ui.field>
            </div>
            <x-ui.lang-tabs :id="'section-alt-'.$id" :locales="$locales" :primary="$primary" :values="[$path.'.image_alt' => $section['image_alt']]" :fields="[
                ['name' => $path.'.image_alt', 'label' => __('manager_site.fields.alt'), 'max' => $choices['limits']['alt']],
            ]" />
        </div>
    </x-ui.card>
@endif

@if($spec['cta'])
    <x-ui.card :title="__('manager_site.fields.section_cta')">
        <div class="cms-item-list">
            @include('livewire.center.appearance.site.partials.cta', ['path' => $path.'.cta', 'cta' => $section['cta'], 'title' => __('manager_site.fields.section_cta'), 'domId' => $id.'-cta'])
        </div>
    </x-ui.card>
@endif

@include('livewire.center.appearance.site.partials.background', ['path' => $path.'.background', 'target' => $target.'.background', 'background' => $section['background'], 'domId' => $id])
