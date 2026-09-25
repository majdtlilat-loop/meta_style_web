{{--
    A section or hero backdrop: none, a soft tint, a BRAND colour, a brand
    gradient, or an image / video with an overlay. Colours are never typed
    here — they come from Appearance → Brand.
    Params: $path (Livewire path), $target (content path), $background, $domId.
--}}
<x-ui.card :title="__('manager_site.background.title')">
    <div class="stack">
        <fieldset class="sb-choice-set">
            <legend>{{ __('manager_site.background.type') }}</legend>
            <div class="sb-choice-set__options" role="radiogroup">
                @foreach($choices['backgrounds'] as $value => $text)
                    <label class="sb-choice" wire:key="{{ $domId }}-bg-{{ $value }}">
                        <input class="sr-only" type="radio" name="{{ $domId }}-bg" value="{{ $value }}" wire:model.live="{{ $path }}.type">
                        <span class="sb-choice__sample" data-bg="{{ $value }}" aria-hidden="true"></span>
                        <span>{{ $text }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        @if(($background['type'] ?? 'none') === 'color')
            <x-ui.field :label="__('manager_site.background.color')" :for="$domId.'-bg-color'" :name="$path.'.color'" :help="__('manager_site.background.brand_note')">
                <select id="{{ $domId }}-bg-color" wire:model.live="{{ $path }}.color">
                    @foreach($choices['background_colors'] as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                </select>
            </x-ui.field>
        @elseif(($background['type'] ?? 'none') === 'gradient')
            <x-ui.field :label="__('manager_site.background.gradient')" :for="$domId.'-bg-gradient'" :name="$path.'.gradient'" :help="__('manager_site.background.brand_note')">
                <select id="{{ $domId }}-bg-gradient" wire:model.live="{{ $path }}.gradient">
                    @foreach($choices['gradients'] as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                </select>
            </x-ui.field>
        @elseif(in_array($background['type'] ?? 'none', ['image', 'video'], true))
            <div class="cms-media-grid">
                @if(($background['type'] ?? '') === 'image')
                    @include('livewire.center.appearance.site.partials.media', ['target' => $target.'.image', 'kind' => 'image', 'label' => __('manager_site.background.image'), 'uuid' => $background['image'] ?? '', 'poster' => ''])
                @else
                    @include('livewire.center.appearance.site.partials.media', ['target' => $target.'.video', 'kind' => 'video', 'label' => __('manager_site.background.video'), 'uuid' => $background['video'] ?? '', 'poster' => $background['poster'] ?? ''])
                    @include('livewire.center.appearance.site.partials.media', ['target' => $target.'.poster', 'kind' => 'image', 'label' => __('manager_site.background.poster'), 'uuid' => $background['poster'] ?? '', 'poster' => ''])
                @endif
            </div>
            <x-ui.field :label="__('manager_site.background.overlay')" :for="$domId.'-bg-overlay'" :name="$path.'.overlay'">
                <select id="{{ $domId }}-bg-overlay" wire:model="{{ $path }}.overlay">
                    @foreach($choices['overlays'] as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                </select>
            </x-ui.field>
        @endif
    </div>
</x-ui.card>
