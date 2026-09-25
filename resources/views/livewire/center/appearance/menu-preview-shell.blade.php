{{--
    The full-screen preview for the guest pages (menu, booking, cart): the REAL
    public template in an iframe, at desktop / tablet / phone width, in any of
    the center's enabled content languages. Opens on the browser event named
    by `$event`, which the component dispatches after it has saved (menu
    draft) or stashed (booking, cart) what is on screen.

    Expects: $event, $src, $title, $locales (list of code + label), $primary.
--}}
<div class="preview-shell" x-data="{ open: false, device: 'desktop', lang: @js($primary), stamp: 0 }"
     x-on:{{ $event }}.window="open = true; stamp = Date.now()" x-show="open" x-cloak
     x-on:keydown.escape.window="open = false" role="dialog" aria-modal="true" aria-labelledby="{{ $event }}-title" x-trap.noscroll="open">
    <div class="preview-shell__bar">
        <strong id="{{ $event }}-title">{{ $title }}</strong>
        <div class="segmented" role="group" aria-label="{{ __('manager_appearance.preview.device') }}">
            @foreach (['desktop' => 'monitor', 'tablet' => 'tablet', 'mobile' => 'smartphone'] as $device => $icon)
                <button type="button" x-on:click="device = '{{ $device }}'" :aria-pressed="device === '{{ $device }}' ? 'true' : 'false'"><x-ui.icon :name="$icon" size="16" /><span>{{ __('manager_appearance.preview.'.$device) }}</span></button>
            @endforeach
        </div>
        @if (count($locales) > 1)
            <div class="segmented" role="group" aria-label="{{ __('manager_appearance.preview.language') }}">
                @foreach ($locales as $option)
                    <button type="button" x-on:click="lang = '{{ $option['code'] }}'; stamp = Date.now()" :aria-pressed="lang === '{{ $option['code'] }}' ? 'true' : 'false'">{{ $option['label'] }}</button>
                @endforeach
            </div>
        @endif
        <span class="muted preview-shell__note">{{ __('manager_appearance.preview.note') }}</span>
        <button class="icon-button" type="button" x-on:click="open = false" aria-label="{{ __('manager_appearance.actions.close') }}"><x-ui.icon name="close" /></button>
    </div>
    <div class="preview-shell__stage">
        <template x-if="open">
            <iframe class="preview-shell__frame" :data-device="device" :src="@js($src) + '?lang=' + lang + '&t=' + stamp" title="{{ $title }}"></iframe>
        </template>
    </div>
</div>
