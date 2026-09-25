@props(['locales' => null])
{{--
    The APPLICATION language (en / ar / ckb) — never the center's content
    languages. Codes are shown as EN / AR / KU; `ckb` is internal only.
    `$current` and `$choices` come from App\View\Composers\LanguageSwitcherComposer.
--}}
<details class="language-switcher" data-popover>
    <summary aria-label="{{ __('ui.language.choose') }}" aria-haspopup="menu" title="{{ __('ui.language.choose') }}">
        <img class="language-flag" src="{{ $current['flag'] }}" alt="" width="24" height="16">
        <strong>{{ $current['short'] }}</strong>
        <x-ui.icon name="chevron-down" class="language-switcher__chevron" />
    </summary>
    <div class="language-switcher__menu" role="menu">
        @foreach ($choices as $choice)
            <a role="menuitem" href="{{ $choice['href'] }}" hreflang="{{ $choice['code'] }}" aria-current="{{ $choice['active'] ? 'true' : 'false' }}">
                <img class="language-flag" src="{{ $choice['flag'] }}" alt="" width="24" height="16">
                <strong>{{ $choice['short'] }}</strong>
                <span lang="{{ $choice['code'] }}" dir="{{ $choice['dir'] }}">{{ $choice['native'] }}</span>
                @if($choice['active'])<x-ui.icon name="check" />@else<span></span>@endif
            </a>
        @endforeach
    </div>
</details>
